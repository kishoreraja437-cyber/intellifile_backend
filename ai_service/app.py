import os
import sys
import json
import re
from http.server import HTTPServer, BaseHTTPRequestHandler
from socketserver import ThreadingMixIn

# Add directory to sys.path
sys.path.insert(0, os.path.dirname(__file__))

# Import standalone pure Python NLP engine
from standalone_nlp import (
    get_categorizer,
    score_priority,
    answer_query,
    split_into_sentences
)

# Import RAG and SLM Pipeline
from rag_pipeline import RAGPipeline
from hybrid_pipeline import HybridAIAssistant
rag_pipeline = RAGPipeline()
hybrid_assistant = HybridAIAssistant(rag_pipeline=rag_pipeline)

# Optional scikit-learn pipeline support
SKLEARN_AVAILABLE = False
try:
    import joblib
    from train_categorizer import train_and_save_categorizer
    MODEL_PATH = os.path.join(os.path.dirname(__file__), "models", "categorizer_pipeline.pkl")
    SKLEARN_AVAILABLE = True
except Exception:
    MODEL_PATH = None

def run_prediction(title: str, content: str) -> dict:
    combined_text = f"{title} {content}".strip()
    if not combined_text:
        return {
            "success": True,
            "title": title,
            "category": "General",
            "category_confidence": 0.5,
            "priority": "NORMAL",
            "priority_score": 45,
            "detected_cues": []
        }

    # 1. Predict Category
    category = "General"
    confidence = 0.8
    if SKLEARN_AVAILABLE and os.path.exists(MODEL_PATH):
        try:
            model = joblib.load(MODEL_PATH)
            category = model.predict([combined_text])[0]
            probs = model.predict_proba([combined_text])[0]
            confidence = float(max(probs))
        except Exception:
            categorizer = get_categorizer()
            category, confidence = categorizer.predict(combined_text)
    else:
        categorizer = get_categorizer()
        category, confidence = categorizer.predict(combined_text)

    # 2. Score Priority
    priority_res = score_priority(combined_text, category)

    return {
        "success": True,
        "title": title,
        "category": category,
        "category_confidence": round(confidence, 3),
        "priority": priority_res["priority"],
        "priority_score": priority_res["score"],
        "detected_cues": priority_res["detected_cues"]
    }

def run_qa(query: str, documents: list) -> dict:
    if not query.strip():
        return {
            "success": False,
            "message": "Query prompt cannot be empty."
        }
    qa_result = answer_query(query, documents)
    return {
        "success": True,
        "query": query,
        "category": qa_result["category"],
        "answer": qa_result["answer"],
        "matched_files": qa_result["matched_files"]
    }

