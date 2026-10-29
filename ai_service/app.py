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

def run_summarize(title: str, content: str) -> dict:
    if not content.strip():
        return {
            "success": True,
            "title": title,
            "summary": f"Document '{title}' has no text content available to summarize.",
            "bullet_points": []
        }

    sentences = split_into_sentences(content)
    key_points = sentences[:5] if len(sentences) >= 5 else sentences

    return {
        "success": True,
        "title": title,
        "sentence_count": len(sentences),
        "bullet_points": key_points,
        "summary": " ".join(key_points[:3])
    }

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
                "version": "1.0.0",
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

        if path == "/predict_category_priority":
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

        elif path == "/summarize":
            title = str(body.get("title", "Document")).strip()
            content = str(body.get("content_text", "")).strip()
            res = run_summarize(title, content)
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

