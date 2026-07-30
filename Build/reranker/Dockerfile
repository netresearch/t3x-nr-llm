# Cross-encoder reranker sidecar for nr_llm (ADR-075).
# CPU image; the model is baked in at build time so the container starts offline.
FROM python:3.14-slim

ENV HF_HOME=/models \
    RERANKER_MODEL=BAAI/bge-reranker-v2-m3 \
    RERANKER_DEVICE=cpu \
    RERANKER_PORT=8081 \
    PIP_NO_CACHE_DIR=1

WORKDIR /app

COPY requirements.txt requirements.lock ./
# torch CPU wheels from the dedicated index keep the image off the CUDA stack.
# The model is pre-downloaded into the image so runtime needs no network / HF
# token; /models is handed to the unprivileged runtime user for HF lock files.
#
# --only-binary :all: refuses source distributions, so no dependency's setup.py
# executes at build time. Verified: the whole resolved tree publishes wheels.
# --require-hashes makes pip refuse any artifact whose digest is not listed in
# requirements.lock, which pins all 40 transitive packages by version AND sha256
# — so a rebuild installs byte-for-byte what was resolved and reviewed, not
# whatever the index serves that day.
RUN pip install --require-hashes --only-binary :all: --extra-index-url https://download.pytorch.org/whl/cpu -r requirements.lock \
    && python -c "from sentence_transformers import CrossEncoder; \
        CrossEncoder('BAAI/bge-reranker-v2-m3', max_length=512, device='cpu')" \
    && useradd --create-home reranker \
    && chown -R reranker:reranker /models

COPY app.py .

USER reranker

EXPOSE 8081
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD python -c "import urllib.request,sys; \
        sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8081/health').status==200 else 1)"

CMD ["python", "app.py"]
