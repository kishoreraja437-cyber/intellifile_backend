import os
import sys
import unittest
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from classifier import QueryClassifier
from hybrid_pipeline import HybridAIAssistant
from vector_store import VectorStore
from chunker import DocumentChunker
from embeddings import EmbeddingEngine
from slm_engine import SLMEngine
from rag_pipeline import RAGPipeline

class TestHybridPipeline(unittest.TestCase):
    def setUp(self):
        self.temp_file = tempfile.NamedTemporaryFile(suffix=".db", delete=False)
        self.temp_file.close()
        self.test_db_path = self.temp_file.name

        self.vector_store = VectorStore(db_path=self.test_db_path)
        self.chunker = DocumentChunker(chunk_size=300, overlap=40)
        self.embedding_engine = EmbeddingEngine()
        self.slm_engine = SLMEngine()
        self.rag_pipeline = RAGPipeline(
            vector_store=self.vector_store,
            chunker=self.chunker,
            embedding_engine=self.embedding_engine,
            slm_engine=self.slm_engine
        )
        self.assistant = HybridAIAssistant(rag_pipeline=self.rag_pipeline)

        # Mock app stats for user 100
        self.user_stats = {
            "total_files": 12,
            "format_counts": {"PDF": 7, "DOCX": 2, "PPT": 1, "CSV": 1, "TXT": 1},
            "priority_counts": {"IMPORTANT": 5, "NORMAL": 5, "LOW": 2},
            "bookmarked_count": 3,
            "saved_count": 4,
            "today_count": 2,
            "most_used_format": "PDF",
            "recent_files": [
                {"id": 1, "title": "AI_Report.pdf", "format": "PDF", "size": "2.4 MB", "dateText": "Today", "priority": "IMPORTANT"},
                {"id": 2, "title": "Budget.xlsx", "format": "XLSX", "size": "1.1 MB", "dateText": "Today", "priority": "NORMAL"}
            ]
        }

    def tearDown(self):
        del self.assistant
        del self.rag_pipeline
        del self.vector_store
        import gc
        gc.collect()
        if os.path.exists(self.test_db_path):
            try:
                os.remove(self.test_db_path)
            except Exception:
                pass

    def test_query_classification(self):
        classifier = QueryClassifier()

        # General questions
        self.assertEqual(classifier.classify("What is Artificial Intelligence?")["query_type"], "GENERAL_QUERY")
        self.assertEqual(classifier.classify("Explain Machine Learning with examples.")["query_type"], "GENERAL_QUERY")
        self.assertEqual(classifier.classify("What is Python?")["query_type"], "GENERAL_QUERY")
        self.assertEqual(classifier.classify("How does cloud computing work?")["query_type"], "GENERAL_QUERY")
        self.assertEqual(classifier.classify("Write a short paragraph about technology.")["query_type"], "GENERAL_QUERY")
        self.assertEqual(classifier.classify("Explain programming concepts.")["query_type"], "GENERAL_QUERY")

        # App Data questions
        self.assertEqual(classifier.classify("How many total files are stored in my IntelliFile?")["query_type"], "APP_DATA_QUERY")
        self.assertEqual(classifier.classify("How many PDF, DOCX and PPT files do I have?")["query_type"], "APP_DATA_QUERY")
        self.assertEqual(classifier.classify("How many important files are saved?")["query_type"], "APP_DATA_QUERY")
        self.assertEqual(classifier.classify("How many files were uploaded today?")["query_type"], "APP_DATA_QUERY")
        self.assertEqual(classifier.classify("Show my recently uploaded files.")["query_type"], "APP_DATA_QUERY")
        self.assertEqual(classifier.classify("How many bookmarked files do I have?")["query_type"], "APP_DATA_QUERY")
        self.assertEqual(classifier.classify("Which file type do I use most?")["query_type"], "APP_DATA_QUERY")
        self.assertEqual(classifier.classify("What is my total document count?")["query_type"], "APP_DATA_QUERY")

        # Document questions
        self.assertEqual(classifier.classify("Summarize my PDF.")["query_type"], "DOCUMENT_QUERY")
        self.assertEqual(classifier.classify("Explain the second chapter of my document.")["query_type"], "DOCUMENT_QUERY")
        self.assertEqual(classifier.classify("What are the important points in this PPT?")["query_type"], "DOCUMENT_QUERY")
        self.assertEqual(classifier.classify("Find the definition of machine learning in my uploaded files.")["query_type"], "DOCUMENT_QUERY")
        self.assertEqual(classifier.classify("Compare information from two documents.")["query_type"], "DOCUMENT_QUERY")

        # Hybrid question
        self.assertEqual(classifier.classify("How many total files do I have and summarize my uploaded document?")["query_type"], "HYBRID_QUERY")

    def test_general_query_execution(self):
        res = self.assistant.process_query(user_id=100, query="What is Artificial Intelligence?")
        self.assertTrue(res["success"])
        self.assertEqual(res["query_type"], "GENERAL_QUERY")
        self.assertEqual(res["category"], "GENERAL")
        self.assertIn("Artificial Intelligence", res["answer"])
        # Crucial requirement: general queries must NOT trigger document retrieval or return random files
        self.assertEqual(len(res["sources"]), 0)
        self.assertEqual(len(res["files"]), 0)

    def test_app_data_file_counts(self):
        res = self.assistant.process_query(
            user_id=100,
            query="How many total files are stored in my IntelliFile?",
            app_stats=self.user_stats
        )
        self.assertTrue(res["success"])
        self.assertEqual(res["query_type"], "APP_DATA_QUERY")
        self.assertIn("12 files", res["answer"])

    def test_app_data_format_breakdown(self):
        res = self.assistant.process_query(
            user_id=100,
            query="How many PDF, DOCX and PPT files do I have?",
            app_stats=self.user_stats
        )
        self.assertTrue(res["success"])
        self.assertEqual(res["query_type"], "APP_DATA_QUERY")
        self.assertIn("PDF**: 7", res["answer"])
        self.assertIn("DOCX**: 2", res["answer"])
        self.assertIn("PPT**: 1", res["answer"])

    def test_app_data_important_and_today(self):
        res_imp = self.assistant.process_query(
            user_id=100,
            query="How many important files are saved?",
            app_stats=self.user_stats
        )
        self.assertIn("5 Important", res_imp["answer"])

        res_today = self.assistant.process_query(
            user_id=100,
            query="How many files were uploaded today?",
            app_stats=self.user_stats
        )
        self.assertIn("2 files", res_today["answer"])

    def test_app_data_recent_files(self):
        res = self.assistant.process_query(
            user_id=100,
            query="Show my recently uploaded files.",
            app_stats=self.user_stats
        )
        self.assertEqual(len(res["files"]), 2)
        self.assertEqual(res["files"][0]["title"], "AI_Report.pdf")

    def test_document_query_rag(self):
        # Index a sample document
        self.rag_pipeline.index_document(
            user_id=100,
            doc_id=10,
            title="Syllabus.pdf",
            doc_format="PDF",
            content_text="Machine learning is defined as a subset of AI enabling computer systems to learn from data autonomously.",
            total_pages=1
        )
        res = self.assistant.process_query(
            user_id=100,
            query="Find the definition of machine learning in my uploaded files.",
            app_stats=self.user_stats
        )
        self.assertEqual(res["query_type"], "DOCUMENT_QUERY")
        self.assertIn("Syllabus.pdf", res["answer"])
        self.assertGreater(len(res["sources"]), 0)

if __name__ == "__main__":
    unittest.main()