def run_generate_insight(title: str, content: str, doc_format: str = "") -> dict:
    clean_title = (title or "Document").strip()
    upper_format = (doc_format or "Document").upper().strip()
    
    if not content.strip():
        return {
            "success": True,
            "title": clean_title,
            "format": upper_format,
            "status": "READY",
            "overview": f"{clean_title} is securely stored in your IntelliSpace as a {upper_format} asset.",
            "main_topics": [f"{clean_title} Overview", f"{upper_format} Structure", "Asset Metadata"],
            "key_points": [f"Document format: {upper_format}", "Stored securely in IntelliSpace"],
            "important_details": [f"File name: {clean_title}", f"Format: {upper_format}", "Indexed in IntelliSpace"],
            "important_terms": [f"{upper_format} — Document container specification"],
            "conclusion": f"{clean_title} has been successfully indexed in your IntelliSpace repository.",
            "summary": f"{clean_title} is securely stored in your IntelliSpace as a {upper_format} asset.",
            "bullet_points": [f"Document format: {upper_format}", "Stored securely in IntelliSpace"],
            "sentence_count": 0
        }

    sentences = split_into_sentences(content)
    total_chars = len(content)

    # 1. Overview Synthesis (2-3 sentences)
    intro_candidates = [s for s in sentences if any(w in s.lower() for w in ['overview', 'summary', 'purpose', 'introduction', 'is an', 'designed to', 'helps', 'provides'])]
    if len(intro_candidates) >= 2:
        overview = " ".join(intro_candidates[:3])
    elif sentences:
        overview = " ".join(sentences[:min(3, len(sentences))])
    else:
        overview = f"{clean_title} is an indexed {upper_format} file in IntelliSpace."

    # 2. Main Topics
    topics = []
    # Check headings, slide tags, sheet tags
    h_matches = re.findall(r'(?:##|#|===|Worksheet:|Slide\s+\d+:?)\s*([^\n\r]+)', content, re.IGNORECASE)
    for h in h_matches:
        h_clean = re.sub(r'[-•*:=#|]', '', h).strip()
        if 3 <= len(h_clean) <= 65 and h_clean not in topics:
            topics.append(h_clean)
        if len(topics) >= 6:
            break

    if len(topics) < 3:
        stop_words = {'the','and','for','that','this','with','from','have','were','which','their','about','these','document','file'}
        words = re.findall(r'\b[A-Z][a-zA-Z0-9-]{3,}\b', content)
        from collections import Counter
        counts = Counter([w.lower() for w in words])
        for w, cnt in counts.most_common(12):
            if w not in stop_words and cnt >= 2:
                cap = w.capitalize()
                if cap not in topics:
                    topics.append(cap)
            if len(topics) >= 5:
                break

    if not topics:
        topics = [os.path.splitext(clean_title)[0], f"{upper_format} Content Analysis", "Document Structure"]

    # 3. Key Points (scored sentences)
    importance_keywords = ['important', 'key', 'critical', 'feature', 'system', 'result', 'finding', 'provides', 'includes', 'requirement', 'objective', 'architecture', 'model', 'benefit', 'standard']
    scored = []
    for idx, s in enumerate(sentences):
        score = 0
        s_lower = s.lower()
        for kw in importance_keywords:
            if kw in s_lower:
                score += 2
        if re.search(r'\d+', s):
            score += 1
        if 40 < len(s) < 220:
            score += 1
        scored.append((score, idx, s))
    scored.sort(key=lambda x: x[0], reverse=True)
    top_items = sorted(scored[:7], key=lambda x: x[1])
    key_points = [item[2] for item in top_items] if top_items else sentences[:min(5, len(sentences))]

    # 4. Important Details (numbers, metrics, formulas, rows)
    details = []
    for s in sentences:
        if re.search(r'(?:\$|€|₹|Rs\.?|USD|\b\d+(?:\.\d+)?%|\b\d{4}\b|\b\d+,\d+|\b\d+\s*(?:MB|KB|GB|users|records|days|months|hours|parameters|accuracy)\b)', s, re.IGNORECASE):
            if s not in details and s not in key_points:
                details.append(s)
        if len(details) >= 6:
            break
    if len(details) < 3:
        row_matches = re.findall(r'(?:Row\s+\d+|Headers?):[^\n]+', content, re.IGNORECASE)
        for rm in row_matches[:5]:
            clean_rm = rm.strip()
            if clean_rm not in details:
                details.append(clean_rm)
    if not details:
        details = [f"Document format: {upper_format}", f"Extracted text volume: {total_chars} characters", f"Indexed sections: {len(topics)}"]

    # 5. Important Terms & Definitions
    terms = []
    acronyms = list(dict.fromkeys(re.findall(r'\b([A-Z]{2,6})\b', content)))
    common_filter = {'THE', 'AND', 'FOR', 'NOT', 'ALL', 'CAN', 'NEW', 'DOC', 'PDF', 'CSV', 'XLS', 'PPT', 'XML', 'TXT', 'ZIP'}
    for acr in acronyms:
        if acr not in common_filter:
            context_sentence = next((s for s in sentences if re.search(rf'\b{acr}\b', s)), None)
            if context_sentence:
                terms.append(f"{acr} — {context_sentence[:140]}")
            else:
                terms.append(f"{acr} — Core terminology identified in {clean_title}")
        if len(terms) >= 6:
            break
    if len(terms) < 4:
        def_matches = re.findall(r'([A-Za-z0-9\s_-]{3,25})\s*(?::|is defined as|refers to)\s*([^\n.]{15,120})', content, re.IGNORECASE)
        for d_term, d_desc in def_matches:
            dt_clean = d_term.strip()
            dd_clean = d_desc.strip()
            if len(dt_clean) <= 30 and dt_clean.upper() not in common_filter:
                terms.append(f"{dt_clean} — {dd_clean}")
            if len(terms) >= 6:
                break
    if not terms:
        terms = [
            f"Document Domain — {topics[0] if topics else 'General Analysis'}",
            "Processing Tier — IntelliSpace Universal Extraction",
            f"File Architecture — Structured {upper_format} Specification"
        ]

    # 6. Conclusion
    conclusion = ""
    if len(sentences) > 3:
        conclusion_candidates = [s for s in sentences[-6:] if any(w in s.lower() for w in ['conclu', 'summary', 'overall', 'future', 'recommend', 'result', 'thus', 'therefore'])]
        if conclusion_candidates:
            conclusion = " ".join(conclusion_candidates)
        else:
            conclusion = sentences[-1]
    if not conclusion or len(conclusion) < 20:
        conclusion = f"In conclusion, {clean_title} provides a comprehensive overview of its subject matter across {upper_format} format, organized into {len(topics)} core functional areas."

    return {
        "success": True,
        "title": clean_title,
        "format": upper_format,
        "status": "READY",
        "overview": overview,
        "main_topics": list(dict.fromkeys(topics)),
        "key_points": list(dict.fromkeys(key_points)),
        "important_details": list(dict.fromkeys(details)),
        "important_terms": list(dict.fromkeys(terms)),
        "conclusion": conclusion,
        "summary": overview,
        "bullet_points": list(dict.fromkeys(key_points)),
        "sentence_count": len(sentences),
        "char_count": total_chars
    }

