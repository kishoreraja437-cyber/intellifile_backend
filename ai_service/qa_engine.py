import re
import numpy as np
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.metrics.pairwise import cosine_similarity

def split_into_sentences(text: str) -> list:
    sentences = re.split(r'(?<=[.!?])\s+', text.strip())
    return [s.strip() for s in sentences if len(s.strip()) > 15]

def extract_best_answer(query: str, content: str) -> dict:
    sentences = split_into_sentences(content)
    if not sentences:
        return {"answer": content[:300], "score": 0.5}

    corpus = [query] + sentences
    try:
        vectorizer = TfidfVectorizer(ngram_range=(1, 2), stop_words='english')
        tfidf_matrix = vectorizer.fit_transform(corpus)
        query_vec = tfidf_matrix[0]
        sentence_vecs = tfidf_matrix[1:]

        sims = cosine_similarity(query_vec, sentence_vecs).flatten()
        top_indices = np.argsort(sims)[::-1]

        best_score = float(sims[top_indices[0]]) if len(top_indices) > 0 else 0.0
        
        # Pick top 2 most relevant sentences
        top_sentences = []
        for idx in top_indices[:2]:
            if sims[idx] > 0.05:
                top_sentences.append(sentences[idx])

        if top_sentences:
            return {"answer": " ".join(top_sentences), "score": round(best_score, 3)}
        else:
            return {"answer": sentences[0], "score": 0.2}

    except Exception:
        return {"answer": sentences[0] if sentences else content[:200], "score": 0.2}

def answer_query(query: str, documents: list) -> dict:
    """
    RAG QA pipeline:
    - Analyzes user query
    - Finds most relevant documents
    - Extracts precise answer from document content
    """
    lower_query = query.lower()
    
    # 1. Determine Intent / Category
    if any(k in lower_query for k in ["summar", "overview", "explain", "key point", "takeaway"]):
        category = "ANALYSIS"
    elif any(k in lower_query for k in ["find", "search", "where is", "show me", "locate"]):
        category = "SEARCH"
    else:
        category = "GENERAL"

    if not documents:
        return {
            "category": category,
            "answer": f"I couldn't find any documents in your workspace to answer '{query}'. Please upload relevant documents first.",
            "matched_files": []
        }

    # 2. Score Document Relevance
    doc_scores = []
    vectorizer = TfidfVectorizer(ngram_range=(1, 2), stop_words='english')
    doc_texts = [d.get("title", "") + " " + d.get("content_text", "") + " " + d.get("category", "") for d in documents]

    try:
        tfidf = vectorizer.fit_transform([query] + doc_texts)
        sims = cosine_similarity(tfidf[0:1], tfidf[1:]).flatten()
        for idx, doc in enumerate(documents):
            score = float(sims[idx])
            # Boost score if words in title match directly
            title_words = set(re.findall(r'\w+', doc.get("title", "").lower()))
            query_words = set(re.findall(r'\w+', lower_query))
            overlap = len(title_words.intersection(query_words))
            score += overlap * 0.3
            doc_scores.append((score, doc))
    except Exception:
        for doc in documents:
            doc_scores.append((0.1, doc))

    doc_scores.sort(key=lambda x: x[0], reverse=True)
    top_matches = [d for s, d in doc_scores if s > 0.05][:4]
    
    # Fallback to top documents if no strict match
    if not top_matches:
        top_matches = [doc_scores[0][1]] if doc_scores else []

    # 3. Generate Answer based on Category
    matched_files = []
    for d in top_matches:
        matched_files.append({
            "id": d.get("id"),
            "title": d.get("title"),
            "format": d.get("format", "PDF"),
            "size": d.get("size") or d.get("file_size", "1.0 MB"),
            "dateText": d.get("dateText", "Recent"),
            "priority": d.get("priority", "NORMAL"),
            "category": d.get("category", "General"),
            "file_url": d.get("file_url")
        })

    if not top_matches:
        return {
            "category": category,
            "answer": f"I searched your repository but found no matches for '{query}'.",
            "matched_files": []
        }

    primary_doc = top_matches[0]
    content = primary_doc.get("content_text", "")

    if category == "ANALYSIS":
        if content:
            qa_res = extract_best_answer(query, content)
            answer_text = (
                f"Based on '{primary_doc.get('title')}':\n\n"
                f"{qa_res['answer']}\n\n"
                f"• Priority: {primary_doc.get('priority')}\n"
                f"• Category: {primary_doc.get('category')}"
            )
        else:
            answer_text = f"Document '{primary_doc.get('title')}' is cataloged in category '{primary_doc.get('category')}'. Content indexing in progress."
    
    elif category == "SEARCH":
        count = len(matched_files)
        answer_text = f"I found {count} relevant document{'s' if count > 1 else ''} matching '{query}' in your files."

    else:
        if content:
            qa_res = extract_best_answer(query, content)
            answer_text = f"From '{primary_doc.get('title')}': {qa_res['answer']}"
        else:
            answer_text = f"Regarding '{query}', the most relevant document in your files is '{primary_doc.get('title')}'."

    return {
        "category": category,
        "answer": answer_text,
        "matched_files": matched_files
    }

