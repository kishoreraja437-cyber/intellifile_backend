import math
import re
from collections import Counter

# IntelliFile Comprehensive Document Classification Dataset
TRAINING_DATA = [
    # 1. Identity & Government Documents
    ("Government of India Unique Identification Authority of India UIDAI Aadhaar card Mera Aadhaar Meri Pehchan 12 digit enrolment male female address DOB", "Aadhaar Card"),
    ("Aadhaar Card download e-Aadhaar UIDAI virtual ID Vid helpdesk uidai.gov.in biometric barcode QR code", "Aadhaar Card"),
    ("Income Tax Department Govt. of India Permanent Account Number PAN Card Father's Name Date of Birth signature barcode alphanumeric 10 characters", "PAN Card"),
    ("NSDL e-Governance UTIITSL PAN allotment letter Income Tax PAN verification form 49A", "PAN Card"),
    ("Union of India Driving Licence Motor Vehicles Department Transport Authority DL No License valid till non-transport transport LMV MCWG", "Driving License"),
    ("Election Commission of India Elector Photo Identity Card EPIC Voter ID Card Electoral Roll Assembly Constituency Polling Station", "Voter ID"),
    ("Republic of India Passport Type P Country Code IND Passport No Surname Given Name Nationality Indian Date of Expiry Place of Issue", "Passport"),
    ("Food and Civil Supplies Department Ration card BPL APL NFSA Antyodaya Anna Yojana Family members quota rice wheat subsidy", "Ration Card"),

    # 2. Academic & Education
    ("Statement of Marks Board of Secondary Education SSLC 10th standard exam register number marks obtained subjects theory practical total result pass", "Marksheet"),
    ("Higher Secondary Examination HSC 12th Marks Sheet Physics Chemistry Maths Biology English aggregate percentage distinction pass certificate", "Marksheet"),
    ("University Consolidated Grade Sheet Semester Examination CGPA SGPA Controller of Examinations register no credits earned grade points", "Marksheet"),
    ("Anna University Provisional Certificate Degree of Bachelor of Engineering Technology First Class with Distinction Graduation convocation", "Academic Certificate"),
    ("Semester Hall Ticket Admit Card Examination Schedule Candidate Roll Number Exam Centre Reporting Time invigilator signature instructions", "Hall Ticket"),
    ("College Tuition Fee Receipt hostel fee payment acknowledgment term semester challan account section paid stamp", "Fee Receipt"),
    ("Transfer Certificate TC Conduct Character certificate student admission roll number date of leaving school college", "Academic Certificate"),
    ("AML Assignment 1 Convolutional Neural Networks gradient descent loss function submission due", "Assignment"),
    ("Data Structures Lab Assignment Problem Sheet binary search trees heaps implementation", "Assignment"),
    ("DBMS Assignment on Relational Algebra SQL queries normalization BCNF exercises", "Assignment"),
    ("Computer Networks homework socket programming TCP UDP handshake assignment questions", "Assignment"),
    ("Physics Assignment problem set thermodynamics kinematics solve problems for marks", "Assignment"),
    ("Maths Tutorial sheet integration differential equations assignment submission deadline", "Assignment"),
    ("DBMS Lecture Notes chapter 3 transaction management ACID properties serializability revision notes", "Notes"),
    ("Operating System class notes virtual memory paging segmentation thrashing study guide", "Notes"),
    ("Computer Networks study revision notes OSI 7 layer model IP addressing subnetting", "Notes"),
    ("Machine Learning exam notes supervised unsupervised learning decision trees random forest", "Notes"),
    ("Data Structures and Algorithms quick revision summary sorting searching dynamic programming", "Notes"),
    ("Compiler design class notes syntax analysis bottom up top down parsing LL1 LR0", "Notes"),
    ("Cloud Computing notes AWS EC2 S3 virtualization containers docker kubernetes", "Notes"),

    # 3. Finance & Banking
    ("State Bank of India Account Statement Savings Bank A/C Number IFSC Code Branch Transaction Details Debit Credit Balance Opening Closing", "Bank Statement"),
    ("HDFC Bank ICICI Axis Bank statement of transactions UPI transfer NEFT RTGS IMPS withdrawal deposit balance inquiry", "Bank Statement"),
    ("Salary Slip Payslip Employee Code Designation Earnings Basic Pay HRA Allowance Deductions PF Provident Fund Professional Tax Net Pay", "Salary Slip"),
    ("Monthly Salary Statement CTC Gross Earnings TDS deduction ESIC Employee Provident Fund payslip for the month of", "Salary Slip"),
    ("Form No. 16 Part A and Part B Certificate under section 203 of the Income-tax Act 1961 Tax Deducted at Source TDS salary employer TAN PAN", "Tax Document"),
    ("Income Tax Return Verification Form ITR-V Assessment Year acknowledgment e-filed total income taxes paid refund", "Tax Document"),
    ("Life Insurance Policy LIC premium receipt sum assured policyholder name maturity date nominee term plan", "Insurance Policy"),
    ("Health Insurance Mediclaim policy document cashless card TPA hospital coverage sum insured policy schedule", "Insurance Policy"),

    # 4. Medical & Healthcare
    ("Doctor Prescription Clinic Hospital Rx Patient Name Age Gender Diagnosis Symptoms Medication Dosage Morning Night Tablets Syrup Dr. MBBS MD", "Medical Prescription"),
    ("Apollo Diagnostics Metropolis Lab Test Report Complete Blood Count CBC Hemoglobin Platelets Lipid Profile Blood Sugar Fasting Reference Range", "Medical Lab Report"),
    ("Hospital Discharge Summary Inpatient admission date surgery procedure performed clinical summary doctor advice follow up", "Discharge Summary"),
    ("COVID-19 Vaccination Certificate Ministry of Health Family Welfare Beneficiary Name Age Vaccine Name Dose 1 Dose 2 Universal Passport Cowin", "Vaccination Certificate"),

    # 5. Career & Professional
    ("Resume Curriculum Vitae Kishore Kumar software engineer Android Kotlin Java PHP MySQL skills education work history achievements", "Resume"),
    ("CV Arul Kumar machine learning engineer Python TensorFlow PyTorch projects experience graduation publications", "Resume"),
    ("Software Developer Resume work history technical skills Bachelor of Technology CGPA honors contact email phone", "Resume"),
    ("Employment Offer Letter Position Software Engineer CTC compensation joining date terms of employment appointment letter", "Offer Letter"),
    ("Relieving Letter and Service Certificate Experience Certificate conduct satisfactory tenure resignation accepted final settlement", "Experience Certificate"),
    ("Internship Completion Certificate successfully completed two months summer internship project evaluation recommendation", "Internship Certificate"),

    # 6. Legal & Property
    ("Rental Agreement Tenancy Contract Stamp Paper Non-Judicial Landlord Tenant Monthly Rent Security Deposit Lease Period premises electricity maintenance", "Rental Agreement"),
    ("Sworn Affidavit before Notary Public deponent solemnly affirm declare oath identity verification stamp advocate", "Affidavit"),
    ("Non-Disclosure Agreement NDA Confidential Information proprietary trade secrets disclosure obligations parties effective date", "Legal Contract"),
    ("Sale Deed Property conveyance registration sub-registrar survey number boundaries plot land consideration amount", "Property Document"),

    # 7. Invoices & Receipts
    ("Tax Invoice Invoice Number INV-2024-001 Bill to customer total amount due payment receipt GSTIN HSN SAC code CGST SGST IGST", "Invoice"),
    ("Amazon India Flipkart Order Invoice Tax Invoice Cash on Delivery payment confirmation shipping address delivery item description", "Invoice"),
    ("Electricity Bill Consumer No Power Distribution Company Units Consumed Due Date Bill Amount Meter Reading Disconnection Notice", "Utility Bill"),
    ("IRCTC Electronic Reservation Slip E-Ticket PNR Number Train Name Quota Class Boarding Station Coach Seat Berth Passenger Name", "Travel Ticket"),

    # 8. Project & Reports
    ("IntelliFile AI document assistant final year project report architecture system design SRS methodology evaluation conclusions", "Project Report"),
    ("Automated Attendance System using Face Recognition capstone project documentation abstract methodology literature survey", "Project Report"),
    ("An Empirical Study on Retrieval-Augmented Generation for Mobile Document Systems IEEE Conference Paper Journal abstract", "Research Paper"),

    # 9. General
    ("Meeting minutes team standup discussion action items follow up next meeting schedule circular notice", "General"),
    ("Terms and conditions user privacy policy license agreement disclaimer general documentation", "General")
]

