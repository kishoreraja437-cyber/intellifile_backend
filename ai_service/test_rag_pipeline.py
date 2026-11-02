import os
import sys
import unittest
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from chunker import DocumentChunker
from embeddings import EmbeddingEngine
from vector_store import VectorStore
from slm_engine import SLMEngine
from rag_pipeline import RAGPipeline

class TestRAGPipeline(unittest.TestCase):
    def setUp(self):
        self.temp_file = tempfile.NamedTemporaryFile(suffix=".db", delete=False)
        self.temp_file.close()
        self.test_db_path = self.temp_file.name

        self.vector_store = VectorStore(db_path=self.test_db_path)
        self.chunker = DocumentChunker(chunk_size=300, overlap=40)
        self.embedding_engine = EmbeddingEngine()
        self.slm_engine = SLMEngine()
        self.pipeline = RAGPipeline(
            vector_store=self.vector_store,
            chunker=self.chunker,
            embedding_engine=self.embedding_engine,
            slm_engine=self.slm_engine
        )

    def tearDown(self):
        del self.pipeline
        del self.vector_store
        import gc
        gc.collect()
        if os.path.exists(self.test_db_path):
            try:
                os.remove(self.test_db_path)
            except Exception:
                pass

    def test_chunking_with_pages(self):
        text = (
            "Section 1: Introduction to Machine Learning. Machine learning algorithms build a model based on sample data. "
            "Slide 1: Supervised learning uses labeled training data. Unsupervised learning discovers hidden patterns in data.\n\n"
            "Slide 2: Neural networks are inspired by biological neural networks in animal brains. Deep learning uses multiple layers."
        )
        chunks = self.chunker.chunk_document(text, doc_id=101, title="ML Lecture", total_pages=2, doc_format="PDF")
        self.assertGreater(len(chunks), 0)
        self.assertEqual(chunks[0]["doc_id"], 101)
        self.assertIn("page_num", chunks[0])

    def test_deduplication(self):
        content = "This is a capstone report for the IntelliFile project. It covers intelligent document management and RAG."
        res1 = self.pipeline.index_document(user_id=1, doc_id=50, title="Report.pdf", doc_format="PDF", content_text=content, total_pages=1)
        self.assertTrue(res1["success"])
        self.assertFalse(res1.get("already_indexed", False))
        self.assertGreater(res1["chunk_count"], 0)

        # Re-index same content -> must be flagged as already indexed (no duplicate embeddings)
        res2 = self.pipeline.index_document(user_id=1, doc_id=50, title="Report.pdf", doc_format="PDF", content_text=content, total_pages=1)
        self.assertTrue(res2["success"])
        self.assertTrue(res2["already_indexed"])

    def test_user_data_isolation(self):
        # User 10 uploads confidential medical record
        self.pipeline.index_document(
            user_id=10,
            doc_id=1,
            title="Medical_Prescription.pdf",
            doc_format="PDF",
            content_text="Patient diagnosed with acute migraine. Prescribed Sumatriptan 50mg as needed.",
            total_pages=1
        )

        # User 20 uploads software design document
        self.pipeline.index_document(
            user_id=20,
            doc_id=2,
            title="Architecture_Design.pdf",
            doc_format="PDF",
            content_text="The backend is built with PHP and Python microservices communicating over JSON REST API.",
            total_pages=1
        )

        # When User 20 asks about migraine medication, they must NOT retrieve User 10's prescription!
        ans_user20 = self.pipeline.chat(user_id=20, query="What medication was prescribed for migraine?")
        self.assertEqual(len(ans_user20["sources"]), 0)
        self.assertIn("could not find information", ans_user20["answer"])

        # When User 10 asks about migraine medication, they get the answer with source
        ans_user10 = self.pipeline.chat(user_id=10, query="What medication was prescribed for migraine?")
        self.assertGreater(len(ans_user10["sources"]), 0)
        self.assertEqual(ans_user10["sources"][0]["title"], "Medical_Prescription.pdf")
        self.assertIn("Sumatriptan", ans_user10["answer"])

    def test_unrelated_query_no_hallucination(self):
        self.pipeline.index_document(
            user_id=1,
            doc_id=99,
            title="Syllabus.pdf",
            doc_format="PDF",
            content_text="Data Structures and Algorithms syllabus covering Stacks, Queues, Binary Trees, and Graphs.",
            total_pages=1
        )
        ans = self.pipeline.chat(user_id=1, query="What is the stock price of Apple today?")
        self.assertEqual(len(ans["sources"]), 0)
        self.assertIn("could not find information", ans["answer"])

    def test_document_summary(self):
        self.pipeline.index_document(
            user_id=1,
            doc_id=88,
            title="Rental_Agreement.pdf",
            doc_format="PDF",
            content_text="This rental agreement is between Landlord John Doe and Tenant Jane Smith. The monthly rent is 1500 USD due on the first day of each month. Security deposit is 3000 USD refundable upon lease expiration.",
            total_pages=1
        )
        ans = self.pipeline.chat(user_id=1, query="Summarize this rental agreement")
        self.assertIn("Summary", ans["answer"])
        self.assertGreater(len(ans["sources"]), 0)
        self.assertEqual(ans["sources"][0]["title"], "Rental_Agreement.pdf")

    def test_multi_document_qa(self):
        # User 1 has two related documents: Project Proposal and Budget Breakdown
        self.pipeline.index_document(
            user_id=1,
            doc_id=201,
            title="Project_Proposal.pdf",
            doc_format="PDF",
            content_text="IntelliFile Project aims to deliver on-device and local cloud AI document processing with RAG and SLM.",
            total_pages=1
        )
        self.pipeline.index_document(
            user_id=1,
            doc_id=202,
            title="Budget_Breakdown.pdf",
            doc_format="PDF",
            content_text="Project budget allocate 5000 USD for server hosting and 2000 USD for testing equipment.",
            total_pages=1
        )

        ans = self.pipeline.chat(user_id=1, query="Summarize project documents")
        self.assertGreater(len(ans["sources"]), 1)
        source_titles = [s["title"] for s in ans["sources"]]
        self.assertIn("Project_Proposal.pdf", source_titles)
        self.assertIn("Budget_Breakdown.pdf", source_titles)

    def test_key_points_extraction(self):
        self.pipeline.index_document(
            user_id=1,
            doc_id=301,
            title="Security_Guidelines.docx",
            doc_format="DOCX",
            content_text=(
                "Rule 1: Always encrypt sensitive data at rest using AES-256. "
                "Rule 2: Implement role based access control for all internal resources. "
                "Rule 3: Rotate authentication tokens every ninety days without exception."
            ),
            total_pages=1
        )
        ans = self.pipeline.chat(user_id=1, query="What are the important points in security guidelines?")
        self.assertIn("Key Takeaways", ans["answer"])
        self.assertIn("Security_Guidelines.docx", ans["answer"])
        self.assertGreater(len(ans["sources"]), 0)

    def test_follow_up_with_history(self):
        self.pipeline.index_document(
            user_id=1,
            doc_id=401,
            title="Trip_Itinerary.pdf",
            doc_format="PDF",
            content_text="Flight departs to Tokyo at 09:30 AM on Friday. Hotel check-in is at the Grand Palace Hotel at 02:00 PM.",
            total_pages=1
        )
        history = [
            {"sender": "USER", "text": "When is the flight to Tokyo?"},
            {"sender": "AI", "text": "Flight departs to Tokyo at 09:30 AM on Friday."}
        ]
        ans = self.pipeline.chat(
            user_id=1,
            query="Where is the hotel check-in?",
            conversation_history=history
        )
        self.assertIn("Grand Palace Hotel", ans["answer"])
        self.assertEqual(ans["sources"][0]["title"], "Trip_Itinerary.pdf")

if __name__ == "__main__":
    unittest.main()
