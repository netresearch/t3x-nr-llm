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
| `RERANKER_PORT` | `8081` | listen port |
| `RERANKER_MAX_LENGTH` | `512` | tokenizer max length |
| `RERANKER_MAX_DOCUMENTS` | `128` | reject oversized batches |
