"""Local decision sidecar for nr_llm.

A minimal HTTP service that answers structured questions about a state (a task,
a candidate, evidence) with an NLI model used as a zero-shot classifier
(default MoritzLaurer/mDeBERTa-v3-base-xnli-multilingual-nli-2mil7, MIT,
multilingual incl. German). It is the local, free counterpart to a paid
decision API: nr_llm can be tested against it without a key and without data
leaving the machine.

Stdlib only (http.server) so the container needs no web framework; the single
heavy dependency is transformers + torch (CPU). Both are imported lazily inside
load_classifier(), so this module imports without them and the unit tests run
on a bare interpreter with a fake classifier injected into `_classifier`.

Request:  POST /decide
  {"state": {"task"?: str, "candidate"?: str, "evidence"?: [str, ...]},
   "questions": {"<key>": {"type": "yes_no", "instructions": str, "yes"?: str, "no"?: str}
                        | {"type": "choice", "instructions": str, "options": [str, ...],
                           "descriptions"?: {"<option>": str}}
                        | {"type": "score", "instructions": str, "levels": [str, ...]}}}
Response: {"model": "<id>",
           "answers": {"<key>": {"type": "yes_no", "probability_of_yes": p}
                              | {"type": "choice", "choice": "<option>", "probabilities": {"<option>": p}}
                              | {"type": "score", "score": s, "probabilities": {"0": p, ...}}},
           "usage": {"input_tokens": n}}
Transport: plain HTTP, for a private container network next to the consumer;
          TLS belongs to a reverse proxy in front of it, as for Ollama.
Errors:   422 {"detail": ...} validation / invalid JSON, 413 oversized body,
          404 unknown path, 400 broken Content-Length, 500 inference failure.
Health:   GET /health -> {"status": "ok", "model": "<id>"}
Models:   GET /models -> {"models": [{"name": "<id>"}]}

How the three question types map onto NLI
-----------------------------------------
Premise: the state rendered as plain text with labelled sections, in the fixed
order "Task:", "Candidate:", "Evidence 1:", "Evidence 2:" ...; absent fields are
omitted. The same premise is used for every question of a request.

Hypothesis: "<instructions> Answer: <label>" — the question's instructions
shape every hypothesis (they become the pipeline's hypothesis_template, braces
escaped), because the same state is asked different questions.

Every question is one zero-shot-classification call with multi_label=False, i.e.
the entailment logits of its candidate labels are softmaxed against each other,
so each question's probabilities sum to 1. The hypotheses of one question go
through the model in batches of up to DECISION_BATCH_SIZE (default 32) pairs;
the softmax still runs over all labels of the question. The cap bounds memory:
a choice may carry 255 options, each padded to up to 512 tokens.

- yes_no: labels "yes" / "no", or "yes: <yes>" / "no: <no>" when the optional
  descriptions are given. probability_of_yes is the normalised share of "yes".
- choice: one label per option, "<option>" or "<option>: <description>" when a
  description is given. probabilities is keyed by the option exactly as given;
  choice is the argmax (first option wins a tie).
- score: the levels are ordered candidate labels (index 0 = lowest).
  probabilities is keyed "0".."n-1"; score = sum(i * p_i), a probability-
  weighted level in [0, n-1].

No confidence field is reported: these are uncalibrated model probabilities.

usage.input_tokens counts the tokens of every premise/hypothesis pair the
request sent through the model, tokenised exactly as the pipeline does
(special tokens included, premise truncated to the model's max length, no
padding).
"""

from __future__ import annotations

import json
import logging
import os
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any, Protocol

