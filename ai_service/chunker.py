import re
from typing import List, Dict, Any

class DocumentChunker:
    """
    Splits document text into coherent semantic chunks with metadata tracking:
    - Target chunk size and overlap
    - Sentence/paragraph boundary preservation
    - Page/slide number estimation or detection
    """

    def __init__(self, chunk_size: int = 450, overlap: int = 60):
        self.chunk_size = chunk_size
        self.overlap = overlap

    def clean_text(self, text: str) -> str:
        if not text:
            return ""
        # Normalize carriage returns and tabs
        text = text.replace("\r\n", "\n").replace("\r", "\n").replace("\t", " ")
        # Collapse excessive newlines (> 2) to 2
        text = re.sub(r'\n{3,}', '\n\n', text)
        # Collapse multiple spaces
        text = re.sub(r'[ ]{2,}', ' ', text)
        return text.strip()

    def split_into_sentences(self, text: str) -> List[str]:
        # Split on sentence boundaries (. ! ?) while handling abbreviations
        raw_sentences = re.split(r'(?<=[.!?])\s+', text)
        cleaned = [s.strip() for s in raw_sentences if s.strip()]
        return cleaned

    def chunk_document(
        self,
        text: str,
        doc_id: int,
        title: str,
        total_pages: int = 1,
        doc_format: str = "PDF"
    ) -> List[Dict[str, Any]]:
        cleaned = self.clean_text(text)
        if not cleaned:
            return []

        # Check if text already has slide or page markers (e.g. "Slide 1:", "Page 2:")
        has_page_markers = bool(re.search(r'(?:Page|Slide)\s+(\d+)\s*:', cleaned, re.IGNORECASE))

        paragraphs = [p.strip() for p in cleaned.split("\n\n") if p.strip()]
        if not paragraphs:
            paragraphs = [cleaned]

        chunks: List[Dict[str, Any]] = []
        current_chunk = ""
        current_page = 1
        total_len = len(cleaned)
        running_char_idx = 0

        for para in paragraphs:
            # Detect explicit page/slide marker
            page_match = re.search(r'(?:Page|Slide)\s+(\d+)\s*:', para, re.IGNORECASE)
            if page_match:
                try:
                    current_page = int(page_match.group(1))
                except Exception:
                    pass

            sentences = self.split_into_sentences(para)
            if not sentences:
                sentences = [para]

            for s in sentences:
                if not current_chunk:
                    current_chunk = s
                elif len(current_chunk) + len(s) + 1 <= self.chunk_size:
                    current_chunk += " " + s
                else:
                    # Finalize current chunk
                    chunk_text = current_chunk.strip()
                    if chunk_text:
                        # Estimate page if not explicit
                        if not has_page_markers and total_pages > 1:
                            ratio = running_char_idx / max(1, total_len)
                            estimated_page = min(total_pages, max(1, int(ratio * total_pages) + 1))
                        else:
                            estimated_page = current_page

                        chunks.append({
                            "chunk_index": len(chunks),
                            "chunk_text": chunk_text,
                            "page_num": estimated_page,
                            "doc_id": doc_id,
                            "title": title,
                            "format": doc_format,
                            "char_count": len(chunk_text)
                        })

                    running_char_idx += len(chunk_text)

                    # Overlap: keep the trailing part of the current chunk
                    if self.overlap > 0 and len(current_chunk) > self.overlap:
                        overlap_tail = current_chunk[-self.overlap:].strip()
                        current_chunk = overlap_tail + " " + s
                    else:
                        current_chunk = s

        # Remaining chunk
        if current_chunk.strip():
            chunk_text = current_chunk.strip()
            if not has_page_markers and total_pages > 1:
                ratio = running_char_idx / max(1, total_len)
                estimated_page = min(total_pages, max(1, int(ratio * total_pages) + 1))
            else:
                estimated_page = current_page

            chunks.append({
                "chunk_index": len(chunks),
                "chunk_text": chunk_text,
                "page_num": estimated_page,
                "doc_id": doc_id,
                "title": title,
                "format": doc_format,
                "char_count": len(chunk_text)
            })

        return chunks

