import re
from typing import Dict, Any, List, Optional

class QueryClassifier:
    """
    Intelligent Question Classifier for Hybrid AI Assistant:
    Classifies user queries into:
    1. GENERAL_QUERY  – General technology, coding, science, conversational questions
    2. APP_DATA_QUERY – Queries regarding user's actual IntelliFile files, counts, types, dates
    3. DOCUMENT_QUERY – Content-specific RAG queries (summaries, points, questions on vault documents)
    4. HYBRID_QUERY   – Combinations of app statistics and document content analysis
    """

    APP_DATA_KEYWORDS = [
        "total files", "total file", "how many file", "how many files",
        "how many document", "how many documents", "total document", "total documents",
        "file count", "document count", "count of files", "number of files",
        "how many pdf", "how many docx", "how many ppt", "how many pptx", "how many txt",
        "how many csv", "how many xlsx", "how many json",
        "pdf and docx", "pdf, docx", "file format", "file formats", "file type", "file types",
        "most used format", "most used file type", "which file type do i use most",
        "which format do i use most", "which file type",
        "how many important", "important files", "important file", "priority files",
        "how many saved", "saved files", "saved file",
        "how many bookmarked", "bookmarked files", "bookmarked file", "my bookmarks",
        "uploaded today", "files today", "today's files", "uploaded recently",
        "recently uploaded", "recent files", "recent file", "show my files", "list my files",
        "show my recent", "show recently", "last uploaded",
        "stored in my intellifile", "in my intellifile", "my intellifile storage"
    ]

    DOC_CONTENT_KEYWORDS = [
        "summarize my", "summarize this", "summarize the", "summary of my", "summary of the",
        "explain chapter", "explain section", "explain the document", "explain this document",
        "explain my document", "what are the important points in this",
        "important points in my", "key points in my", "key takeaways from",
        "in my uploaded files", "in my uploaded file", "in my uploaded document", "in my vault",
        "definition of", "find the definition", "compare information from two documents",
        "compare two documents", "compare my documents", "from my document", "from my file",
        "salary slip", "rental agreement", "prescription", "project report", "assignment file",
        "marksheet", "statement"
    ]

    def classify(self, query: str, conversation_history: Optional[List[Dict[str, str]]] = None) -> Dict[str, Any]:
        lower_q = query.lower().strip()
        # Clean punctuation for pattern matching
        clean_q = re.sub(r'[\?\.\!,]', ' ', lower_q)
        clean_q = re.sub(r'\s+', ' ', clean_q).strip()

        has_app_data_intent = False
        has_doc_content_intent = False
        sub_intent = "UNKNOWN"
        detected_formats = []

        # Check detected file formats mentioned
        for fmt in ["pdf", "docx", "doc", "ppt", "pptx", "txt", "csv", "xlsx", "xls", "json"]:
            if re.search(r'\b' + fmt + r'\b', clean_q):
                detected_formats.append(fmt.upper())

        # 1. Check App Data Patterns
        if any(k in clean_q for k in self.APP_DATA_KEYWORDS):
            has_app_data_intent = True
        elif re.search(r'\bhow many\b.*\b(file|files|doc|docs|document|documents)\b', clean_q):
            has_app_data_intent = True
        elif re.search(r'\b(show|list|display)\b.*\b(my\s+)?(file|files|doc|docs|recent)\b', clean_q):
            has_app_data_intent = True

        # Determine sub_intent for App Data
        if has_app_data_intent:
            if any(k in clean_q for k in ["set priority", "change priority", "update priority", "mark as important", "mark as low", "set as important", "assign priority", "how to set priority"]):
                sub_intent = "SET_PRIORITY"
            elif any(k in clean_q for k in ["total file", "total document", "how many total", "how many files are stored", "total count"]):
                sub_intent = "COUNT_TOTAL"
            elif any(k in clean_q for k in ["how many pdf", "how many docx", "how many ppt", "which file type", "most used", "file format", "file type"]):
                sub_intent = "FORMAT_STATS"
            elif any(k in clean_q for k in ["important files", "important file", "how many important", "priority"]):
                sub_intent = "PRIORITY_STATS"
            elif any(k in clean_q for k in ["uploaded today", "today", "today's"]):
                sub_intent = "TODAY_STATS"
            elif any(k in clean_q for k in ["bookmarked", "bookmark"]):
                sub_intent = "BOOKMARK_STATS"
            elif any(k in clean_q for k in ["saved files", "saved file", "how many saved"]):
                sub_intent = "SAVED_STATS"
            elif any(k in clean_q for k in ["recent files", "recently uploaded", "show my files", "list my files", "recent"]):
                sub_intent = "RECENT_FILES"
            else:
                sub_intent = "GENERAL_STATS"

        # 2. Check Document Content Patterns
        if any(k in clean_q for k in self.DOC_CONTENT_KEYWORDS):
            has_doc_content_intent = True
        elif re.search(r'\b(summar|explain|point|points|takeaway|takeaways|compare)\b.*\b(pdf|docx|ppt|doc|document|file)\b', clean_q):
            has_doc_content_intent = True
        elif any(k in clean_q for k in ["in my document", "in my uploaded", "in my file", "from my file", "from my document"]):
            has_doc_content_intent = True

        # 3. Detect Hybrid vs App Data vs Document vs General
        if has_app_data_intent and has_doc_content_intent:
            query_type = "HYBRID_QUERY"
        elif has_app_data_intent:
            query_type = "APP_DATA_QUERY"
        elif has_doc_content_intent:
            query_type = "DOCUMENT_QUERY"
        else:
            # Check if query references general AI/Tech/Coding concepts
            general_patterns = [
                r'\bwhat is\b', r'\bwhat are\b', r'\bexplain\b', r'\bhow does\b',
                r'\bwrite a\b', r'\bhow to\b', r'\bwhy is\b', r'\btell me about\b',
                r'\bdifference between\b', r'\bdefine\b'
            ]
            is_general = any(re.search(pat, clean_q) for pat in general_patterns)

            # Check greetings / conversational
            conversational = ["hello", "hi", "hey", "who are you", "what can you do", "thank you", "thanks"]
            if clean_q in conversational or any(clean_q.startswith(c) for c in conversational):
                is_general = True

            # If it's a general question or default
            if is_general:
                query_type = "GENERAL_QUERY"
            else:
                # Default to DOCUMENT_QUERY if user is looking for a specific topic in files
                # unless clearly general
                query_type = "DOCUMENT_QUERY"

        return {
            "query_type": query_type,
            "sub_intent": sub_intent,
            "detected_formats": detected_formats,
            "has_app_data_intent": has_app_data_intent,
            "has_doc_content_intent": has_doc_content_intent,
            "cleaned_query": clean_q
        }

