import os
import re
import hashlib
import numpy as np
from typing import List, Union

class EmbeddingEngine:
    """
    Modular Embedding Generator for RAG Pipeline.
    Supports:
    1. Deterministic dense semantic projection (N-gram hashing + Orthonormal Projection, CPU optimized)
    2. HuggingFace / Transformers embeddings (when explicitly enabled)
    """

    def __init__(self, model_name: str = "sentence-transformers/all-MiniLM-L6-v2", dim: int = 384, use_hf: bool = False):
        self.model_name = model_name
        self.dim = dim
        self.use_hf = use_hf
        self._hf_model = None
        self._hf_tokenizer = None
        self._is_hf_ready = False
        self._init_projector()

    def _init_projector(self):
        """Initializes a deterministic orthonormal projection matrix for 384 dimensions."""
        rng = np.random.RandomState(2024)
        raw_mat = rng.randn(4096, self.dim).astype(np.float32)
        q, _ = np.linalg.qr(raw_mat)
        self._proj_matrix = q.astype(np.float32)

    def _try_load_hf(self):
        if self._is_hf_ready:
            return True
        try:
            import torch
            from transformers import AutoTokenizer, AutoModel
            self._hf_tokenizer = AutoTokenizer.from_pretrained(self.model_name)
            self._hf_model = AutoModel.from_pretrained(self.model_name)
            self._hf_model.eval()
            self._is_hf_ready = True
            return True
        except Exception:
            self._is_hf_ready = False
            return False

    @staticmethod
    def _stable_hash(s: str) -> int:
        """Deterministic integer hash across all Python runs."""
        return int(hashlib.md5(s.encode("utf-8")).hexdigest()[:8], 16)

    def embed_texts(self, texts: List[str]) -> np.ndarray:
        if not texts:
            return np.empty((0, self.dim), dtype=np.float32)

        # Attempt Hugging Face model if explicitly enabled
        if self.use_hf and (self._is_hf_ready or self._try_load_hf()):
            try:
                import torch
                inputs = self._hf_tokenizer(
                    texts,
                    padding=True,
                    truncation=True,
                    max_length=512,
                    return_tensors="pt"
                )
                with torch.no_grad():
                    outputs = self._hf_model(**inputs)
                    attention_mask = inputs["attention_mask"].unsqueeze(-1)
                    embeddings = torch.sum(outputs[0] * attention_mask, 1) / torch.clamp(attention_mask.sum(1), min=1e-9)
                    normed = torch.nn.functional.normalize(embeddings, p=2, dim=1)
                    return normed.cpu().numpy().astype(np.float32)
            except Exception:
                pass

        return self._embed_dense_projection(texts)

    def embed_query(self, query: str) -> np.ndarray:
        res = self.embed_texts([query])
        return res[0] if len(res) > 0 else np.zeros(self.dim, dtype=np.float32)

    def _embed_dense_projection(self, texts: List[str]) -> np.ndarray:
        """
        Deterministic, high-fidelity semantic dense embedding using stable hashing:
        Combines unigrams, bigrams, and char-trigrams with sublinear term-frequency weighting,
        projected onto an orthonormal basis.
        """
        stop_words = {
            "a", "an", "the", "in", "on", "at", "to", "for", "of", "with", "is", "are",
            "was", "were", "be", "this", "that", "it", "and", "or", "as", "by"
        }

        vectors = []
        for text in texts:
            # Replace underscores and hyphens with spaces for optimal word extraction
            cleaned = text.lower().replace("_", " ").replace("-", " ").strip()
            tokens = [t for t in re.findall(r'[a-zA-Z0-9]{2,}', cleaned)]
            
            feat_vec = np.zeros(4096, dtype=np.float32)
            
            # 1. Unigrams with stopword filtering & sublinear TF
            counts: dict[int, int] = {}
            for t in tokens:
                weight = 0.5 if t in stop_words else 2.5
                h = self._stable_hash(t) % 4096
                counts[h] = counts.get(h, 0) + 1
                # Sublinear term frequency: 1 + log(tf)
                feat_vec[h] += weight * (1.0 + np.log(counts[h]))

            # 2. Word Bigrams
            for i in range(len(tokens) - 1):
                bg = f"{tokens[i]}_{tokens[i+1]}"
                h_bg = self._stable_hash(bg) % 4096
                feat_vec[h_bg] += 2.0

            # 3. Char Trigrams (handles word stems and morphology)
            if len(cleaned) >= 3:
                for i in range(len(cleaned) - 2):
                    tg = cleaned[i:i+3]
                    if not tg.isspace():
                        h_tg = self._stable_hash(tg) % 4096
                        feat_vec[h_tg] += 0.3

            # L2 normalize feature vector
            norm = np.linalg.norm(feat_vec)
            if norm > 0:
                feat_vec /= norm

            # Dense projection into 384-dimensional space
            dense = np.dot(feat_vec, self._proj_matrix)
            d_norm = np.linalg.norm(dense)
            if d_norm > 0:
                dense /= d_norm
            vectors.append(dense)

        return np.array(vectors, dtype=np.float32)