# Sensitive Identity Documents (Require High Security)
SENSITIVE_CATEGORIES = {
    "Aadhaar Card", "PAN Card", "Driving License", "Voter ID", "Passport",
    "Bank Statement", "Salary Slip", "Tax Document"
}

# Priority Keyword Dictionaries
URGENT_KEYWORDS = [
    "urgent", "due tomorrow", "deadline", "immediate", "exam", "final submission",
    "critical", "important", "high priority", "asap", "mandatory", "required",
    "submission date", "midterm", "viva", "presentation date", "hall ticket",
    "admit card", "court date", "expiry date", "bill due date", "disconnect"
]

NORMAL_KEYWORDS = [
    "assignment", "homework", "task", "project", "review", "draft", "meeting",
    "notes", "syllabus", "regular", "weekly", "discussion", "update", "marksheet",
    "salary slip", "statement", "receipt", "invoice", "prescription"
]

LOW_KEYWORDS = [
    "reference", "archive", "optional", "reading", "general", "extra", "backup",
    "old", "sample", "template", "cheat sheet", "informational"
]

STOP_WORDS = {
    "a", "about", "above", "after", "again", "against", "all", "am", "an", "and", "any", "are", "as",
    "at", "be", "because", "been", "before", "being", "below", "between", "both", "but", "by", "could",
    "did", "do", "does", "doing", "down", "during", "each", "few", "for", "from", "further", "had",
    "has", "have", "having", "he", "her", "here", "hers", "herself", "him", "himself", "his", "how",
    "i", "if", "in", "into", "is", "it", "its", "itself", "me", "more", "most", "my", "myself", "no",
    "nor", "not", "of", "off", "on", "once", "only", "or", "other", "ought", "our", "ours", "ourselves",
    "out", "over", "own", "same", "she", "should", "so", "some", "such", "than", "that", "the", "their",
    "theirs", "them", "themselves", "then", "there", "these", "they", "this", "those", "through", "to",
    "too", "under", "until", "up", "very", "was", "we", "were", "what", "when", "where", "which", "while",
    "who", "whom", "why", "with", "would", "you", "your", "yours", "yourself", "yourselves"
}

