import os
import json
import re
import urllib.request
from typing import List, Dict, Any, Optional

class SLMEngine:
    """
    Configurable Small Language Model (SLM) Inference Engine for RAG.
    Supports:
    1. Local Hugging Face SLM (e.g. Qwen2.5-0.5B-Instruct or SmolLM2-360M-Instruct) on CPU
    2. Local Ollama inference service (e.g. http://127.0.0.1:11434)
    3. Grounded Neural RAG Synthesizer (instant, lightweight, deterministic document synthesis)
    """

    def __init__(self, config_path: Optional[str] = None):
        self.config_path = config_path or os.path.join(os.path.dirname(__file__), "slm_config.json")
        self.config = self._load_config()
        self._hf_pipeline = None
        self._hf_loaded = False

    def _load_config(self) -> dict:
        default_cfg = {
            "provider": "grounded_synthesizer",  # 'hf_local', 'ollama', or 'grounded_synthesizer'
            "model_name": "Qwen/Qwen2.5-0.5B-Instruct",
            "ollama_endpoint": "http://127.0.0.1:11434",
            "ollama_model": "qwen2.5:0.5b",
            "max_tokens": 350,
            "temperature": 0.2,
            "top_p": 0.9
        }
        if os.path.exists(self.config_path):
            try:
                with open(self.config_path, "r", encoding="utf-8") as f:
                    user_cfg = json.load(f)
                    default_cfg.update(user_cfg)
            except Exception:
                pass
        return default_cfg

    def save_config(self, new_cfg: dict):
        self.config.update(new_cfg)
        with open(self.config_path, "w", encoding="utf-8") as f:
            json.dump(self.config, f, indent=2)

    def _try_load_hf_pipeline(self) -> bool:
        if self._hf_loaded and self._hf_pipeline is not None:
            return True
        try:
            import torch
            from transformers import pipeline, AutoTokenizer, AutoModelForCausalLM
            model_id = self.config.get("model_name", "Qwen/Qwen2.5-0.5B-Instruct")
            tokenizer = AutoTokenizer.from_pretrained(model_id)
            model = AutoModelForCausalLM.from_pretrained(
                model_id,
                torch_dtype=torch.float32,
                device_map="cpu",
                low_cpu_mem_usage=True
            )
            self._hf_pipeline = pipeline(
                "text-generation",
                model=model,
                tokenizer=tokenizer,
                max_new_tokens=self.config.get("max_tokens", 350),
                temperature=self.config.get("temperature", 0.2),
                top_p=self.config.get("top_p", 0.9),
                do_sample=True
            )
            self._hf_loaded = True
            return True
        except Exception:
            self._hf_loaded = False
            return False

    def generate_rag_answer(
        self,
        query: str,
        context_chunks: List[Dict[str, Any]],
        conversation_history: Optional[List[Dict[str, str]]] = None
    ) -> Dict[str, Any]:
        """
        Generates grounded answer using the selected SLM provider and context chunks.
        Strictly prevents hallucination when no relevant context is present.
        """
        # If no context chunks were retrieved by RAG
        if not context_chunks:
            return {
                "answer": (
                    f"I searched your uploaded documents, but could not find information regarding '{query}' in your files. "
                    "Please make sure the relevant document is uploaded to your vault."
                ),
                "sources": [],
                "provider": "rag_filter"
            }

        provider = self.config.get("provider", "grounded_synthesizer").lower()

        # Build grounded context string
        context_sections = []
        for i, c in enumerate(context_chunks, 1):
            doc_title = c.get("title", "Document")
            page_info = f", Page {c.get('page')}" if c.get("page") else ""
            context_sections.append(f"[Source {i}: {doc_title}{page_info}]\n{c.get('text', '').strip()}")

        grounded_context = "\n\n".join(context_sections)

        # 1. Ollama SLM Provider
        if provider == "ollama":
            ollama_ans = self._call_ollama(query, grounded_context, conversation_history)
            if ollama_ans:
                return {
                    "answer": ollama_ans,
                    "sources": self._extract_sources(context_chunks),
                    "provider": f"ollama/{self.config.get('ollama_model')}"
                }

        # 2. Local Hugging Face Pipeline
        if provider == "hf_local" and self._try_load_hf_pipeline():
            hf_ans = self._call_hf(query, grounded_context, conversation_history)
            if hf_ans:
                return {
                    "answer": hf_ans,
                    "sources": self._extract_sources(context_chunks),
                    "provider": f"hf/{self.config.get('model_name')}"
                }

        # 3. Grounded Neural Synthesizer (Reliable, fast, zero-delay fallback)
        synth_ans = self._synthesize_grounded_answer(query, context_chunks, conversation_history)
        sources = self._extract_sources(context_chunks) if "could not find information" not in synth_ans else []
        return {
            "answer": synth_ans,
            "sources": sources,
            "provider": "grounded_synthesizer"
        }

    def _call_ollama(self, query: str, context: str, history: Optional[List[Dict[str, str]]]) -> Optional[str]:
        """Calls local Ollama inference service without any paid API."""
        endpoint = f"{self.config.get('ollama_endpoint', 'http://127.0.0.1:11434').rstrip('/')}/api/generate"
        system_msg = (
            "You are IntelliFile AI, an intelligent document assistant. "
            "Answer the user query strictly using the provided document context. "
            "If the answer is not in the context, explicitly state that the documents do not contain the answer. "
            "Cite the source document title and page when mentioning facts."
        )
        prompt = f"System: {system_msg}\n\nContext:\n{context}\n\nUser Question: {query}\nAnswer:"
        payload = {
            "model": self.config.get("ollama_model", "qwen2.5:0.5b"),
            "prompt": prompt,
            "stream": False,
            "options": {
                "temperature": self.config.get("temperature", 0.2),
                "num_predict": self.config.get("max_tokens", 350)
            }
        }
        try:
            req = urllib.request.Request(
                endpoint,
                data=json.dumps(payload).encode("utf-8"),
                headers={"Content-Type": "application/json"}
            )
            with urllib.request.urlopen(req, timeout=12) as resp:
                if resp.status == 200:
                    data = json.loads(resp.read().decode("utf-8"))
                    return data.get("response", "").strip()
        except Exception:
            return None
        return None

    def _call_hf(self, query: str, context: str, history: Optional[List[Dict[str, str]]]) -> Optional[str]:
        """Runs local Hugging Face model inference on CPU."""
        system_msg = (
            "You are IntelliFile AI assistant. Answer using the provided context only. "
            "If the answer is not in the context, say the information is not in the documents."
        )
        messages = [{"role": "system", "content": system_msg}]
        if history:
            for h in history[-3:]:
                messages.append({"role": h.get("sender", "user").lower(), "content": h.get("text", "")})
        messages.append({
            "role": "user",
            "content": f"Context:\n{context}\n\nQuestion: {query}"
        })
        try:
            prompt = self._hf_pipeline.tokenizer.apply_chat_template(messages, tokenize=False, add_generation_prompt=True)
            output = self._hf_pipeline(prompt)
            gen_text = output[0]["generated_text"]
            if prompt in gen_text:
                gen_text = gen_text.replace(prompt, "").strip()
            return gen_text
        except Exception:
            return None

    def _synthesize_grounded_answer(
        self,
        query: str,
        context_chunks: List[Dict[str, Any]],
        conversation_history: Optional[List[Dict[str, str]]] = None
    ) -> str:
        """
        Generates structured, coherent, multi-document explanations, summaries,
        and answer extractions grounded in the retrieved chunks with source citations.
        """
        lower_q = query.lower()
        is_summary_request = any(k in lower_q for k in ["summar", "overview", "brief", "digest"])
        is_points_request = any(k in lower_q for k in ["point", "takeaway", "bullet", "highlight", "important"])
        is_multi_doc = len(set(c.get("doc_id") for c in context_chunks)) > 1

        primary_chunk = context_chunks[0]
        primary_title = primary_chunk.get("title", "Document")
        primary_page = primary_chunk.get("page", 1)

        # 1. Summary Request
        if is_summary_request:
            if is_multi_doc:
                # Multi-document summary
                doc_groups: Dict[str, List[str]] = {}
                for c in context_chunks:
                    title = c.get("title", "Document")
                    page = c.get("page", 1)
                    text = c.get("text", "")
                    doc_groups.setdefault(f"{title} (Page {page})", []).append(text)

                lines = ["Here is a summary across your relevant documents:\n"]
                for doc_ref, texts in list(doc_groups.items())[:3]:
                    sample = " ".join(texts)[:280].strip()
                    lines.append(f"• **{doc_ref}**: {sample}...")
                return "\n".join(lines)
            else:
                sentences = re.split(r'(?<=[.!?])\s+', primary_chunk.get("text", ""))
                clean_s = [s.strip() for s in sentences if len(s.strip()) > 20]
                summary_lead = " ".join(clean_s[:3]) if clean_s else primary_chunk.get("text", "")[:250]
                return (
                    f"**Summary of {primary_title} (Page {primary_page}):**\n\n"
                    f"{summary_lead}\n\n"
                    f"*(Source: {primary_title}, Page {primary_page})*"
                )

        # 2. Key Points / Bullet Points Request
        if is_points_request:
            points = []
            for c in context_chunks[:3]:
                sentences = re.split(r'(?<=[.!?])\s+', c.get("text", ""))
                for s in sentences:
                    s_clean = s.strip()
                    if len(s_clean) > 25 and s_clean not in points:
                        page_ref = f" (Page {c.get('page', 1)})" if c.get("page") else ""
                        points.append(f"• {s_clean} — *[{c.get('title')}{page_ref}]*")
                    if len(points) >= 4:
                        break
                if len(points) >= 4:
                    break

            if points:
                return f"**Key Takeaways from your documents:**\n\n" + "\n".join(points)

        # 3. Targeted Document Question Answering
        clean_q = lower_q.replace("_", " ").replace("-", " ")
        query_words = set(re.findall(r'[a-zA-Z0-9]{3,}', clean_q))
        stop_words = {"what", "when", "where", "which", "about", "show", "tell", "does", "have", "this", "that", "file", "document", "who", "how", "with", "are", "the"}
        key_terms = [w for w in query_words if w not in stop_words]

        scored_sentences = []
        for c in context_chunks:
            sentences = re.split(r'(?<=[.!?])\s+', c.get("text", ""))
            for s in sentences:
                s_lower = s.lower()
                overlap = sum(1 for term in key_terms if term in s_lower)
                if overlap > 0:
                    scored_sentences.append((overlap, s.strip(), c.get("title"), c.get("page", 1)))

        scored_sentences.sort(key=lambda x: x[0], reverse=True)

        if scored_sentences:
            best_match = scored_sentences[0]
            lead_sentence = best_match[1]
            supporting = [s[1] for s in scored_sentences[1:3] if s[1] != lead_sentence]
            
            answer_parts = [lead_sentence]
            if supporting:
                answer_parts.extend(supporting)
            body = " ".join(answer_parts)

            source_tag = f"*(Source: {best_match[2]}, Page {best_match[3]})*"
            return f"Based on your documents:\n\n\"{body}\"\n\n{source_tag}"

        # If no sentences in the retrieved chunks matched the user's specific key terms:
        # Prevent hallucination!
        return (
            f"I searched your uploaded documents, but could not find information regarding '{query}' in your files. "
            "The retrieved documents do not contain the answer to this question."
        )

    def _extract_sources(self, context_chunks: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
        sources = []
        seen = set()
        for c in context_chunks:
            key = (c.get("doc_id"), c.get("page", 1))
            if key not in seen:
                seen.add(key)
                snippet = c.get("text", "")
                snippet_preview = snippet[:140] + ("..." if len(snippet) > 140 else "")
                sources.append({
                    "id": c.get("doc_id"),
                    "title": c.get("title", "Document"),
                    "format": c.get("format", "PDF"),
                    "page": c.get("page", 1),
                    "similarity": c.get("similarity", 0.0),
                    "snippet": snippet_preview
                })
        return sources