def run_summarize(title: str, content: str) -> dict:
    return run_generate_insight(title, content)

def run_extract_pdf(file_path: str) -> dict:
    """
    Extracts text and page count from PDF files using pypdf.
    Handles encrypted, empty, and corrupted PDFs safely.
    """
    if not file_path or not os.path.exists(file_path):
        return {"success": False, "error": f"File does not exist: {file_path}", "text": "", "page_count": 0}
    
    file_size = os.path.getsize(file_path)
    if file_size == 0:
        return {"success": False, "error": "Empty file (0 bytes).", "text": "", "page_count": 0}

    # Security check: Max 30 MB
    if file_size > 30 * 1024 * 1024:
        return {"success": False, "error": "File size exceeds 30MB limit.", "text": "", "page_count": 0}

    try:
        from pypdf import PdfReader
        reader = PdfReader(file_path)
        
        # Check encryption
        if reader.is_encrypted:
            try:
                decrypted = reader.decrypt("")
                if decrypted == 0:
                    return {
                        "success": False,
                        "error": "Encrypted PDF (Password Protected).",
                        "is_encrypted": True,
                        "page_count": len(reader.pages) if hasattr(reader, "pages") else 0,
                        "text": ""
                    }
            except Exception:
                return {
                    "success": False,
                    "error": "Encrypted PDF (Password Protected).",
                    "is_encrypted": True,
                    "page_count": len(reader.pages) if hasattr(reader, "pages") else 0,
                    "text": ""
                }

        page_count = len(reader.pages)
        pages_text = []
        for i, page in enumerate(reader.pages):
            txt = page.extract_text() or ""
            if txt.strip():
                pages_text.append(txt.strip())

        full_text = "\n\n".join(pages_text).strip()
        is_scanned = (page_count > 0 and len(full_text) == 0)

        # Safe database limit (100,000 characters)
        safe_text = full_text[:100000]

        return {
            "success": True,
            "text": safe_text,
            "page_count": page_count,
            "char_count": len(safe_text),
            "is_encrypted": False,
            "is_scanned": is_scanned,
            "warning": "Scanned document without text layer. OCR required." if is_scanned else None
        }

    except Exception as e:
        return {
            "success": False,
            "error": f"Corrupted or invalid PDF: {str(e)}",
            "is_corrupted": True,
            "text": "",
            "page_count": 0
        }

class ThreadedHTTPServer(ThreadingMixIn, HTTPServer):
    daemon_threads = True

