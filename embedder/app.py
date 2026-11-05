"""
Embedding sidecar: turns text into 384-dim vectors with a local ONNX model.
No external API, no keys. The PHP app calls it over HTTP.

  POST /embed  {"texts": [...], "kind": "query" | "passage"}  -> {"vectors": [[...], ...]}
  GET  /health

Env: EMBED_MODEL = e5-small (default) | minilm
     MODELS_DIR  = directory with downloaded models (default ../models)
"""
import os
import sys
from pathlib import Path
from typing import Literal

from fastapi import FastAPI, HTTPException
from fastembed import TextEmbedding
from fastembed.common.model_description import ModelSource, PoolingType
from pydantic import BaseModel, Field

sys.path.insert(0, str(Path(__file__).parent))
from models import MODELS  # noqa: E402

MODEL_KEY = os.getenv("EMBED_MODEL", "e5-small")
MODELS_DIR = Path(os.getenv("MODELS_DIR", Path(__file__).resolve().parent.parent / "models"))
SPEC = MODELS[MODEL_KEY]

if SPEC["custom"]:
    TextEmbedding.add_custom_model(
        model=SPEC["name"],
        pooling=PoolingType.MEAN,
        normalization=True,
        sources=ModelSource(hf=SPEC["hf_repo"]),
        dim=384,
        model_file=SPEC["model_file"],
    )

model = TextEmbedding(SPEC["name"], specific_model_path=str(MODELS_DIR / MODEL_KEY))
app = FastAPI(title="embedder")


class EmbedRequest(BaseModel):
    texts: list[str] = Field(min_length=1, max_length=256)
    kind: Literal["query", "passage"] = "query"


@app.post("/embed")
def embed(req: EmbedRequest) -> dict:
    prefix = SPEC["prefix"][req.kind]
    try:
        vectors = model.embed([prefix + t for t in req.texts], batch_size=32)
        return {"model": MODEL_KEY, "vectors": [v.round(6).tolist() for v in vectors]}
    except Exception as e:  # keep the service up; PHP falls back to lexical search
        raise HTTPException(status_code=500, detail=str(e))


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "model": MODEL_KEY}
