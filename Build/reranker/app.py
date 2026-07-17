"""Cross-encoder reranker sidecar for nr_llm (ADR-075).

A minimal HTTP service that rescores (query, passage) pairs with a cross-encoder
(default BAAI/bge-reranker-v2-m3). Consumers retrieve candidates elsewhere
(bi-encoder recall) and call this service via nr_llm's RerankerInterface to
reorder them by relevance (cross-encoder precision) — the lever measured for
NRFE-3960: on the real BMDV corpus a cross-encoder lifted top-1 accuracy from
6/9 to 8/9 where a bi-encoder alone and a naive LLM reranker did not.

Stdlib only (http.server) so the container needs no web framework; the single
heavy dependency is sentence-transformers + torch (CPU).

Request:  POST /rerank  {"query": "...", "documents": [{"id": "...", "text": "..."}]}
Response:            {"scores": [{"id": "...", "score": 0.87}, ...]}   (input order)
Health:   GET  /health -> {"status": "ok", "model": "..."}
"""

from __future__ import annotations

import json
import logging
import os
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from sentence_transformers import CrossEncoder

MODEL_NAME = os.environ.get("RERANKER_MODEL", "BAAI/bge-reranker-v2-m3")
MAX_LENGTH = int(os.environ.get("RERANKER_MAX_LENGTH", "512"))
DEVICE = os.environ.get("RERANKER_DEVICE", "cpu")
HOST = os.environ.get("RERANKER_HOST", "0.0.0.0")
PORT = int(os.environ.get("RERANKER_PORT", "8081"))
MAX_DOCUMENTS = int(os.environ.get("RERANKER_MAX_DOCUMENTS", "128"))
MAX_BODY = int(os.environ.get("RERANKER_MAX_BODY_BYTES", str(16 * 1024 * 1024)))

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("reranker")

log.info("loading cross-encoder %s on %s ...", MODEL_NAME, DEVICE)
_model = CrossEncoder(MODEL_NAME, max_length=MAX_LENGTH, device=DEVICE)
log.info("model ready")


def _score(query: str, documents: list[dict]) -> list[dict]:
    pairs = [(query, str(d.get("text", ""))) for d in documents]
    # show_progress_bar=False: predict() otherwise renders a tqdm bar to stderr per
    # request when the log level is INFO. batch_size is explicit for predictable batching.
    scores = _model.predict(pairs, batch_size=32, show_progress_bar=False)
    return [{"id": documents[i].get("id"), "score": float(scores[i])} for i in range(len(documents))]


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def _send(self, code: int, payload: dict) -> None:
        body = json.dumps(payload).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args) -> None:  # quieter default logging
        return

    def do_GET(self) -> None:
        if self.path == "/health":
            self._send(200, {"status": "ok", "model": MODEL_NAME})
            return
        self._send(404, {"error": "not found"})

    def do_POST(self) -> None:
        # Read (drain) the body before branching so every exit path leaves the
        # HTTP/1.1 keep-alive connection in sync; guard the size before reading.
        try:
            length = int(self.headers.get("Content-Length", "0"))
        except ValueError:
            self.close_connection = True
            self._send(400, {"error": "invalid Content-Length"})
            return
        if length < 0 or length > MAX_BODY:
            self.close_connection = True  # body left undrained -> must close
            self._send(413, {"error": f"body too large (max {MAX_BODY} bytes)"})
            return
        body = self.rfile.read(length) if length > 0 else b""

        if self.path != "/rerank":
            self._send(404, {"error": "not found"})
            return
        try:
            data = json.loads(body or b"{}")
        except (ValueError, json.JSONDecodeError):
            self._send(400, {"error": "invalid JSON body"})
            return

        query = data.get("query")
        documents = data.get("documents")
        if not isinstance(query, str) or query == "" or not isinstance(documents, list):
            self._send(400, {"error": "expected {query: string, documents: [{id, text}]}"})
            return
        if len(documents) > MAX_DOCUMENTS:
            self._send(413, {"error": f"too many documents (max {MAX_DOCUMENTS})"})
            return
        if documents == []:
            self._send(200, {"scores": []})
            return

        try:
            self._send(200, {"scores": _score(query, documents)})
        except Exception as exc:  # keep the service alive on a bad batch
            log.exception("rerank failed")
            self._send(500, {"error": f"rerank failed: {exc}"})


def main() -> None:
    server = ThreadingHTTPServer((HOST, PORT), Handler)
    log.info("listening on %s:%d", HOST, PORT)
    server.serve_forever()


if __name__ == "__main__":
    main()
