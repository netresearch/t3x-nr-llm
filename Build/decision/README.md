<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Local decision sidecar

A minimal HTTP service that answers structured questions about a state — a
task, a candidate, evidence — with an NLI model used as a zero-shot classifier
(default `MoritzLaurer/mDeBERTa-v3-base-xnli-multilingual-nli-2mil7`, MIT
licence, multilingual including German). It is the local, free counterpart to
a paid decision API: `nr_llm` can be tested against it without an API key and
without data leaving the machine.

Stdlib `http.server` only; the one heavy dependency is `transformers` + `torch`
(CPU), imported lazily when the model loads.

## API

```
POST /decide
  {"state": {"task"?: "...", "candidate"?: "...", "evidence"?: ["...", ...]},
   "questions": {
     "<key>": {"type": "yes_no", "instructions": "...", "yes"?: "...", "no"?: "..."}
            | {"type": "choice", "instructions": "...", "options": ["a", "b", ...],
               "descriptions"?: {"a": "..."}}
            | {"type": "score", "instructions": "...", "levels": ["low", ..., "high"]}}}
-> 200
  {"model": "<model id actually loaded>",
   "answers": {
     "<key>": {"type": "yes_no", "probability_of_yes": 0.83}
            | {"type": "choice", "choice": "b", "probabilities": {"a": 0.1, "b": 0.7, ...}}
            | {"type": "score", "score": 1.4, "probabilities": {"0": 0.1, "1": 0.4, "2": 0.5}}},
   "usage": {"input_tokens": 798}}

GET /health  -> {"status": "ok", "model": "<id>"}
GET /models  -> {"models": [{"name": "<id>"}]}
```

Every question key in the request has an answer. `choice.probabilities` holds
every option, keyed exactly as given, summing to ~1; `choice` is the argmax
(the first option wins a tie). `score.probabilities` is keyed `"0"` ..
`"n-1"`; `score` is the probability-weighted level `sum(i * p_i)`, a float in
`[0, n-1]`. There is deliberately no confidence field.

`usage.input_tokens` counts the tokens of every premise/hypothesis pair the
request sent through the model, tokenised as the pipeline does: special tokens
included, premise truncated to the model's 512-token limit, no padding.

### Errors

All error bodies are `{"detail": "<message>"}`.

| Status | When |
|---|---|
| 422 | invalid JSON; body not an object; `state` not an object, unknown field in it, `task`/`candidate` not a string, `evidence` not a list of strings, or no non-empty field at all; `questions` not an object, empty, or more than `DECISION_MAX_QUESTIONS`; a question that is not an object, has a missing or unknown `type`, empty `instructions`, a non-string or empty `yes`/`no`; `choice` with fewer than 2 or more than 255 options, duplicate or empty options, `descriptions` naming an unknown option, or option + description rendering to the same label as another option; `score` with fewer than 2 or more than 10 levels, duplicate or empty levels; more than `DECISION_MAX_LABELS` candidate labels across all questions |
| 413 | body larger than `DECISION_MAX_BODY_BYTES` |
| 404 | unknown path |
| 400 | unparseable `Content-Length` header |
| 500 | inference failed (`{"detail": "inference failed"}`, the cause is logged), or the model is not loaded |
| 503 | more than `DECISION_MAX_PENDING` requests already wait for the model |

Two rules go beyond "duplicate options": levels must be unique too, and an
empty `state` is rejected. Both exist because answers are mapped back by label
text, and because a premise without content makes every answer meaningless.

## How the question types map onto NLI

- **Premise** — the state as plain text with labelled sections in a fixed
  order, only present (non-blank) fields: `Task: …`, `Candidate: …`,
  `Evidence 1: …`, `Evidence 2: …`. Every question of a request sees the same
  premise.
- **Hypothesis** — `<instructions> Answer: <label>`. The instructions become
  the pipeline's `hypothesis_template` (braces escaped), so the same state can
  be asked different questions.