MODEL_NAME = os.environ.get(
    "DECISION_MODEL", "MoritzLaurer/mDeBERTa-v3-base-xnli-multilingual-nli-2mil7"
)
# A pinned revision (commit) of the model repository: a rebuild then loads
# the weights that were reviewed, not whatever the repository holds that day.
MODEL_REVISION = os.environ.get("DECISION_MODEL_REVISION", "") or None
DEVICE = os.environ.get("DECISION_DEVICE", "cpu")
HOST = os.environ.get("DECISION_HOST", "0.0.0.0")
PORT = int(os.environ.get("DECISION_PORT", "8082"))
MAX_QUESTIONS = int(os.environ.get("DECISION_MAX_QUESTIONS", "32"))
# Candidate labels across all questions of one request: each is one forward
# pass, so this bounds how long one request can hold the model.
MAX_LABELS = int(os.environ.get("DECISION_MAX_LABELS", "512"))
# Requests allowed to wait for the model at once; more are turned away with
# 503 instead of queueing without bound behind one slow request.
MAX_PENDING = max(1, int(os.environ.get("DECISION_MAX_PENDING", "4")))
BATCH_SIZE = max(1, int(os.environ.get("DECISION_BATCH_SIZE", "32")))
MAX_BODY = int(os.environ.get("DECISION_MAX_BODY_BYTES", str(1024 * 1024)))
# Seconds a connection may stay silent while a request is read before its
# handler thread gives up on it.
CONNECTION_TIMEOUT = max(1, int(os.environ.get("DECISION_CONNECTION_TIMEOUT", "30")))

MIN_OPTIONS, MAX_OPTIONS = 2, 255
MIN_LEVELS, MAX_LEVELS = 2, 10
QUESTION_TYPES = ("yes_no", "choice", "score")

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("decision")


class ValidationError(ValueError):
    """A request that is well-formed JSON but violates the /decide contract (-> 422)."""


class Classifier(Protocol):
    """The seam between the HTTP layer and the model; tests inject a fake."""

    model_id: str

    def classify(
        self, premise: str, labels: list[str], template: str
    ) -> dict[str, float]:
        """Single-label zero-shot probabilities for each label (sum to 1)."""

    def count_tokens(self, pairs: list[tuple[str, str]]) -> int:
        """Tokens the model sees for these (premise, hypothesis) pairs."""


class PipelineClassifier:
    """Classifier backed by the transformers zero-shot-classification pipeline."""

    def __init__(self, pipe: Any, model_id: str) -> None:
        self._pipe = pipe
        self.model_id = model_id
        # A pipeline call stores per-call parameters on the pipeline object, and
        # the server is threaded; serialise inference. On CPU torch already uses
        # every core per forward pass, so this costs no throughput.
        self._lock = threading.Lock()

    def classify(
        self, premise: str, labels: list[str], template: str
    ) -> dict[str, float]:
        with self._lock:
            # labels as a list, never a string: the pipeline splits strings on commas.
            result = self._pipe(
                premise,
                candidate_labels=list(labels),
                hypothesis_template=template,
                multi_label=False,
                # Bounded: one unbounded batch of 255 padded pairs can exhaust
                # memory and kill the process instead of failing the request.
                batch_size=min(len(labels), BATCH_SIZE),
            )
        # The pipeline returns labels sorted by score; map back by label.
        return {
            # A float32 softmax can land an epsilon outside [0, 1].
            label: min(1.0, max(0.0, float(score)))
            for label, score in zip(result["labels"], result["scores"])
        }

    def count_tokens(self, pairs: list[tuple[str, str]]) -> int:
        if not pairs:
            return 0
        # Same truncation the pipeline applies (only_first, model max length).
        encoded = self._pipe.tokenizer(
            [list(p) for p in pairs],
            add_special_tokens=True,
            truncation="only_first",
            padding=False,
        )
        return sum(len(ids) for ids in encoded["input_ids"])


def load_classifier() -> PipelineClassifier:
    """Load the NLI model. torch/transformers are imported here, not at module level."""
    from transformers import pipeline  # lazy by design, see module docstring

    log.info("loading zero-shot model %s on %s ...", MODEL_NAME, DEVICE)
    pipe = pipeline(
        "zero-shot-classification",
        model=MODEL_NAME,
        revision=MODEL_REVISION,
        device=DEVICE,
    )
    if pipe.entailment_id == -1:
        raise RuntimeError(
            f"{MODEL_NAME} has no 'entailment' label in its config; not an NLI model"
        )
    model_id = getattr(pipe.model.config, "name_or_path", "") or MODEL_NAME
    log.info("model ready")
    return PipelineClassifier(pipe, model_id)


