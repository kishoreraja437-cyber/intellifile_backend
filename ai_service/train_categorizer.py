import os
import joblib
import sys
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.naive_bayes import MultinomialNB
from sklearn.pipeline import Pipeline

# Use the centralized expanded training dataset from standalone_nlp
sys.path.insert(0, os.path.dirname(__file__))
from standalone_nlp import (
    TRAINING_DATA,
    SENSITIVE_CATEGORIES,
    detect_rule_based_category,
    score_priority
)

def train_and_save_categorizer():
    texts, labels = zip(*TRAINING_DATA)
    
    pipeline = Pipeline([
        ('tfidf', TfidfVectorizer(ngram_range=(1, 2), min_df=1, sublinear_tf=True)),
        ('clf', MultinomialNB(alpha=0.1))
    ])

    pipeline.fit(texts, labels)
    
    models_dir = os.path.join(os.path.dirname(__file__), "models")
    os.makedirs(models_dir, exist_ok=True)
    
    model_path = os.path.join(models_dir, "categorizer_pipeline.pkl")
    joblib.dump(pipeline, model_path)
    print(f"[OK] Categorizer model trained and saved to: {model_path}")
    return pipeline

if __name__ == "__main__":
    train_and_save_categorizer()
    print("[OK] Model training complete with expanded Indian & Global document dataset.")