- Each question is one `zero-shot-classification` call with
  `multi_label=False`: the entailment logits of its labels are softmaxed
  against each other. Its hypotheses run through the model in batches of up
  to `DECISION_BATCH_SIZE` pairs (the softmax still spans all labels), which
  bounds memory for a 255-option choice.
- **yes_no** — labels `yes` / `no`, or `yes: <yes>` / `no: <no>` when the
  optional descriptions are given; `probability_of_yes` is the normalised
  share of `yes`.
- **choice** — one label per option, `<option>: <description>` when a
  description is given.
- **score** — the levels, in order, are the labels (index 0 is the lowest).

## Quality

An NLI zero-shot model is a local/test decision model, not a replacement for the paid
decision API. Its probabilities are **not calibrated** — the caller must treat
them as raw model probabilities, and any threshold has to be measured per
decision profile against labelled examples, never assumed.

A first smoke run on German content showed how much the phrasing matters: with
bare `yes`/`no` labels the model leans towards "yes" (a question about prices
the candidate never mentions scored about 0.5), and it named the language of a
German sentence "English". Declarative `yes`/`no` descriptions ("The candidate
answers the task.") and option descriptions give the model a proposition to
test and are the first lever to try. Measure per profile.

Results are deterministic for a given request, but they depend slightly on how
the pairs are batched, because padded batches do not produce bit-identical
logits: for a 40-option choice, batch sizes 40, 8 and 1 moved individual
probabilities by up to 0.004 absolute. Keep `DECISION_BATCH_SIZE` fixed within
a measurement.

## Run

Container (model baked in at build time, starts offline):

```bash
docker build -t nr-llm-decision Build/decision
# Loopback only: the service has no authentication.
docker run -p 127.0.0.1:8082:8082 nr-llm-decision
```

In a compose setup, keep it on the internal network with TYPO3 and publish no
port at all.

Local (needs Python + the model download on first run):

```bash
pip install --extra-index-url https://download.pytorch.org/whl/cpu -r Build/decision/requirements.txt
python Build/decision/app.py            # listens on :8082
```

Tests (stdlib only, no model download):

```bash
python -m unittest Build/decision/test_app.py
Build/decision/check-lock-matches-requirements.sh
```

## Configure nr_llm

Create a Provider record of adapter type **"Local decision sidecar"** whose
endpoint points at the sidecar, e.g. `http://decision:8082` (the service name
on the container network). No API key is needed.

The endpoint host resolves to a private address, which nr-vault's SSRF filter
refuses unless it is allow-listed, as for Ollama:

```php
$GLOBALS['TYPO3_CONF_VARS']['HTTP']['allowed_hosts'][] = 'decision';
```

The sidecar speaks plain HTTP and belongs on a private container network next
to TYPO3. Anywhere wider, put a TLS-terminating reverse proxy in front of it
and point the endpoint at the proxy's `https://…` URL, as for Ollama. The
image's health check follows `DECISION_PORT`.

## Environment

| Variable | Default | Purpose |
|---|---|---|
| `DECISION_MODEL` | `MoritzLaurer/mDeBERTa-v3-base-xnli-multilingual-nli-2mil7` | NLI model (needs an `entailment` label) |
| `DECISION_DEVICE` | `cpu` | `cpu` or `cuda` |
| `DECISION_HOST` | `0.0.0.0` | listen address |
| `DECISION_PORT` | `8082` | listen port |
| `DECISION_MODEL_REVISION` | pinned commit in the image | model repository commit to load; empty loads the repository head |
| `DECISION_MAX_QUESTIONS` | `32` | reject requests with more questions |
| `DECISION_MAX_LABELS` | `512` | reject requests with more candidate labels across all questions (each is one forward pass) |
| `DECISION_MAX_PENDING` | `4` | requests that may wait for the model at once; more get 503 |
| `DECISION_BATCH_SIZE` | `32` | premise/hypothesis pairs per forward pass |
| `DECISION_MAX_BODY_BYTES` | `1048576` | reject larger bodies with 413 |
| `DECISION_CONNECTION_TIMEOUT` | `30` | seconds a connection may stay silent while a request is read before it is dropped |