# Replaced by main() with the real classifier, by the tests with a fake.
_classifier: Classifier | None = None

# Slots for requests waiting for, or holding, the model.
_pending = threading.BoundedSemaphore(MAX_PENDING)


# --------------------------------------------------------------------------- validation


def _non_empty_string(value: Any, what: str) -> str:
    if not isinstance(value, str) or value.strip() == "":
        raise ValidationError(f"{what} must be a non-empty string")
    return value


def _validate_state(state: Any) -> dict[str, Any]:
    if not isinstance(state, dict):
        raise ValidationError("state must be an object")
    unknown = sorted(set(state) - {"task", "candidate", "evidence"})
    if unknown:
        raise ValidationError(f"state has unknown field(s): {', '.join(unknown)}")
    for field in ("task", "candidate"):
        if field in state and not isinstance(state[field], str):
            raise ValidationError(f"state.{field} must be a string")
    if "evidence" in state:
        evidence = state["evidence"]
        if not isinstance(evidence, list) or not all(
            isinstance(e, str) for e in evidence
        ):
            raise ValidationError("state.evidence must be a list of strings")
    if render_premise(state) == "":
        raise ValidationError(
            "state must contain at least one non-empty task, candidate or evidence"
        )
    return state


def _validate_labels(values: Any, what: str, low: int, high: int) -> list[str]:
    if not isinstance(values, list):
        raise ValidationError(f"{what} must be a list of strings")
    if not low <= len(values) <= high:
        raise ValidationError(
            f"{what} must have between {low} and {high} entries, got {len(values)}"
        )
    for value in values:
        _non_empty_string(value, f"every entry of {what}")
    if len(set(values)) != len(values):
        raise ValidationError(f"{what} must not contain duplicates")
    return values


def _validate_yes_no(where: str, question: dict[str, Any]) -> None:
    for side in ("yes", "no"):
        if side in question:
            _non_empty_string(question[side], f"{where}.{side}")


def _validate_choice(where: str, question: dict[str, Any]) -> None:
    options = _validate_labels(
        question.get("options"), f"{where}.options", MIN_OPTIONS, MAX_OPTIONS
    )
    descriptions = question.get("descriptions", {})
    if not isinstance(descriptions, dict):
        raise ValidationError(f"{where}.descriptions must be an object")
    stray = sorted(set(descriptions) - set(options))
    if stray:
        raise ValidationError(
            f"{where}.descriptions names unknown option(s): {', '.join(stray)}"
        )
    for option, description in descriptions.items():
        _non_empty_string(description, f"{where}.descriptions.{option}")
    # Answers are mapped back by label text, so two options must not render
    # to the same label (option "a" + description "b" vs. option "a: b").
    _, labels = candidate_labels(question)
    if len(set(labels)) != len(labels):
        raise ValidationError(
            f"{where}: options and descriptions render to duplicate labels"
        )


def _validate_score(where: str, question: dict[str, Any]) -> None:
    _validate_labels(question.get("levels"), f"{where}.levels", MIN_LEVELS, MAX_LEVELS)


_TYPE_VALIDATORS = {
    "yes_no": _validate_yes_no,
    "choice": _validate_choice,
    "score": _validate_score,
}


def _validate_question(key: str, question: Any) -> dict[str, Any]:
    where = f"questions.{key}"
    if not isinstance(question, dict):
        raise ValidationError(f"{where} must be an object")
    qtype = question.get("type")
    if qtype is None:
        raise ValidationError(f"{where}.type is missing")
    if qtype not in QUESTION_TYPES:
        raise ValidationError(
            f"{where}.type must be one of {', '.join(QUESTION_TYPES)}, got {qtype!r}"
        )
    _non_empty_string(question.get("instructions"), f"{where}.instructions")
    _TYPE_VALIDATORS[qtype](where, question)
    return question


