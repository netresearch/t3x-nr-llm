# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
#
# a2a-python 1.3.0, the interop partner of the A2A client (spec 051 A2A-S0-5).
# The versions are pinned: the job measures nr-llm against this release.
# pyproject.toml and uv.lock pin the whole tree with the sha256 of every wheel;
# wheels only, so no package runs a setup script at build time. The partner
# runs as an unprivileged user.
FROM ghcr.io/astral-sh/uv:0.13.1@sha256:4a1f3666816dafadd20ceb71eac9a7d56d7ef30279f7d64048519ab901ada336 AS uv

FROM python:3.12-slim@sha256:5e2bdb1b0d542d5039cffb8181c65930093949194a65c365dfb1b0fe13a4fa5b
ENV UV_PYTHON_DOWNLOADS=never \
    UV_NO_CACHE=1 \
    UV_LINK_MODE=copy \
    UV_PROJECT_ENVIRONMENT=/opt/venv \
    PATH=/opt/venv/bin:$PATH
COPY pyproject.toml uv.lock /opt/partner/
RUN --mount=from=uv,source=/uv,target=/bin/uv \
    uv sync --locked --no-dev --project /opt/partner \
    && useradd --system --no-create-home --uid 10001 a2a
COPY interop_agent.py /app/interop_agent.py
USER a2a
EXPOSE 18120
ENTRYPOINT ["python", "-I", "/app/interop_agent.py"]
