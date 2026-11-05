"""Embedding models we support. Both are multilingual (incl. Hebrew) and 384-dim,
matching the vector(384) columns in db/schema.sql."""

MODELS = {
    # Trained for retrieval (query vs. passage). Needs "query: " / "passage: " prefixes.
    "e5-small": {
        "name": "intfloat/multilingual-e5-small",
        "hf_repo": "Xenova/multilingual-e5-small",
        "model_file": "onnx/model_quantized.onnx",
        "files": ["onnx/model_quantized.onnx", "tokenizer.json", "config.json",
                  "special_tokens_map.json", "tokenizer_config.json"],
        "custom": True,
        "prefix": {"query": "query: ", "passage": "passage: "},
    },
    # Trained for paraphrase similarity; supported by fastembed out of the box.
    "minilm": {
        "name": "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2",
        "hf_repo": "Qdrant/paraphrase-multilingual-MiniLM-L12-v2-onnx-Q",
        "files": None,  # whole repo (small)
        "custom": False,
        "prefix": {"query": "", "passage": ""},
    },
}
