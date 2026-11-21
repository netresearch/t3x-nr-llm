# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
#
# a2a-python 1.3.0, the interop partner of the A2A client (spec 051 A2A-S0-5).
# The versions are pinned: the job measures nr-llm against this release.
# Wheels only, so no package runs a setup script at build time; the partner
# runs as an unprivileged user.
FROM python:3.15-slim
RUN pip install --no-cache-dir --only-binary :all: "a2a-sdk[http-server,fastapi]==1.3.0" "uvicorn==0.54.0" \
    && useradd --system --no-create-home --uid 10001 a2a
COPY interop_agent.py /app/interop_agent.py
USER a2a
EXPOSE 18120
ENTRYPOINT ["python", "-I", "/app/interop_agent.py"]
