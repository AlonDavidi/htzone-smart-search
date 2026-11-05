"""
Downloads the embedding model files into ./models/<key>/ (git-ignored).

Usage:  python embedder/download_models.py            # all models
        python embedder/download_models.py e5-small   # one model

In Docker this runs at build time, so the image ships with the model.
"""
import sys
from pathlib import Path

from huggingface_hub import snapshot_download

sys.path.insert(0, str(Path(__file__).parent))
from models import MODELS  # noqa: E402

ROOT = Path(__file__).resolve().parent.parent / "models"

for key in sys.argv[1:] or MODELS:
    spec = MODELS[key]
    target = ROOT / key
    print(f"Downloading {spec['hf_repo']} -> {target}")
    snapshot_download(repo_id=spec["hf_repo"], local_dir=target, allow_patterns=spec["files"])

print("Done.")