def validate_request(data: Any) -> tuple[dict[str, Any], dict[str, dict[str, Any]]]:
    """Return (state, questions) or raise ValidationError."""
    if not isinstance(data, dict):
        raise ValidationError("request body must be a JSON object")
    state = _validate_state(data.get("state"))
    questions = data.get("questions")
    if not isinstance(questions, dict):
        raise ValidationError("questions must be an object keyed by question name")
    if not questions:
        raise ValidationError("questions must contain at least one question")
    if len(questions) > MAX_QUESTIONS:
        raise ValidationError(
            f"too many questions (max {MAX_QUESTIONS}), got {len(questions)}"
        )
    validated = {key: _validate_question(key, q) for key, q in questions.items()}
    labels = sum(len(candidate_labels(q)[1]) for q in validated.values())
    if labels > MAX_LABELS:
        raise ValidationError(
            f"too many candidate labels across the questions (max {MAX_LABELS}), got {labels}"
        )
    return state, validated


# --------------------------------------------------------------------------- NLI mapping


def render_premise(state: dict[str, Any]) -> str:
    """Render the state as labelled plain text: Task, Candidate, Evidence 1..n."""
    sections: list[str] = []
    task = state.get("task")
    if isinstance(task, str) and task.strip():
        sections.append(f"Task: {task.strip()}")
    candidate = state.get("candidate")
    if isinstance(candidate, str) and candidate.strip():
        sections.append(f"Candidate: {candidate.strip()}")
    evidence = [
        e.strip()
        for e in state.get("evidence") or []
        if isinstance(e, str) and e.strip()
    ]
    for i, item in enumerate(evidence, start=1):
        sections.append(f"Evidence {i}: {item}")
    return "\n".join(sections)


def hypothesis_template(instructions: str) -> str:
    """Pipeline template for one question: its instructions, then the label."""
    escaped = instructions.strip().replace("{", "{{").replace("}", "}}")
    return escaped + " Answer: {}"


def _labelled(name: str, description: str | None) -> str:
    return f"{name}: {description.strip()}" if description else name


def candidate_labels(question: dict[str, Any]) -> tuple[list[str], list[str]]:
    """(keys, labels): the answer key each label stands for, and the label text itself."""
    qtype = question["type"]
    if qtype == "yes_no":
        keys = ["yes", "no"]
        return keys, [_labelled(k, question.get(k)) for k in keys]
    if qtype == "choice":
        descriptions = question.get("descriptions") or {}
        keys = list(question["options"])
        return keys, [_labelled(o, descriptions.get(o)) for o in keys]
    keys = [str(i) for i in range(len(question["levels"]))]
    return keys, list(question["levels"])


def build_answer(
    question: dict[str, Any], probabilities: dict[str, float]
) -> dict[str, Any]:
    """Shape per-key probabilities (sum ~1) into the answer for the question's type."""
    qtype = question["type"]
    if qtype == "yes_no":
        return {"type": "yes_no", "probability_of_yes": probabilities["yes"]}
    if qtype == "choice":
        options = question["options"]
        choice = max(options, key=lambda o: probabilities[o])  # first option wins a tie
        return {
            "type": "choice",
            "choice": choice,
            "probabilities": {o: probabilities[o] for o in options},
        }
    n = len(question["levels"])
    probs = {str(i): probabilities[str(i)] for i in range(n)}
    return {
        "type": "score",
        # Float rounding can carry the weighted sum a hair past the ends of
        # the scale; the answer must stay a level value.
        "score": min(float(n - 1), max(0.0, sum(i * probs[str(i)] for i in range(n)))),
        "probabilities": probs,
    }


def decide(
    classifier: Classifier, state: dict[str, Any], questions: dict[str, dict[str, Any]]
) -> dict[str, Any]:
    """Answer every question about the state. Input must already be validated."""
    premise = render_premise(state)
    answers: dict[str, Any] = {}
    pairs: list[tuple[str, str]] = []
    for key, question in questions.items():
        template = hypothesis_template(question["instructions"])
        keys, labels = candidate_labels(question)
        by_label = classifier.classify(premise, labels, template)
        missing = [label for label in labels if label not in by_label]
        if missing:
            raise RuntimeError(f"classifier returned no score for label(s) {missing!r}")
        answers[key] = build_answer(
            question, {k: by_label[label] for k, label in zip(keys, labels)}
        )
        pairs.extend((premise, template.format(label)) for label in labels)
    return {
        "model": classifier.model_id,
        "answers": answers,
        "usage": {"input_tokens": classifier.count_tokens(pairs)},
    }


