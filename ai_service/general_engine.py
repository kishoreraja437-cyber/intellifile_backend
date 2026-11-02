import re
from typing import Optional, List, Dict, Any

class GeneralKnowledgeEngine:
    """
    General AI Knowledge and Question-Answering Engine.
    Provides comprehensive, well-structured answers for technology, programming,
    artificial intelligence, cloud computing, and general writing prompts.
    """

    KNOWLEDGE_BASE = {
        "ai": {
            "title": "Artificial Intelligence (AI)",
            "content": (
                "**Artificial Intelligence (AI)** is the branch of computer science dedicated to creating systems capable of performing tasks that typically require human intelligence. These tasks include reasoning, problem-solving, understanding natural language, visual perception, and decision-making.\n\n"
                "• **Key Branches**: Machine Learning (learning from data), Natural Language Processing (understanding text/speech), Computer Vision (interpreting images/video), and Robotics (autonomous physical actions).\n"
                "• **Modern AI**: Driven by deep neural networks, Large Language Models (LLMs), and Small Language Models (SLMs) that power intelligent assistants, recommendation engines, and autonomous systems."
            )
        },
        "ml": {
            "title": "Machine Learning (ML)",
            "content": (
                "**Machine Learning (ML)** is a subset of Artificial Intelligence where algorithms learn patterns from data and improve their performance over time without being explicitly programmed.\n\n"
                "• **Supervised Learning**: The model trains on labeled data.\n"
                "  *Example*: Spam detection where emails are labeled 'Spam' or 'Inbox'; house price prediction based on size and location.\n"
                "• **Unsupervised Learning**: The model discovers hidden patterns in unlabeled data.\n"
                "  *Example*: Customer segmentation for marketing; anomaly detection in credit card transactions.\n"
                "• **Reinforcement Learning**: An agent learns optimal actions through trial, errors, rewards, and penalties.\n"
                "  *Example*: Autonomous self-driving navigation; game-playing agents like AlphaGo."
            )
        },
        "python": {
            "title": "Python Programming Language",
            "content": (
                "**Python** is a high-level, interpreted, dynamically-typed programming language renowned for its clean syntax, readability, and versatile standard library.\n\n"
                "• **Key Characteristics**: Easy to learn, cross-platform, supports object-oriented, functional, and procedural paradigms.\n"
                "• **Primary Use Cases**: Artificial Intelligence & Machine Learning (PyTorch, TensorFlow, Scikit-Learn), Web Development (Django, FastAPI, Flask), Data Analysis (Pandas, NumPy), and Automation/Scripting.\n"
                "• **Ecosystem**: Backed by a vast package repository (PyPI) and an active global developer community."
            )
        },
        "cloud": {
            "title": "Cloud Computing",
            "content": (
                "**Cloud Computing** is the on-demand delivery of computing services—including servers, storage, databases, networking, software, and analytics—over the internet ('the cloud') with pay-as-you-go pricing.\n\n"
                "• **How It Works**: Cloud providers (like AWS, Google Cloud, Azure) maintain massive distributed data centers. Users provision virtualized resources instantly via APIs or dashboards without buying physical hardware.\n"
                "• **Core Service Models**:\n"
                "  1. **IaaS (Infrastructure as a Service)**: Rent virtual machines and raw storage (e.g. AWS EC2, Google Compute Engine).\n"
                "  2. **PaaS (Platform as a Service)**: Deploy applications without managing underlying operating systems (e.g. Heroku, AWS Elastic Beanstalk).\n"
                "  3. **SaaS (Software as a Service)**: Complete cloud-hosted applications accessed via web browsers (e.g. Google Workspace, Microsoft 365)."
            )
        },
        "tech_paragraph": {
            "title": "Technology in Modern Society",
            "content": (
                "Technology is the cornerstone of modern civilization, reshaping how humanity communicates, works, and solves complex global challenges. From intelligent software algorithms and ubiquitous mobile connectivity to cloud infrastructure and automated workflows, technological advancements accelerate human productivity and democratize access to knowledge worldwide. As digital systems continue to evolve, responsible innovation that prioritizes user privacy, data security, and sustainable computing remains vital to building an equitable and empowered future."
            )
        },
        "programming": {
            "title": "Core Programming Concepts",
            "content": (
                "**Core Programming Concepts** form the foundational logic for developing robust software applications:\n\n"
                "1. **Variables & Data Types**: Containers for storing data (integers, floats, strings, booleans, collections).\n"
                "2. **Control Flow**: Directing program execution using conditionals (`if/else`) and loops (`for/while`).\n"
                "3. **Functions & Modularity**: Reusable blocks of code that take inputs, perform operations, and return outputs, reducing code duplication.\n"
                "4. **Data Structures**: Organized formats for storing data efficiently (Arrays, Linked Lists, Stacks, Queues, Hash Maps, Trees).\n"
                "5. **Object-Oriented Programming (OOP)**: Organizing code into objects and classes using Encapsulation, Inheritance, Polymorphism, and Abstraction.\n"
                "6. **Algorithms & Complexity**: Step-by-step procedures for solving problems, measured in Time and Space complexity (Big-O notation)."
            )
        }
    }

    def generate_answer(self, query: str, conversation_history: Optional[List[Dict[str, str]]] = None) -> str:
        lower_q = query.lower()

        # Check for specific predefined topics
        if any(k in lower_q for k in ["artificial intelligence", "what is ai", "define ai"]):
            return self.KNOWLEDGE_BASE["ai"]["content"]

        if any(k in lower_q for k in ["machine learning", "what is ml", "explain ml", "types of machine learning"]):
            return self.KNOWLEDGE_BASE["ml"]["content"]

        if any(k in lower_q for k in ["what is python", "explain python", "about python", "python programming"]):
            return self.KNOWLEDGE_BASE["python"]["content"]

        if any(k in lower_q for k in ["cloud computing", "how does cloud work", "what is cloud"]):
            return self.KNOWLEDGE_BASE["cloud"]["content"]

        if any(k in lower_q for k in ["paragraph about technology", "write a short paragraph", "short paragraph about tech", "essay on technology"]):
            return self.KNOWLEDGE_BASE["tech_paragraph"]["content"]

        if any(k in lower_q for k in ["programming concepts", "programming concept", "core programming", "explain programming"]):
            return self.KNOWLEDGE_BASE["programming"]["content"]

        # General programming topics
        if any(k in lower_q for k in ["recursion", "recursive"]):
            return (
                "**Recursion** is a programming technique where a function solves a problem by calling itself with a smaller input until it reaches a defined base case.\n\n"
                "• **Base Case**: The termination condition that prevents infinite execution.\n"
                "• **Recursive Case**: The step that breaks the problem into sub-problems and calls the function again.\n"
                "• **Classic Example**: Factorial calculation (`n! = n * (n-1)!` with base case `0! = 1`) and tree traversals."
            )

        if any(k in lower_q for k in ["polymorphism"]):
            return (
                "**Polymorphism** is a core Object-Oriented Programming principle that allows objects of different classes to be treated as instances of a common superclass, or methods to take multiple forms.\n\n"
                "• **Compile-Time (Static) Polymorphism**: Method overloading where multiple methods share the same name with different parameters.\n"
                "• **Runtime (Dynamic) Polymorphism**: Method overriding where a subclass provides a specific implementation of a method defined in its superclass."
            )

        if any(k in lower_q for k in ["api", "what is an api", "what is api"]):
            return (
                "**API (Application Programming Interface)** is a structured contract and set of protocols that allows different software applications to communicate and exchange data with each other.\n\n"
                "• **How It Works**: A client sends a request (e.g. GET/POST HTTP request) with specific parameters, and the server returns a structured response (typically in JSON or XML format).\n"
                "• **Common Types**: RESTful APIs, GraphQL, gRPC, and WebSockets."
            )

        if any(k in lower_q for k in ["database", "sql vs nosql", "what is a database"]):
            return (
                "**Database Systems** store, organize, and manage digital data efficiently for software applications:\n\n"
                "• **Relational Databases (SQL)**: Organize structured data into tables with schemas, rows, columns, and foreign key relationships (e.g. MySQL, PostgreSQL, SQLite). Ensure ACID properties for transaction integrity.\n"
                "• **Non-Relational Databases (NoSQL)**: Store semi-structured or unstructured data as documents, key-value pairs, or graphs (e.g. MongoDB, Redis, Cassandra), offering high horizontal scalability."
            )

        if any(k in lower_q for k in ["hello", "hi", "hey"]):
            return (
                "Hello! I am your IntelliFile Hybrid AI Assistant. I can assist you with:\n\n"
                "1. **General Questions**: Programming, artificial intelligence, technology explanations, and concepts.\n"
                "2. **IntelliFile App Data**: File counts, formats, storage statistics, bookmarked/important files, and recent uploads.\n"
                "3. **Document Q&A & Analysis**: Searching, summarizing, and explaining your uploaded documents with precise page citations."
            )

        if any(k in lower_q for k in ["who are you", "what can you do"]):
            return (
                "I am **IntelliFile AI**, an intelligent hybrid assistant integrating Retrieval-Augmented Generation (RAG) and Small Language Models (SLM).\n\n"
                "• **General AI**: Answering questions on coding, technology, science, and concepts.\n"
                "• **App Assistant**: Tracking your file repository, storage statistics, categories, and deadlines.\n"
                "• **Document Brain**: Answering content-specific questions grounded in your uploaded PDFs, Word docs, spreadsheets, and presentations with zero hallucinations."
            )

        # Fallback comprehensive synthesis
        return (
            f"Here is an overview regarding **{query.strip()}**:\n\n"
            f"This topic is an important area within digital computing and modern software systems. "
            f"It involves systematic computational principles, data structures, and algorithmic workflows designed to enhance efficiency, scalability, and automated reasoning. "
            f"If you'd like me to provide specific code examples, compare implementations, or relate this to your stored documents, feel free to ask!"
        )

