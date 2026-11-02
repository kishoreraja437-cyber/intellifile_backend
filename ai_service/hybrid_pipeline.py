import os
import sys
from typing import Dict, Any, List, Optional

from classifier import QueryClassifier
from general_engine import GeneralKnowledgeEngine
from app_data_engine import AppDataEngine
from rag_pipeline import RAGPipeline

class HybridAIAssistant:
    """
    IntelliFile Hybrid AI Assistant:
    Intelligently routes and answers queries across 4 modalities:
    1. GENERAL_QUERY   – Direct SLM knowledge without document retrieval
    2. APP_DATA_QUERY  – Real-time IntelliFile database statistics & file management
    3. DOCUMENT_QUERY  – RAG semantic retrieval & grounded citation answering
    4. HYBRID_QUERY    – Combined repository statistics and document content synthesis
    """

    def __init__(self, rag_pipeline: Optional[RAGPipeline] = None):
        self.classifier = QueryClassifier()
        self.general_engine = GeneralKnowledgeEngine()
        self.app_data_engine = AppDataEngine()
        self.rag_pipeline = rag_pipeline or RAGPipeline()

    def process_query(
        self,
        user_id: int,
        query: str,
        app_stats: Optional[Dict[str, Any]] = None,
        conversation_history: Optional[List[Dict[str, str]]] = None,
        filter_doc_ids: Optional[List[int]] = None
    ) -> Dict[str, Any]:
        clean_query = query.strip()
        if not clean_query:
            return {
                "success": False,
                "message": "Query cannot be empty."
            }

        # Step 1: Intelligent Query Classification
        classification = self.classifier.classify(clean_query, conversation_history)
        query_type = classification["query_type"]
        sub_intent = classification["sub_intent"]
        detected_formats = classification["detected_formats"]

        stats = app_stats or {}

        # Case 1: GENERAL_QUERY (Do NOT trigger document retrieval)
        if query_type == "GENERAL_QUERY":
            # Generate general answer via SLM knowledge engine
            answer = self.general_engine.generate_answer(clean_query, conversation_history)
            return {
                "success": True,
                "query": clean_query,
                "query_type": "GENERAL_QUERY",
                "category": "GENERAL",
                "answer": answer,
                "sources": [],
                "files": [],
                "provider": "slm_general_engine"
            }

        # Case 2: APP_DATA_QUERY (Query live database statistics)
        elif query_type == "APP_DATA_QUERY":
            app_res = self.app_data_engine.format_response(clean_query, sub_intent, stats, detected_formats)
            return {
                "success": True,
                "query": clean_query,
                "query_type": "APP_DATA_QUERY",
                "category": app_res.get("category", "ANALYSIS"),
                "answer": app_res["answer"],
                "sources": [],
                "files": app_res.get("files", []),
                "provider": "app_database_stats"
            }

        # Case 3: HYBRID_QUERY (Combine App Data + RAG Document Retrieval)
        elif query_type == "HYBRID_QUERY":
            # 1. Format app data summary
            app_res = self.app_data_engine.format_response(clean_query, sub_intent, stats, detected_formats)
            app_summary = app_res["answer"]

            # 2. Run RAG on document content
            rag_res = self.rag_pipeline.chat(
                user_id=user_id,
                query=clean_query,
                conversation_history=conversation_history,
                filter_doc_ids=filter_doc_ids
            )

            # 3. Combine both gracefully
            doc_answer = rag_res["answer"]
            combined_answer = f"**Repository Overview:**\n{app_summary}\n\n**Document Analysis:**\n{doc_answer}"

            return {
                "success": True,
                "query": clean_query,
                "query_type": "HYBRID_QUERY",
                "category": "ANALYSIS",
                "answer": combined_answer,
                "sources": rag_res.get("sources", []),
                "files": rag_res.get("sources", []) if rag_res.get("sources") else app_res.get("files", []),
                "provider": "hybrid_rag_slm"
            }

        # Case 4: DOCUMENT_QUERY (Default to RAG Document Retrieval)
        else:
            rag_res = self.rag_pipeline.chat(
                user_id=user_id,
                query=clean_query,
                conversation_history=conversation_history,
                filter_doc_ids=filter_doc_ids
            )
            return {
                "success": True,
                "query": clean_query,
                "query_type": "DOCUMENT_QUERY",
                "category": "ANALYSIS",
                "answer": rag_res["answer"],
                "sources": rag_res.get("sources", []),
                "files": rag_res.get("sources", []),
                "provider": rag_res.get("provider", "rag_slm")
            }