def tokenize(text: str) -> list:
    words = re.findall(r'[a-zA-Z0-9]+', text.lower())
    return [w for w in words if len(w) >= 2 and w not in STOP_WORDS]

# Deterministic Pattern Matcher for 100% Precision on Identity & Special Docs
def detect_rule_based_category(text: str) -> dict:
    lower = text.lower()
    
    # 1. Aadhaar Card
    if "aadhaar" in lower or "uidai" in lower or "mera aadhaar" in lower or re.search(r'\b\d{4}\s\d{4}\s\d{4}\b', text):
        return {"category": "Aadhaar Card", "confidence": 0.99, "is_sensitive": True, "priority": "IMPORTANT"}

    # 2. PAN Card
    if "permanent account number" in lower or ("income tax department" in lower and "pan" in lower) or re.search(r'\b[A-Za-z]{5}[0-9]{4}[A-Za-z]\b', text):
        return {"category": "PAN Card", "confidence": 0.99, "is_sensitive": True, "priority": "IMPORTANT"}

    # 3. Driving License
    if "driving licence" in lower or "driving license" in lower or ("motor vehicles" in lower and "license" in lower):
        return {"category": "Driving License", "confidence": 0.98, "is_sensitive": True, "priority": "IMPORTANT"}

    # 4. Voter ID
    if "elector photo identity" in lower or "voter id" in lower or "election commission of india" in lower or re.search(r'\b[A-Za-z]{3}[0-9]{7}\b', text):
        return {"category": "Voter ID", "confidence": 0.98, "is_sensitive": True, "priority": "IMPORTANT"}

    # 5. Passport
    if ("republic of india" in lower and "passport" in lower) or "passport no" in lower or "place of issue" in lower and "nationality indian" in lower:
        return {"category": "Passport", "confidence": 0.98, "is_sensitive": True, "priority": "IMPORTANT"}

    # 6. Marksheet & Grade Cards
    if any(k in lower for k in ["statement of marks", "consolidated grade sheet", "marksheet", "grade card", "marks sheet", "controller of examinations", "register number", "provisional certificate"]):
        return {"category": "Marksheet", "confidence": 0.97, "is_sensitive": False, "priority": "IMPORTANT"}

    # 7. Hall Ticket / Admit Card
    if any(k in lower for k in ["hall ticket", "admit card", "exam roll number", "candidate roll no", "examination schedule"]):
        return {"category": "Hall Ticket", "confidence": 0.98, "is_sensitive": False, "priority": "IMPORTANT"}

    # 8. Bank Statement
    if ("account statement" in lower or "savings bank" in lower or "statement of account" in lower) and ("ifsc" in lower or "balance" in lower or "debit" in lower):
        return {"category": "Bank Statement", "confidence": 0.96, "is_sensitive": True, "priority": "IMPORTANT"}

    # 9. Salary Slip / Payslip
    if any(k in lower for k in ["salary slip", "payslip", "pay slip", "earnings and deductions", "basic pay", "net salary", "gross earnings"]):
        return {"category": "Salary Slip", "confidence": 0.97, "is_sensitive": True, "priority": "IMPORTANT"}

    # 10. Medical Prescription
    if ("prescription" in lower or "dr." in lower or "clinic" in lower or "hospital" in lower) and ("dosage" in lower or "tablets" in lower or "rx" in lower or "syrup" in lower):
        return {"category": "Medical Prescription", "confidence": 0.96, "is_sensitive": False, "priority": "IMPORTANT"}

    # 11. Rental Agreement
    if any(k in lower for k in ["rental agreement", "tenancy contract", "landlord and tenant", "security deposit", "stamp paper"]):
        return {"category": "Rental Agreement", "confidence": 0.96, "is_sensitive": False, "priority": "IMPORTANT"}

    return None

