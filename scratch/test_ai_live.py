import urllib.request
import json

token = "616c7963ee9ca0d8874d621e42d0d4943bdd2efd516d26868f8e291829d27cce"
url = "http://127.0.0.1/intellifile_backend/ai/chat.php"

test_queries = [
    "Show all PDF files",
    "Show word documents",
    "What is the priority of AML Assignment.pdf",
    "What is Artificial Intelligence?"
]

print("=== Running Additional AI Chat Verification ===\n")

for q in test_queries:
    payload = json.dumps({"message": q}).encode("utf-8")
    req = urllib.request.Request(
        url,
        data=payload,
        headers={
            "Content-Type": "application/json",
            "Authorization": f"Bearer {token}"
        }
    )
    try:
        with urllib.request.urlopen(req, timeout=15) as resp:
            data = json.loads(resp.read().decode("utf-8"))
            msg = data.get("message", {})
            reply_text = msg.get("text", "")
            files = msg.get("files", [])
            print(f"QUERY: \"{q}\"")
            print(f"REPLY: {reply_text.splitlines()[0] if reply_text else 'None'}")
            print(f"FILES RETURNED ({len(files)}):")
            for f in files:
                print(f"  - [{f.get('source')}] {f.get('title')} ({f.get('format')}, {f.get('size')})")
            print("-" * 60)
    except Exception as e:
        print(f"ERROR on \"{q}\": {e}")