# --------------------------------------------------------------------------- HTTP


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    # A silent client must not hold its thread forever.
    timeout = CONNECTION_TIMEOUT

    def _send(self, code: int, payload: dict) -> None:
        body = json.dumps(payload).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args) -> None:  # quieter default logging
        return

    def _model_id(self) -> str:
        return _classifier.model_id if _classifier is not None else MODEL_NAME

    def do_GET(self) -> None:
        if self.path == "/health":
            self._send(200, {"status": "ok", "model": self._model_id()})
            return
        if self.path == "/models":
            self._send(200, {"models": [{"name": self._model_id()}]})
            return
        self._send(404, {"detail": "not found"})

    def do_POST(self) -> None:
        # Read (drain) the body before branching so every exit path leaves the
        # HTTP/1.1 keep-alive connection in sync; guard the size before reading.
        try:
            length = int(self.headers.get("Content-Length", "0"))
        except ValueError:
            self.close_connection = True
            self._send(400, {"detail": "invalid Content-Length"})
            return
        if length < 0 or length > MAX_BODY:
            self.close_connection = True  # body left undrained -> must close
            self._send(413, {"detail": f"body too large (max {MAX_BODY} bytes)"})
            return
        body = self.rfile.read(length) if length > 0 else b""

        if self.path != "/decide":
            self._send(404, {"detail": "not found"})
            return
        try:
            data = json.loads(body or b"{}")
        except (ValueError, RecursionError):
            # JSONDecodeError and UnicodeDecodeError are ValueErrors; a body
            # nested too deep to parse is invalid JSON for this service too.
            self._send(422, {"detail": "invalid JSON body"})
            return
        try:
            state, questions = validate_request(data)
        except ValidationError as exc:
            self._send(422, {"detail": str(exc)})
            return

        classifier = _classifier
        if classifier is None:
            self._send(500, {"detail": "model not loaded"})
            return
        if not _pending.acquire(blocking=False):
            self._send(503, {"detail": "busy, try again"})
            return
        try:
            self._send(200, decide(classifier, state, questions))
        except Exception:  # keep the service alive on a bad request
            # The detail stays in the log: model and tokenizer errors carry
            # paths and internals a client has no business reading.
            log.exception("decide failed")
            self._send(500, {"detail": "inference failed"})
        finally:
            _pending.release()


class DecisionServer(ThreadingHTTPServer):
    """The HTTP server, logging a failed connection in one line.

    A client that times out or drops the connection is an event, not a stack
    trace.
    """

    def handle_error(self, request: Any, client_address: Any) -> None:
        exc = sys.exc_info()[1]
        if isinstance(exc, OSError):
            # Timeouts and dropped connections: expected, one line each.
            log.warning("connection from %s failed: %s", client_address, exc)
            return
        # Anything else is a defect in the handler and keeps its traceback.
        log.error("request from %s failed", client_address, exc_info=exc)


def healthcheck(port: int) -> int:
    """Exit status of the container health probe: 0 when /health answers 200."""
    import urllib.request

    try:
        # The URL is a loopback literal plus the service's own port.
        # nosemgrep: python.lang.security.audit.dynamic-urllib-use-detected.dynamic-urllib-use-detected
        with urllib.request.urlopen(
            f"http://127.0.0.1:{port}/health", timeout=5
        ) as response:  # NOSONAR python:S5332
            return 0 if response.status == 200 else 1
    except OSError:
        return 1


def main() -> None:
    global _classifier
    # Load before listening, so /health answering means the model is ready.
    _classifier = load_classifier()
    server = DecisionServer((HOST, PORT), Handler)
    log.info("listening on %s:%d", HOST, PORT)
    # Plain HTTP by design: a sidecar next to its consumer on a private
    # container network; TLS is the deployment's reverse proxy's concern.
    server.serve_forever()  # NOSONAR python:S5332


if __name__ == "__main__":
    if sys.argv[1:] == ["--healthcheck"]:
        sys.exit(healthcheck(PORT))
    main()
