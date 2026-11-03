import os
import re
import sqlite3
import hashlib
import numpy as np
from typing import List, Dict, Any, Optional

class VectorStore:
    """
    Persistent SQLite Vector Database with strict user-specific document isolation,
    embedding deduplication, and fast vectorized cosine similarity retrieval.
    """

    def __init__(self, db_path: Optional[str] = None):
        if db_path is None:
            base_dir = os.path.dirname(os.path.abspath(__file__))
            storage_dir = os.path.join(base_dir, "storage")
            os.makedirs(storage_dir, exist_ok=True)
            db_path = os.path.join(storage_dir, "vector_store.db")
        else:
            dir_name = os.path.dirname(os.path.abspath(db_path))
            if dir_name:
                os.makedirs(dir_name, exist_ok=True)
        
        self.db_path = db_path
        self._init_db()

    def _get_connection(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self.db_path, timeout=30.0)
        conn.execute("PRAGMA journal_mode = WAL;")
        conn.execute("PRAGMA synchronous = NORMAL;")
        return conn

    def _init_db(self):
        with self._get_connection() as conn:
            cursor = conn.cursor()
            # 1. Document chunks table
            cursor.execute("""
                CREATE TABLE IF NOT EXISTS document_chunks (
                    chunk_id TEXT PRIMARY KEY,
                    user_id INTEGER NOT NULL,
                    doc_id INTEGER NOT NULL,
                    doc_title TEXT NOT NULL,
                    doc_format TEXT NOT NULL,
                    page_num INTEGER NOT NULL DEFAULT 1,
                    chunk_index INTEGER NOT NULL,
                    chunk_text TEXT NOT NULL,
                    embedding BLOB NOT NULL,
                    content_hash TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
            """)
            cursor.execute("CREATE INDEX IF NOT EXISTS idx_chunks_user ON document_chunks(user_id);")
            cursor.execute("CREATE INDEX IF NOT EXISTS idx_chunks_doc ON document_chunks(doc_id);")
            cursor.execute("CREATE INDEX IF NOT EXISTS idx_chunks_user_doc ON document_chunks(user_id, doc_id);")

            # 2. Document index registry (for instant deduplication)
            cursor.execute("""
                CREATE TABLE IF NOT EXISTS indexed_documents (
                    doc_id INTEGER PRIMARY KEY,
                    user_id INTEGER NOT NULL,
                    doc_title TEXT NOT NULL,
                    content_hash TEXT NOT NULL,
                    chunk_count INTEGER NOT NULL,
                    indexed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
            """)
            cursor.execute("CREATE INDEX IF NOT EXISTS idx_indexed_user ON indexed_documents(user_id);")
            conn.commit()

    @staticmethod
    def compute_content_hash(text: str) -> str:
        """Computes SHA-256 hash for document content to track changes."""
        return hashlib.sha256(text.encode("utf-8")).hexdigest()

    def is_document_indexed(self, doc_id: int, content_hash: str) -> bool:
        """
        Deduplication check:
        Returns True if the document with this exact content hash has already
        been indexed and its chunks are stored, avoiding redundant embedding generation.
        """
        with self._get_connection() as conn:
            cursor = conn.cursor()
            cursor.execute(
                "SELECT chunk_count FROM indexed_documents WHERE doc_id = ? AND content_hash = ?",
                (doc_id, content_hash)
            )
            row = cursor.fetchone()
            if not row or row[0] <= 0:
                return False
            
            # Verify actual chunks exist in chunk table
            cursor.execute("SELECT COUNT(*) FROM document_chunks WHERE doc_id = ?", (doc_id,))
            count = cursor.fetchone()[0]
            return count == row[0]

    def save_document_chunks(
        self,
        user_id: int,
        doc_id: int,
        doc_title: str,
        doc_format: str,
        content_hash: str,
        chunks: List[Dict[str, Any]],
        embeddings: np.ndarray
    ) -> int:
        """
        Stores chunks and their embedding vectors in the vector store.
        Replaces any outdated chunks for the given doc_id.
        """
        if len(chunks) != len(embeddings):
            raise ValueError(f"Chunk count ({len(chunks)}) does not match embeddings count ({len(embeddings)})")

        with self._get_connection() as conn:
            cursor = conn.cursor()
            # Clean up prior chunks if updating existing document
            cursor.execute("DELETE FROM document_chunks WHERE doc_id = ?", (doc_id,))
            cursor.execute("DELETE FROM indexed_documents WHERE doc_id = ?", (doc_id,))

            rows_to_insert = []
            for i, chunk in enumerate(chunks):
                chunk_id = f"doc_{doc_id}_chunk_{i}"
                vec_blob = embeddings[i].astype(np.float32).tobytes()
                rows_to_insert.append((
                    chunk_id,
                    user_id,
                    doc_id,
                    doc_title,
                    doc_format,
                    chunk.get("page_num", 1),
                    chunk.get("chunk_index", i),
                    chunk.get("chunk_text", ""),
                    vec_blob,
                    content_hash
                ))

            cursor.executemany("""
                INSERT INTO document_chunks (
                    chunk_id, user_id, doc_id, doc_title, doc_format,
                    page_num, chunk_index, chunk_text, embedding, content_hash
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """, rows_to_insert)

            # Record in registry
            cursor.execute("""
                INSERT INTO indexed_documents (doc_id, user_id, doc_title, content_hash, chunk_count)
                VALUES (?, ?, ?, ?, ?)
            """, (doc_id, user_id, doc_title, content_hash, len(chunks)))

            conn.commit()
            return len(rows_to_insert)

    def retrieve_top_k(
        self,
        user_id: int,
        query_embedding: np.ndarray,
        top_k: int = 4,
        min_similarity: float = 0.03,
        filter_doc_ids: Optional[List[int]] = None,
        query_text: Optional[str] = None
    ) -> List[Dict[str, Any]]:
        """
        Retrieves the most semantically relevant chunks for a user.
        CRITICAL: Strictly enforces user_id isolation (never queries other users' chunks).
        Applies Hybrid scoring (dense cosine similarity + sparse lexical keyword boost).
        """
        with self._get_connection() as conn:
            cursor = conn.cursor()
            
            if filter_doc_ids:
                placeholders = ",".join("?" for _ in filter_doc_ids)
                query = f"""
                    SELECT chunk_id, doc_id, doc_title, doc_format, page_num, chunk_index, chunk_text, embedding
                    FROM document_chunks
                    WHERE user_id = ? AND doc_id IN ({placeholders})
                """
                cursor.execute(query, [user_id] + filter_doc_ids)
            else:
                query = """
                    SELECT chunk_id, doc_id, doc_title, doc_format, page_num, chunk_index, chunk_text, embedding
                    FROM document_chunks
                    WHERE user_id = ?
                """
                cursor.execute(query, (user_id,))

            rows = cursor.fetchall()
            if not rows:
                return []

            # Prepare vectors and metadata
            q_vec = query_embedding.astype(np.float32).flatten()
            q_norm = np.linalg.norm(q_vec)
            if q_norm < 1e-8:
                return []

            chunk_metas = []
            vectors = []
            for row in rows:
                chunk_id, doc_id, doc_title, doc_format, page_num, chunk_idx, chunk_text, blob = row
                vec = np.frombuffer(blob, dtype=np.float32)
                vectors.append(vec)
                chunk_metas.append({
                    "chunk_id": chunk_id,
                    "doc_id": doc_id,
                    "title": doc_title,
                    "format": doc_format,
                    "page": page_num,
                    "chunk_index": chunk_idx,
                    "text": chunk_text
                })

            mat = np.vstack(vectors)
            # Vectorized Cosine Similarity
            dot_products = np.dot(mat, q_vec)
            mat_norms = np.linalg.norm(mat, axis=1)
            denoms = mat_norms * q_norm
            denoms[denoms < 1e-8] = 1e-8
            similarities = dot_products / denoms

            # Hybrid Lexical BM25 Boost (Keyword alignment)
            if query_text:
                q_clean = query_text.lower().replace("_", " ").replace("-", " ")
                q_tokens = set(re.findall(r'[a-zA-Z0-9]{2,}', q_clean))
                stop_words = {"what", "when", "where", "which", "about", "show", "tell", "does", "have", "this", "that", "file", "document", "who", "how", "with", "are", "the", "find", "search", "give", "can", "for"}
                key_q_tokens = [w for w in q_tokens if w not in stop_words]

                for idx, meta in enumerate(chunk_metas):
                    title_tokens = set(re.findall(r'[a-zA-Z0-9]{2,}', meta["title"].lower().replace("_", " ")))
                    text_tokens = set(re.findall(r'[a-zA-Z0-9]{2,}', meta["text"].lower().replace("_", " ")))
                    title_overlap = sum(1 for t in key_q_tokens if t in title_tokens)
                    content_overlap = sum(1 for t in key_q_tokens if t in text_tokens)
                    similarities[idx] += (title_overlap * 0.15) + (content_overlap * 0.08)

            # Filter and rank
            ranked_indices = np.argsort(similarities)[::-1]
            results: List[Dict[str, Any]] = []

            for idx in ranked_indices:
                score = float(similarities[idx])
                if score < min_similarity:
                    break
                meta = chunk_metas[idx].copy()
                meta["similarity"] = round(score, 4)
                results.append(meta)
                if len(results) >= top_k:
                    break

            return results

    def delete_document(self, user_id: int, doc_id: int) -> bool:
        """Deletes a document from the vector store while maintaining user isolation."""
        with self._get_connection() as conn:
            cursor = conn.cursor()
            cursor.execute("DELETE FROM document_chunks WHERE user_id = ? AND doc_id = ?", (user_id, doc_id))
            cursor.execute("DELETE FROM indexed_documents WHERE user_id = ? AND doc_id = ?", (user_id, doc_id))
            conn.commit()
            return cursor.rowcount > 0

    def get_user_stats(self, user_id: int) -> Dict[str, Any]:
        """Returns statistics of indexed documents and chunks for a given user."""
        with self._get_connection() as conn:
            cursor = conn.cursor()
            cursor.execute("SELECT COUNT(*), SUM(chunk_count) FROM indexed_documents WHERE user_id = ?", (user_id,))
            doc_count, chunk_sum = cursor.fetchone()
            return {
                "user_id": user_id,
                "indexed_documents": doc_count or 0,
                "total_chunks": chunk_sum or 0
            }
