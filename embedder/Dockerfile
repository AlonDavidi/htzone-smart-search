# Embedding service (FastAPI + ONNX). The model is downloaded at build time,
# so the running container needs no network access to HuggingFace.
# Deployed to a Hugging Face Space (Docker SDK), which expects port 7860.
FROM python:3.12-slim

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY models.py download_models.py app.py ./
ENV MODELS_DIR=/app/models EMBED_MODEL=minilm
RUN python download_models.py minilm

EXPOSE 7860
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "7860"]