class SimpleTfidfVectorizer:
    def __init__(self):
        self.vocabulary = {}
        self.idf = {}
        self.num_docs = 0

    def fit(self, corpus: list):
        self.num_docs = len(corpus)
        doc_freq = Counter()
        vocab_set = set()

        for doc in corpus:
            tokens = set(tokenize(doc))
            vocab_set.update(tokens)
            for t in tokens:
                doc_freq[t] += 1

        self.vocabulary = {term: idx for idx, term in enumerate(sorted(vocab_set))}
        for term, df in doc_freq.items():
            self.idf[term] = math.log((1 + self.num_docs) / (1 + df)) + 1.0

        return self

    def transform_vector(self, text: str) -> dict:
        tokens = tokenize(text)
        if not tokens:
            return {}
        
        tf = Counter(tokens)
        total = len(tokens)
        vec = {}
        sum_sq = 0.0

        for term, count in tf.items():
            if term in self.vocabulary:
                w = (count / total) * self.idf.get(term, 1.0)
                vec[term] = w
                sum_sq += w * w

        norm = math.sqrt(sum_sq)
        if norm > 0:
            for term in vec:
                vec[term] /= norm

        return vec

def cosine_similarity(vec1: dict, vec2: dict) -> float:
    if not vec1 or not vec2:
        return 0.0
    dot = 0.0
    for term, val1 in vec1.items():
        if term in vec2:
            dot += val1 * vec2[term]
    return dot

class CategorizerModel:
    def __init__(self):
        self.vectorizer = SimpleTfidfVectorizer()
        self.class_centroids = {}
        self.classes = []

    def train(self, data):
        texts, labels = zip(*data)
        self.vectorizer.fit(list(texts))
        self.classes = sorted(list(set(labels)))

        by_class = {c: [] for c in self.classes}
        for text, label in data:
            by_class[label].append(text)

        for label, class_texts in by_class.items():
            combined = " ".join(class_texts)
            self.class_centroids[label] = self.vectorizer.transform_vector(combined)

    def predict(self, text: str) -> tuple:
        # 1. Deterministic Rule Match first (100% precision for critical docs)
        rule_match = detect_rule_based_category(text)
        if rule_match:
            return rule_match["category"], rule_match["confidence"]

        # 2. Statistical TF-IDF Centroid Classifier
        vec = self.vectorizer.transform_vector(text)
        if not vec:
            return "General", 0.5

        scores = {}
        for c in self.classes:
            sim = cosine_similarity(vec, self.class_centroids[c])
            scores[c] = sim

        best_class = max(scores, key=scores.get)
        best_score = scores[best_class]

        total_sim = sum(scores.values()) or 1.0
        confidence = round(max(0.45, min(0.99, best_score / total_sim * 2.2)), 3)

        return best_class, confidence

# Singleton classifier
_categorizer = None

def get_categorizer():
    global _categorizer
    if _categorizer is None:
        _categorizer = CategorizerModel()
        _categorizer.train(TRAINING_DATA)
    return _categorizer

