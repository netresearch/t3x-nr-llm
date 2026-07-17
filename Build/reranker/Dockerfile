# Cross-encoder reranker sidecar for nr_llm (ADR-075).
# CPU image; the model is baked in at build time so the container starts offline.
FROM python:3.14-slim

ENV HF_HOME=/models \
    RERANKER_MODEL=BAAI/bge-reranker-v2-m3 \
    RERANKER_DEVICE=cpu \
    RERANKER_PORT=8081 \
    PIP_NO_CACHE_DIR=1

WORKDIR /app

COPY requirements.txt .
# torch CPU wheels from the dedicated index keep the image off the CUDA stack.
RUN pip install --extra-index-url https://download.pytorch.org/whl/cpu -r requirements.txt

# Pre-download the model into the image so runtime needs no network / HF token.
RUN python -c "from sentence_transformers import CrossEncoder; CrossEncoder('BAAI/bge-reranker-v2-m3', max_length=512, device='cpu')"

COPY app.py .

EXPOSE 8081
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8081/health').status==200 else 1)"

CMD ["python", "app.py"]
