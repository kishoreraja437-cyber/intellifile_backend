import os
import sys
from typing import List, Dict, Any, Optional

from chunker import DocumentChunker
from embeddings import EmbeddingEngine
from vector_store import VectorStore
from slm_engine import SLMEngine

class RAGPipeline:
    """
    End-to-End Retrieval-Augmented Generation (RAG) Pipeline:
    - Text extraction & normalization
    - Semantic chunking with page number preservation
    - Vector embeddings generation
    - Persistent SQLite vector storage with user isolation
    - Embedding deduplication
    - Semantic similarity retrieval
    - Grounded generation via configurable SLM
    """

    def __init__(
        self,
        vector_store: Optional[VectorStore] = None,
        chunker: Optional[DocumentChunker] = None,
        embedding_engine: Optional[EmbeddingEngine] = None,
        slm_engine: Optional[SLMEngine] = None
    ):
        self.vector_store = vector_store or VectorStore()
        self.chunker = chunker or DocumentChunker(chunk_size=450, overlap=60)
        self.embedding_engine = embedding_engine or EmbeddingEngine()
        self.slm_engine = slm_engine or SLMEngine()

    def index_document(
        self,
        user_id: int,
        doc_id: int,
        title: str,
        doc_format: str,
        content_text: str,
        total_pages: int = 1
    ) -> Dict[str, Any]:
        """
        Indexes a document into the vector database.
        Checks for existing hash first to avoid redundant embedding generation.
        """
        if not content_text or not content_text.strip():
            return {
                "success": False,
                "doc_id": doc_id,
                "message": f"Document '{title}' has no text content to index.",
                "chunk_count": 0
            }

        content_hash = self.vector_store.compute_content_hash(content_text)

        # Deduplication check
        if self.vector_store.is_document_indexed(doc_id, content_hash):
            return {
                "success": True,
                "doc_id": doc_id,
                "title": title,
                "already_indexed": True,
                "message": f"Document '{title}' is already indexed (duplicate embeddings prevented)."
            }

        # Step 1: Semantic Chunking
        chunks = self.chunker.chunk_document(
            text=content_text,
            doc_id=doc_id,
            title=title,
            total_pages=total_pages,
            doc_format=doc_format
        )

        if not chunks:
            return {
                "success": False,
                "doc_id": doc_id,
                "message": "Chunking yielded no readable text segments.",
                "chunk_count": 0
            }

        # Step 2: Embedding Generation (incorporating title for semantic grounding)
        embedded_texts = [f"{title}. {c['chunk_text']}" for c in chunks]
        embeddings = self.embedding_engine.embed_texts(embedded_texts)

        # Step 3: Vector Storage with user isolation
        saved_count = self.vector_store.save_document_chunks(
            user_id=user_id,
            doc_id=doc_id,
            doc_title=title,
            doc_format=doc_format,
            content_hash=content_hash,
            chunks=chunks,
            embeddings=embeddings
        )

        return {
            "success": True,
            "doc_id": doc_id,
            "title": title,
            "already_indexed": False,
            "chunk_count": saved_count,
            "message": f"Successfully indexed {saved_count} chunks for '{title}'."
        }

    def chat(
        self,
        user_id: int,
        query: str,
        conversation_history: Optional[List[Dict[str, str]]] = None,
        filter_doc_ids: Optional[List[int]] = None,
        top_k: int = 4
    ) -> Dict[str, Any]:
        """
        Processes a user question through the complete RAG + SLM pipeline:
        1. Embeds query
        2. Retrieves top-k chunks strictly belonging to user_id
        3. Invokes SLM with grounded context
        4. Returns answer and source references
        """
        clean_query = query.strip()
        if not clean_query:
            return {
                "success": False,
                "message": "Query cannot be empty."
            }

        # 1. Embed query
        query_vec = self.embedding_engine.embed_query(clean_query)

        # 2. Retrieve top-k chunks (Strict User Isolation with Hybrid Scoring)
        retrieved_chunks = self.vector_store.retrieve_top_k(
            user_id=user_id,
            query_embedding=query_vec,
            top_k=top_k,
            min_similarity=0.03,
            filter_doc_ids=filter_doc_ids,
            query_text=clean_query
        )

        # 3. Grounded Answer Generation via SLM
        slm_result = self.slm_engine.generate_rag_answer(
            query=clean_query,
            context_chunks=retrieved_chunks,
            conversation_history=conversation_history
        )

        return {
            "success": True,
            "query": clean_query,
            "user_id": user_id,
            "answer": slm_result["answer"],
            "sources": slm_result["sources"],
            "provider": slm_result["provider"],
            "chunks_retrieved": len(retrieved_chunks)
        }

    def delete_document(self, user_id: int, doc_id: int) -> bool:
        return self.vector_store.delete_document(user_id, doc_id)

    def sync_user_documents(self, user_id: int, documents: List[Dict[str, Any]]) -> Dict[str, Any]:
        """
        Syncs a list of documents for a user, embedding only new/changed ones.
        """
        indexed_count = 0
        skipped_count = 0
        for doc in documents:
            doc_id = int(doc.get("id", 0))
            title = doc.get("title", f"Doc {doc_id}")
            doc_format = doc.get("format", "PDF")
            content = doc.get("content_text", "")
            pages = int(doc.get("total_pages", 1))

            res = self.index_document(
                user_id=user_id,
                doc_id=doc_id,
                title=title,
                doc_format=doc_format,
                content_text=content,
                total_pages=pages
            )
            if res.get("already_indexed"):
                skipped_count += 1
            elif res.get("success"):
                indexed_count += 1

        return {
            "success": True,
            "user_id": user_id,
            "newly_indexed": indexed_count,
            "already_indexed_skipped": skipped_count,
            "total_documents": len(documents)
        }