def score_priority(text: str, category: str = "") -> dict:
    lower = text.lower()
    urgent_hits = [k for k in URGENT_KEYWORDS if k in lower]
    normal_hits = [k for k in NORMAL_KEYWORDS if k in lower]
    low_hits = [k for k in LOW_KEYWORDS if k in lower]

    # Category base priorities
    if category in SENSITIVE_CATEGORIES or category in ["Marksheet", "Hall Ticket", "Medical Prescription"]:
        score = 75 # Identity, Marksheet & Medical docs start at high priority
    elif category in ["Assignment", "Invoice", "Rental Agreement", "Offer Letter"]:
        score = 55
    elif category in ["Research Paper", "Project Report", "Resume"]:
        score = 45
    else:
        score = 30

    score += len(urgent_hits) * 20
    score += len(normal_hits) * 5
    score -= len(low_hits) * 15

    score = max(5, min(95, score))

    if score >= 65:
        priority = "IMPORTANT"
    elif score >= 35:
        priority = "NORMAL"
    else:
        priority = "LOW"

    return {
        "priority": priority,
        "score": score,
        "detected_cues": urgent_hits if urgent_hits else (normal_hits if normal_hits else low_hits)
    }

def split_into_sentences(text: str) -> list:
    cleaned = re.sub(r'[\r\n]+', ' ', text.strip())
    sentences = re.split(r'(?<=[.!?])\s+', cleaned)
    return [s.strip() for s in sentences if len(s.strip()) > 15]

def extract_best_answer(query: str, content: str) -> dict:
    sentences = split_into_sentences(content)
    if not sentences:
        return {"answer": content[:300], "score": 0.5}

    vectorizer = SimpleTfidfVectorizer()
    vectorizer.fit([query] + sentences)
    query_vec = vectorizer.transform_vector(query)

    scored = []
    for s in sentences:
        s_vec = vectorizer.transform_vector(s)
        sim = cosine_similarity(query_vec, s_vec)
        scored.append((sim, s))

    scored.sort(key=lambda x: x[0], reverse=True)
    best_sim, best_sentence = scored[0]

    top_sentences = [best_sentence]
    if len(scored) > 1 and scored[1][0] > 0.08:
        top_sentences.append(scored[1][1])

    return {
        "answer": " ".join(top_sentences),
        "score": round(best_sim, 3)
    }

def answer_query(query: str, documents: list) -> dict:
    lower_query = query.lower()

    if any(k in lower_query for k in ["summar", "overview", "explain", "key point", "takeaway"]):
        category = "ANALYSIS"
    elif any(k in lower_query for k in ["find", "search", "where is", "show me", "locate", "my aadhar", "my pan", "marksheet"]):
        category = "SEARCH"
    else:
        category = "GENERAL"

    if not documents:
        return {
            "category": category,
            "answer": f"I couldn't find any documents in your workspace to answer '{query}'. Please upload relevant documents first.",
            "matched_files": []
        }

    # Score documents
    doc_scores = []
    vectorizer = SimpleTfidfVectorizer()
    all_texts = [query] + [d.get("title", "") + " " + d.get("content_text", "") for d in documents]
    vectorizer.fit(all_texts)
    q_vec = vectorizer.transform_vector(query)

    for doc in documents:
        doc_text = doc.get("title", "") + " " + doc.get("content_text", "")
        d_vec = vectorizer.transform_vector(doc_text)
        sim = cosine_similarity(q_vec, d_vec)

        # Title and Category bonus
        title_words = set(tokenize(doc.get("title", "")))
        cat_words = set(tokenize(doc.get("category", "")))
        q_words = set(tokenize(query))
        sim += len(title_words.intersection(q_words)) * 0.35
        sim += len(cat_words.intersection(q_words)) * 0.25
        doc_scores.append((sim, doc))

    doc_scores.sort(key=lambda x: x[0], reverse=True)
    top_matches = [d for s, d in doc_scores if s > 0.04][:4]
    if not top_matches and doc_scores:
        top_matches = [doc_scores[0][1]]

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

    primary = top_matches[0]
    content = primary.get("content_text", "")

    if category == "ANALYSIS":
        if content:
            qa_res = extract_best_answer(query, content)
            answer_text = (
                f"Based on '{primary.get('title')}':\n\n"
                f"{qa_res['answer']}\n\n"
                f"• Priority: {primary.get('priority')}\n"
                f"• Category: {primary.get('category')}"
            )
        else:
            answer_text = f"Document '{primary.get('title')}' is cataloged in category '{primary.get('category')}'. Content indexing in progress."

    elif category == "SEARCH":
        count = len(matched_files)
        answer_text = f"I found {count} relevant document{'s' if count > 1 else ''} matching '{query}' in your files."

    else:
        if content:
            qa_res = extract_best_answer(query, content)
            answer_text = f"From '{primary.get('title')}': {qa_res['answer']}"
        else:
            answer_text = f"Regarding '{query}', the most relevant document in your files is '{primary.get('title')}'."

    return {
        "category": category,
        "answer": answer_text,
        "matched_files": matched_files
    }
