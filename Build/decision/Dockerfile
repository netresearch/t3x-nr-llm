# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH

# Local decision sidecar for nr_llm (NLI zero-shot classifier).
# CPU image; the model is baked in at build time so the container starts offline.
# Pinned by digest, like the packages by hash: a rebuild uses the base that
# was reviewed. Renovate proposes digest updates.

# uv installs the locked dependencies. It is bind-mounted into the RUN below
# and never copied, so it is not part of the final image.
FROM ghcr.io/astral-sh/uv:0.13.1@sha256:4a1f3666816dafadd20ceb71eac9a7d56d7ef30279f7d64048519ab901ada336 AS uv

FROM python:3.14-slim@sha256:0514bc76712d64f8bb48adcb95b1ab0d88c69fee95d62fe4c18ee948bee9b1e1

ENV HF_HOME=/models \
    DECISION_MODEL=MoritzLaurer/mDeBERTa-v3-base-xnli-multilingual-nli-2mil7 \
    DECISION_MODEL_REVISION=b5113eb38ab63efdd7f280f8c144ea8b13f978ce \
    DECISION_DEVICE=cpu \
    DECISION_PORT=8082 \
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
#
# The pre-download reads DECISION_MODEL and DECISION_MODEL_REVISION from the
# ENV above, so the baked weights and the ones app.py loads cannot diverge;
# change the model (and its pinned commit) there.
RUN --mount=from=uv,source=/uv,target=/bin/uv \
    uv sync --locked --no-dev \
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
