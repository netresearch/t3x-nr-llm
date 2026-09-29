# Local decision sidecar for nr_llm (NLI zero-shot classifier).
# CPU image; the model is baked in at build time so the container starts offline.
# Pinned by digest, like the packages by hash: a rebuild uses the base that
# was reviewed. Renovate proposes digest updates.
FROM python:3.14-slim@sha256:51dafde81dbdb6ebde285137a295cf18a47ca95234fe388a343719cb97305b3d

ENV HF_HOME=/models \
    DECISION_MODEL=MoritzLaurer/mDeBERTa-v3-base-xnli-multilingual-nli-2mil7 \
    DECISION_MODEL_REVISION=b5113eb38ab63efdd7f280f8c144ea8b13f978ce \
    DECISION_DEVICE=cpu \
    DECISION_PORT=8082 \
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
# requirements.lock, which pins all 34 transitive packages by version AND sha256
# — so a rebuild installs byte-for-byte what was resolved and reviewed, not
# whatever the index serves that day.
#
# The pre-download reads DECISION_MODEL and DECISION_MODEL_REVISION from the
# ENV above, so the baked weights and the ones app.py loads cannot diverge;
# change the model (and its pinned commit) there.
RUN pip install --require-hashes --only-binary :all: --extra-index-url https://download.pytorch.org/whl/cpu -r requirements.lock \
    && python -c "import os; from transformers import pipeline; \
        pipeline('zero-shot-classification', model=os.environ['DECISION_MODEL'], revision=os.environ['DECISION_MODEL_REVISION'], device='cpu')" \
    && useradd --create-home decision \
    && chown -R decision:decision /models

COPY app.py .

USER decision

# The model is in the image; never reach out to the Hub at runtime.
ENV HF_HUB_OFFLINE=1

EXPOSE 8082
# The probe follows DECISION_PORT.
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD ["python", "app.py", "--healthcheck"]

CMD ["python", "app.py"]
