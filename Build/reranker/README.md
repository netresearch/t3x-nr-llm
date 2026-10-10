<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Cross-encoder reranker sidecar (ADR-075)

A minimal HTTP service that rescores `(query, passage)` pairs with a
cross-encoder (default `BAAI/bge-reranker-v2-m3`). Consumers retrieve
candidates elsewhere (bi-encoder recall) and call this service via
`nr_llm`'s `Netresearch\NrLlm\Service\Rerank\RerankerInterface` to reorder
them by relevance (cross-encoder precision).

On the real BMDV corpus a cross-encoder lifted top-1 accuracy from 6/9 to 8/9
where a bi-encoder alone and a naive LLM reranker did not (NRFE-3960,
nr_ai_search ADR-029).

## API

```
POST /rerank   {"query": "...", "documents": [{"id": "...", "text": "..."}]}
            -> {"scores": [{"id": "...", "score": 0.87}, ...]}   # input order
GET  /health   -> {"status": "ok", "model": "..."}
```

The request must be a JSON object with a nonempty string `query` and an array
`documents`. Every document must contain string `id` and `text` fields. An empty
document array returns an empty score array without calling the model. Scores
retain the input order; the consumer sorts them. Scores must be finite numbers.

Malformed JSON or an invalid request shape returns HTTP 400. A batch exceeding
`RERANKER_MAX_DOCUMENTS` or a body exceeding `RERANKER_MAX_BODY_BYTES` returns
HTTP 413. An inference failure or a non-finite model score returns HTTP 500 with
valid JSON. Bodies rejected before reading
close the connection; other error paths drain the body and permit another
request on the same connection.

## Run

Container (model baked in at build time, starts offline):

```bash
docker build -t nr-llm-reranker Build/reranker
docker run -p 8081:8081 nr-llm-reranker
```

Local (needs Python + the model download on first run):

```bash
pip install -r Build/reranker/requirements.txt
python Build/reranker/app.py            # listens on :8081
```

## Configure nr_llm

Set the extension configuration:

- `rerankerEndpoint` — the sidecar base URL, e.g. `http://reranker:8081`
  (empty = disabled; consumers get the input-order `NullReranker`).
- `rerankerTimeout` — request timeout in seconds (default 30; a
  cross-encoder on CPU can be slow for a wide candidate pool).

A failed or unreachable sidecar surfaces as a typed `RerankerException` —
each consumer owns its degradation policy (e.g. fall back to the pre-rerank
ordering). Score-threshold gates are consumer-side; the score scale is
model-specific.

## Environment

| Variable | Default | Purpose |
|---|---|---|
| `RERANKER_MODEL` | `BAAI/bge-reranker-v2-m3` | cross-encoder model |
| `RERANKER_DEVICE` | `cpu` | `cpu` or `cuda` |
| `RERANKER_HOST` | `0.0.0.0` | bind address; deploy on a private network |
| `RERANKER_PORT` | `8081` | listen port |
| `RERANKER_MAX_LENGTH` | `512` | tokenizer max length |
| `RERANKER_MAX_DOCUMENTS` | `128` | reject oversized batches |
| `RERANKER_MAX_BODY_BYTES` | `16777216` | maximum request body size |

## Test the HTTP contract

```bash
python -m unittest discover -s Build/reranker -p test_app.py -v
```

The tests start the actual HTTP handler on a local ephemeral port and replace
only the cross-encoder with a deterministic stub. They need the Python standard
library, download no model and check validation, batching, input order, finite
JSON scores, error responses and connection handling. Separate settings tests
check the documented defaults and configured values at their consumers. CI runs
them under Python 3.14, matching the
container. They do not measure the model's ranking quality or prove the
historical corpus result above.
