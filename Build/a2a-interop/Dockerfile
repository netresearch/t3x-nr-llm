# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
#
# a2a-python 1.3.0, the interop partner of the A2A client (spec 051 A2A-S0-5).
# The versions are pinned: the job measures nr-llm against this release.
FROM python:3.12-slim
RUN pip install --no-cache-dir "a2a-sdk[http-server,fastapi]==1.3.0" "uvicorn==0.54.0"
COPY interop_agent.py /app/interop_agent.py
EXPOSE 18120
ENTRYPOINT ["python", "-I", "/app/interop_agent.py"]