class AIRequestHandler(BaseHTTPRequestHandler):
    def _send_json(self, status_code: int, data: dict):
        response_bytes = json.dumps(data, ensure_ascii=False).encode("utf-8")
        self.send_response(status_code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(response_bytes)))
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, X-User-Id, Authorization")
        self.end_headers()
        self.wfile.write(response_bytes)

    def do_OPTIONS(self):
        self.send_response(200)
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, X-User-Id, Authorization")
        self.end_headers()

    def do_GET(self):
        path = self.path.split("?")[0].rstrip("/")
        if path == "/health" or path == "":
            self._send_json(200, {
                "status": "online",
                "service": "IntelliFile Local AI Microservice",
                "version": "2.0.0",
                "rag_slm_enabled": True,
                "vector_store": "sqlite3_wal",
                "slm_provider": rag_pipeline.slm_engine.config.get("provider", "grounded_synthesizer"),
                "sklearn_enabled": SKLEARN_AVAILABLE,
                "model_status": "loaded"
            })
        else:
            self._send_json(404, {"success": False, "message": "Endpoint not found"})

    def do_POST(self):
        path = self.path.split("?")[0].rstrip("/")
        content_length = int(self.headers.get("Content-Length", 0))
        post_data = self.rfile.read(content_length).decode("utf-8") if content_length > 0 else "{}"
        
        try:
            body = json.loads(post_data) if post_data.strip() else {}
        except Exception:
            body = {}

        if path == "/hybrid_chat" or path == "/rag_chat":
            user_id = int(body.get("user_id", self.headers.get("X-User-Id", 0)))
            query = str(body.get("query", body.get("message", body.get("prompt", "")))).strip()
            history = body.get("conversation_history", [])
            filter_docs = body.get("filter_doc_ids", None)
            app_stats = body.get("app_stats", {})
            res = hybrid_assistant.process_query(
                user_id=user_id,
                query=query,
                app_stats=app_stats,
                conversation_history=history,
                filter_doc_ids=filter_docs
            )
            status = 200 if res.get("success") else 400
            self._send_json(status, res)

        elif path == "/index_document":
            user_id = int(body.get("user_id", self.headers.get("X-User-Id", 0)))
            doc_id = int(body.get("doc_id", body.get("id", 0)))
            title = str(body.get("title", "")).strip()
            doc_format = str(body.get("format", "PDF")).strip()
            content_text = str(body.get("content_text", "")).strip()
            total_pages = int(body.get("total_pages", 1))
            res = rag_pipeline.index_document(
                user_id=user_id,
                doc_id=doc_id,
                title=title,
                doc_format=doc_format,
                content_text=content_text,
                total_pages=total_pages
            )
            status = 200 if res.get("success") else 400
            self._send_json(status, res)

        elif path == "/sync_documents":
            user_id = int(body.get("user_id", self.headers.get("X-User-Id", 0)))
            documents = body.get("documents", [])
            res = rag_pipeline.sync_user_documents(user_id=user_id, documents=documents)
            self._send_json(200, res)

        elif path == "/delete_document_index":
            user_id = int(body.get("user_id", self.headers.get("X-User-Id", 0)))
            doc_id = int(body.get("doc_id", body.get("id", 0)))
            deleted = rag_pipeline.delete_document(user_id=user_id, doc_id=doc_id)
            self._send_json(200, {"success": True, "deleted": deleted})

        elif path == "/predict_category_priority":
            title = str(body.get("title", "")).strip()
            content = str(body.get("content_text", "")).strip()
            res = run_prediction(title, content)
            self._send_json(200, res)

        elif path == "/document_qa":
            query = str(body.get("query", "")).strip()
            documents = body.get("documents", [])
            res = run_qa(query, documents)
            status = 200 if res.get("success") else 400
            self._send_json(status, res)

        elif path == "/summarize" or path == "/generate_insight":
            title = str(body.get("title", "Document")).strip()
            content = str(body.get("content_text", "")).strip()
            doc_format = str(body.get("format", "")).strip()
            res = run_generate_insight(title, content, doc_format)
            self._send_json(200, res)

        elif path == "/extract_text":
            file_path = str(body.get("file_path", "")).strip()
            res = run_extract_pdf(file_path)
            # 200 for clean extraction or scanned notice; 400 for hard errors / encrypted
            self._send_json(200 if res.get("success") else 400, res)

        else:
            self._send_json(404, {"success": False, "message": "Endpoint not found"})

    def log_message(self, format, *args):
        # Concise logging
        sys.stderr.write(f"[AI SERVICE] {self.address_string()} - {format % args}\n")

def start_server(port: int = 5000):
    # Warm up categorizer
    get_categorizer()
    server = ThreadedHTTPServer(("127.0.0.1", port), AIRequestHandler)
    print(f"[AI SERVICE] IntelliFile Local AI Microservice is LIVE on http://127.0.0.1:{port}")
    sys.stdout.flush()
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("[AI SERVICE] Stopping server...")
        server.server_close()

if __name__ == "__main__":
    start_server(5000)

