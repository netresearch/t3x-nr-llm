# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH

# Cross-encoder reranker sidecar for nr_llm (ADR-075).
# CPU image; the model is baked in at build time so the container starts offline.

# uv installs the locked dependencies. It is bind-mounted into the RUN below
# and never copied, so it is not part of the final image. Pinned by digest, like
# the packages by hash; Renovate proposes digest updates.
FROM ghcr.io/astral-sh/uv:0.13.1@sha256:4a1f3666816dafadd20ceb71eac9a7d56d7ef30279f7d64048519ab901ada336 AS uv

# Pinned by digest, like the decision sidecar: a rebuild uses the base that was
# reviewed. Renovate proposes digest updates; renovate.json keeps it below 3.15.
FROM python:3.14-slim@sha256:0514bc76712d64f8bb48adcb95b1ab0d88c69fee95d62fe4c18ee948bee9b1e1

ENV HF_HOME=/models \
    RERANKER_MODEL=BAAI/bge-reranker-v2-m3 \
    RERANKER_DEVICE=cpu \
    RERANKER_PORT=8081 \
    UV_PYTHON_DOWNLOADS=never \
    UV_NO_CACHE=1 \
    UV_LINK_MODE=copy \
    PATH=/app/.venv/bin:$PATH

WORKDIR /app

COPY pyproject.toml uv.lock ./
# torch CPU wheels come from the dedicated index (pyproject.toml routes torch
# there), which keeps the image off the CUDA stack. The model is pre-downloaded
# into the image so runtime needs no network / HF token; /models is handed to
# the unprivileged runtime user for HF lock files.
#
# `uv sync --locked` installs exactly the tree in uv.lock into /app/.venv and
# refuses to run when uv.lock no longer matches pyproject.toml. uv checks every
# downloaded wheel against the sha256 recorded in uv.lock, so a rebuild installs
# byte-for-byte what was resolved and reviewed, not whatever the index serves
# that day. `no-build = true` in pyproject.toml refuses source distributions, so
# no dependency's setup.py executes at build time. UV_PYTHON_DOWNLOADS=never
# makes uv use this image's interpreter instead of fetching its own.
RUN --mount=from=uv,source=/uv,target=/bin/uv \
    uv sync --locked --no-dev \
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
