# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH

"""Unit tests for the decision sidecar — stdlib only, no model download.

Run from the repository root:  python -m unittest Build/decision/test_app.py

A fake classifier is injected into app._classifier; torch and transformers are
never imported (app imports them lazily inside load_classifier()).
"""

from __future__ import annotations

import http.client
import json
import os
import socket
import sys
import threading
import time
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import app  # after the sys.path insert above


class FakeClassifier:
    """Deterministic stand-in: scores are proportional to a per-label weight."""

    model_id = "fake/nli-model"

    def __init__(
        self, weights: dict[str, float] | None = None, fail: bool = False
    ) -> None:
        self.weights = weights or {}
        self.fail = fail
        self.calls: list[tuple[str, list[str], str]] = []

    def classify(
        self, premise: str, labels: list[str], template: str
    ) -> dict[str, float]:
        if self.fail:
            raise RuntimeError("boom")
        self.calls.append((premise, list(labels), template))
        raw = {label: self.weights.get(label, 1.0) for label in labels}
        total = sum(raw.values())
        # Sorted by score like the real pipeline, so the mapping cannot rely on order.
        return dict(
            sorted(((k, v / total) for k, v in raw.items()), key=lambda kv: -kv[1])
        )

    def count_tokens(self, pairs: list[tuple[str, str]]) -> int:
        return sum(len(p.split()) + len(h.split()) for p, h in pairs)


def _request(**questions) -> dict:
    return {
        "state": {"task": "Find X.", "candidate": "X is here."},
        "questions": questions,
    }


class ModuleImportTest(unittest.TestCase):
    def test_no_heavy_import_at_module_level(self) -> None:
        self.assertNotIn("torch", sys.modules)
        self.assertNotIn("transformers", sys.modules)

    def test_defaults(self) -> None:
        self.assertEqual(8082, app.PORT)
        self.assertEqual(
            "MoritzLaurer/mDeBERTa-v3-base-xnli-multilingual-nli-2mil7", app.MODEL_NAME
        )


class PremiseTest(unittest.TestCase):
    def test_sections_in_fixed_order(self) -> None:
        premise = app.render_premise(
            {"evidence": ["e1", "e2"], "candidate": "c", "task": "t"}
        )
        self.assertEqual(
            "Task: t\nCandidate: c\nEvidence 1: e1\nEvidence 2: e2", premise
        )

    def test_only_present_fields(self) -> None:
        self.assertEqual("Candidate: c", app.render_premise({"candidate": "c"}))
        self.assertEqual("Evidence 1: only", app.render_premise({"evidence": ["only"]}))

    def test_blank_fields_are_omitted_and_evidence_renumbered(self) -> None:
        premise = app.render_premise({"task": "  ", "evidence": ["", "a", " ", "b"]})
        self.assertEqual("Evidence 1: a\nEvidence 2: b", premise)


class ValidationTest(unittest.TestCase):
    def assertInvalid(self, data, fragment: str) -> None:
        with self.assertRaises(app.ValidationError) as ctx:
            app.validate_request(data)
        self.assertIn(fragment, str(ctx.exception))

    def test_valid_request_of_every_type(self) -> None:
        state, questions = app.validate_request(
            _request(
                a={"type": "yes_no", "instructions": "Q?", "yes": "y", "no": "n"},
                b={
                    "type": "choice",
                    "instructions": "Q?",
                    "options": ["x", "y"],
                    "descriptions": {"x": "d"},
                },
                c={"type": "score", "instructions": "Q?", "levels": ["lo", "hi"]},
            )
        )
        self.assertEqual({"a", "b", "c"}, set(questions))
        self.assertEqual("Find X.", state["task"])

    def test_body_not_an_object(self) -> None:
        self.assertInvalid([], "JSON object")

    def test_malformed_state(self) -> None:
        q = {"q": {"type": "yes_no", "instructions": "Q?"}}
        self.assertInvalid({"questions": q}, "state must be an object")
        self.assertInvalid({"state": "text", "questions": q}, "state must be an object")
        self.assertInvalid({"state": {"task": 1}, "questions": q}, "state.task")
        self.assertInvalid(
            {"state": {"candidate": ["c"]}, "questions": q}, "state.candidate"
        )
        self.assertInvalid(
            {"state": {"evidence": "e"}, "questions": q}, "state.evidence"
        )
        self.assertInvalid(
            {"state": {"evidence": ["e", 2]}, "questions": q}, "state.evidence"
        )
        self.assertInvalid(
            {"state": {"task": "t", "extra": "x"}, "questions": q}, "unknown field"
        )

    def test_empty_state(self) -> None:
        q = {"q": {"type": "yes_no", "instructions": "Q?"}}
        self.assertInvalid({"state": {}, "questions": q}, "at least one non-empty")
        self.assertInvalid(
            {"state": {"task": " ", "evidence": []}, "questions": q},
            "at least one non-empty",
        )

    def test_no_questions(self) -> None:
        self.assertInvalid({"state": {"task": "t"}}, "questions must be an object")
        self.assertInvalid(
            {"state": {"task": "t"}, "questions": {}}, "at least one question"
        )
        self.assertInvalid(
            {"state": {"task": "t"}, "questions": []}, "questions must be an object"
        )

    def test_too_many_questions(self) -> None:
        many = {
            f"q{i}": {"type": "yes_no", "instructions": "Q?"}
            for i in range(app.MAX_QUESTIONS + 1)
        }
        self.assertInvalid(
            {"state": {"task": "t"}, "questions": many}, "too many questions"
        )

    def test_question_not_an_object(self) -> None:
        self.assertInvalid(_request(q="yes_no"), "questions.q must be an object")

    def test_missing_and_unknown_type(self) -> None:
        self.assertInvalid(
            _request(q={"instructions": "Q?"}), "questions.q.type is missing"
        )
        self.assertInvalid(
            _request(q={"type": "rank", "instructions": "Q?"}), "must be one of"
        )

    def test_empty_instructions(self) -> None:
        self.assertInvalid(_request(q={"type": "yes_no"}), "instructions")
        self.assertInvalid(
            _request(q={"type": "yes_no", "instructions": ""}), "instructions"
        )
        self.assertInvalid(
            _request(q={"type": "yes_no", "instructions": "   "}), "instructions"
        )

    def test_yes_no_descriptions_must_be_strings(self) -> None:
        self.assertInvalid(
            _request(q={"type": "yes_no", "instructions": "Q?", "yes": 1}),
            "questions.q.yes",
        )
        self.assertInvalid(
            _request(q={"type": "yes_no", "instructions": "Q?", "no": ""}),
            "questions.q.no",
        )

    def test_choice_option_count_bounds(self) -> None:
        self.assertInvalid(
            _request(q={"type": "choice", "instructions": "Q?", "options": ["a"]}),
            "between 2 and 255",
        )
        too_many = [f"o{i}" for i in range(256)]
        self.assertInvalid(
            _request(q={"type": "choice", "instructions": "Q?", "options": too_many}),
            "got 256",
        )
        app.validate_request(
            _request(
                q={"type": "choice", "instructions": "Q?", "options": too_many[:255]}
            )
        )
        self.assertInvalid(
            _request(q={"type": "choice", "instructions": "Q?"}),
            "options must be a list",
        )

    def test_choice_duplicate_and_empty_options(self) -> None:
        self.assertInvalid(
            _request(q={"type": "choice", "instructions": "Q?", "options": ["a", "a"]}),
            "duplicates",
        )
        self.assertInvalid(
            _request(q={"type": "choice", "instructions": "Q?", "options": ["a", ""]}),
            "non-empty",
        )
        self.assertInvalid(
            _request(q={"type": "choice", "instructions": "Q?", "options": ["a", 2]}),
            "non-empty",
        )

    def test_choice_descriptions(self) -> None:
        base = {"type": "choice", "instructions": "Q?", "options": ["a", "b"]}
        self.assertInvalid(
            _request(q={**base, "descriptions": ["x"]}),
            "descriptions must be an object",
        )
        self.assertInvalid(
            _request(q={**base, "descriptions": {"c": "x"}}), "unknown option(s): c"
        )
        self.assertInvalid(
            _request(q={**base, "descriptions": {"a": ""}}), "descriptions.a"
        )

    def test_choice_rendered_label_collision(self) -> None:
        q = {
            "type": "choice",
            "instructions": "Q?",
            "options": ["a", "a: b"],
            "descriptions": {"a": "b"},
        }
        self.assertInvalid(_request(q=q), "duplicate labels")

    def test_score_level_count_bounds(self) -> None:
        self.assertInvalid(
            _request(q={"type": "score", "instructions": "Q?", "levels": ["x"]}),
            "between 2 and 10",
        )
        levels = [f"l{i}" for i in range(11)]
        self.assertInvalid(
            _request(q={"type": "score", "instructions": "Q?", "levels": levels}),
            "got 11",
        )
        app.validate_request(
            _request(q={"type": "score", "instructions": "Q?", "levels": levels[:10]})
        )
        self.assertInvalid(
            _request(q={"type": "score", "instructions": "Q?"}), "levels must be a list"
        )

    def test_score_duplicate_levels(self) -> None:
        self.assertInvalid(
            _request(q={"type": "score", "instructions": "Q?", "levels": ["x", "x"]}),
            "duplicates",
        )


class MappingTest(unittest.TestCase):
    def test_hypothesis_template_carries_instructions_and_escapes_braces(self) -> None:
        template = app.hypothesis_template(" Does it mention {name}? ")
        self.assertEqual("Does it mention {name}? Answer: yes", template.format("yes"))

    def test_yes_no_labels(self) -> None:
        self.assertEqual(
            (["yes", "no"], ["yes", "no"]), app.candidate_labels({"type": "yes_no"})
        )
        keys, labels = app.candidate_labels(
            {"type": "yes_no", "yes": "It fits.", "no": "It does not fit."}
        )
        self.assertEqual(["yes", "no"], keys)
        self.assertEqual(["yes: It fits.", "no: It does not fit."], labels)

    def test_choice_labels(self) -> None:
        keys, labels = app.candidate_labels(
            {
                "type": "choice",
                "options": ["de", "en"],
                "descriptions": {"de": "German"},
            }
        )
        self.assertEqual(["de", "en"], keys)
        self.assertEqual(["de: German", "en"], labels)

    def test_score_labels(self) -> None:
        keys, labels = app.candidate_labels(
            {"type": "score", "levels": ["low", "mid", "high"]}
        )
        self.assertEqual(["0", "1", "2"], keys)
        self.assertEqual(["low", "mid", "high"], labels)

    def _decide(
        self, weights: dict[str, float], **questions
    ) -> tuple[dict, FakeClassifier]:
        fake = FakeClassifier(weights)
        state, validated = app.validate_request(_request(**questions))
        return app.decide(fake, state, validated), fake

    def test_yes_no_answer(self) -> None:
        out, fake = self._decide(
            {"yes: It fits.": 3.0, "no: It misfits.": 1.0},
            q={
                "type": "yes_no",
                "instructions": "Does it fit?",
                "yes": "It fits.",
                "no": "It misfits.",
            },
        )
        self.assertEqual(
            {"type": "yes_no", "probability_of_yes": 0.75}, out["answers"]["q"]
        )
        premise, labels, template = fake.calls[0]
        self.assertEqual("Task: Find X.\nCandidate: X is here.", premise)
        self.assertEqual(["yes: It fits.", "no: It misfits."], labels)
        self.assertEqual("Does it fit? Answer: {}", template)

    def test_choice_answer(self) -> None:
        out, _ = self._decide(
            {"a": 1.0, "b: best": 6.0, "c": 3.0},
            q={
                "type": "choice",
                "instructions": "Which?",
                "options": ["a", "b", "c"],
                "descriptions": {"b": "best"},
            },
        )
        answer = out["answers"]["q"]
        self.assertEqual("choice", answer["type"])
        self.assertEqual("b", answer["choice"])
        self.assertEqual(
            ["a", "b", "c"], list(answer["probabilities"])
        )  # keyed by option, request order
        self.assertAlmostEqual(1.0, sum(answer["probabilities"].values()))
        self.assertAlmostEqual(0.6, answer["probabilities"]["b"])

    def test_choice_tie_picks_first_option(self) -> None:
        out, _ = self._decide(
            {}, q={"type": "choice", "instructions": "Which?", "options": ["z", "a"]}
        )
        self.assertEqual("z", out["answers"]["q"]["choice"])

    def test_score_answer_is_probability_weighted(self) -> None:
        out, _ = self._decide(
            {"low": 1.0, "mid": 1.0, "high": 2.0},
            q={
                "type": "score",
                "instructions": "How good?",
                "levels": ["low", "mid", "high"],
            },
        )
        answer = out["answers"]["q"]
        self.assertEqual({"0": 0.25, "1": 0.25, "2": 0.5}, answer["probabilities"])
        self.assertAlmostEqual(1.25, answer["score"])  # 0*0.25 + 1*0.25 + 2*0.5

    def test_a_score_past_the_top_level_by_rounding_stays_on_the_scale(self) -> None:
        question = {"type": "score", "levels": ["low", "mid", "high"]}
        # Probabilities that sum a hair above 1, as float rounding can.
        answer = app.build_answer(question, {"0": 0.0, "1": 0.0, "2": 1.0000004})
        self.assertEqual(2.0, answer["score"])

    def test_a_score_below_the_bottom_level_stays_on_the_scale(self) -> None:
        question = {"type": "score", "levels": ["low", "high"]}
        answer = app.build_answer(question, {"0": 1.0, "1": -1e-9})
        self.assertEqual(0.0, answer["score"])

    def test_every_question_answered_and_tokens_counted(self) -> None:
        out, fake = self._decide(
            {},
            a={"type": "yes_no", "instructions": "One?"},
            b={"type": "score", "instructions": "Two?", "levels": ["x", "y", "z"]},
        )
        self.assertEqual({"a", "b"}, set(out["answers"]))
        self.assertEqual("fake/nli-model", out["model"])
        # 5 pairs (2 + 3); premise "Task: Find X.\nCandidate: X is here." = 7 words,
        # hypotheses "<instr> Answer: <label>" = 3 words each.
        self.assertEqual(5 * (7 + 3), out["usage"]["input_tokens"])
        self.assertEqual(2, len(fake.calls))  # one batched call per question

    def test_missing_label_in_classifier_output_is_an_error(self) -> None:
        class Broken(FakeClassifier):
            def classify(self, premise, labels, template):
                return {labels[0]: 1.0}

        state, questions = app.validate_request(
            _request(q={"type": "yes_no", "instructions": "Q?"})
        )
        broken = Broken()
        with self.assertRaises(RuntimeError):
            app.decide(broken, state, questions)


class FakePipe:
    """Stands in for the transformers pipeline: records calls, answers sorted."""

    def __init__(self, scores: dict[str, float]) -> None:
        self.scores = scores
        self.calls: list[dict] = []
        self.tokenizer = self._tokenize

    def __call__(self, premise, **kwargs):
        self.calls.append({"premise": premise, **kwargs})
        ranked = sorted(self.scores.items(), key=lambda kv: -kv[1])
        return {
            "labels": [label for label, _ in ranked],
            "scores": [score for _, score in ranked],
        }

    def _tokenize(self, pairs, **kwargs):
        self.tokenizer_kwargs = kwargs
        return {"input_ids": [list(range(len(" ".join(p).split()))) for p in pairs]}


class LimitsTest(unittest.TestCase):
    def test_too_many_labels_across_questions_are_refused(self) -> None:
        options = [f"o{i}" for i in range(255)]
        questions = {
            f"q{i}": {"type": "choice", "instructions": "Q?", "options": options}
            for i in range(3)
        }
        with self.assertRaises(app.ValidationError) as ctx:
            app.validate_request({"state": {"candidate": "x"}, "questions": questions})
        self.assertIn("candidate labels", str(ctx.exception))

    def test_a_request_within_the_label_budget_is_accepted(self) -> None:
        options = [f"o{i}" for i in range(255)]
        questions = {
            f"q{i}": {"type": "choice", "instructions": "Q?", "options": options}
            for i in range(2)
        }
        app.validate_request({"state": {"candidate": "x"}, "questions": questions})


class PipelineClassifierTest(unittest.TestCase):
    def test_labels_go_in_as_a_list_in_bounded_batches_and_map_back_by_name(
        self,
    ) -> None:
        pipe = FakePipe({"billing, invoices": 0.7, "tech": 0.3})
        classifier = app.PipelineClassifier(pipe, "m")

        result = classifier.classify("P", ["tech", "billing, invoices"], "{}")

        call = pipe.calls[0]
        # A string would be split on its comma into two labels.
        self.assertEqual(["tech", "billing, invoices"], call["candidate_labels"])
        self.assertEqual(2, call["batch_size"])
        self.assertFalse(call["multi_label"])
        self.assertEqual({"billing, invoices": 0.7, "tech": 0.3}, result)

    def test_a_softmax_an_epsilon_outside_the_unit_interval_is_clamped(self) -> None:
        pipe = FakePipe({"yes": 1.0000002, "no": -0.0000001})

        result = app.PipelineClassifier(pipe, "m").classify("P", ["yes", "no"], "{}")

        self.assertEqual({"yes": 1.0, "no": 0.0}, result)

    def test_tokens_are_counted_as_the_pipeline_truncates(self) -> None:
        pipe = FakePipe({})
        classifier = app.PipelineClassifier(pipe, "m")

        self.assertEqual(0, classifier.count_tokens([]))
        self.assertEqual(5, classifier.count_tokens([("a b", "c"), ("d", "e")]))
        self.assertEqual("only_first", pipe.tokenizer_kwargs["truncation"])
        self.assertTrue(pipe.tokenizer_kwargs["add_special_tokens"])


class ServerTest(unittest.TestCase):
    def test_a_failed_connection_is_logged_in_one_line(self) -> None:
        server = app.DecisionServer(("127.0.0.1", 0), app.Handler)
        try:
            with self.assertLogs("decision", level="WARNING") as logs:
                try:
                    raise OSError("connection reset")
                except OSError:
                    server.handle_error(None, ("198.51.100.7", 4242))
        finally:
            server.server_close()
        self.assertEqual(1, len(logs.output))
        self.assertIn("connection reset", logs.output[0])

    def test_a_handler_defect_keeps_its_traceback(self) -> None:
        server = app.DecisionServer(("127.0.0.1", 0), app.Handler)
        try:
            with self.assertLogs("decision", level="ERROR") as logs:
                try:
                    raise RecursionError("nested too deep")
                except RecursionError:
                    server.handle_error(None, ("198.51.100.7", 4242))
        finally:
            server.server_close()
        self.assertIn("RecursionError", logs.output[0])

    def test_a_silent_connection_is_dropped_after_the_timeout(self) -> None:
        class Quick(app.Handler):
            timeout = 0.3

        server = app.DecisionServer(("127.0.0.1", 0), Quick)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            silent = socket.create_connection(server.server_address, timeout=5)
            try:
                started = time.monotonic()
                # Sends nothing; the server must close it, not wait for ever.
                self.assertEqual(b"", silent.recv(1))
                self.assertLess(time.monotonic() - started, 4)
            finally:
                silent.close()
        finally:
            server.shutdown()
            server.server_close()

    def test_the_health_probe_fails_when_nothing_answers(self) -> None:
        server = app.DecisionServer(("127.0.0.1", 0), app.Handler)
        port = server.server_address[1]
        server.server_close()
        self.assertEqual(1, app.healthcheck(port))


class HandlerTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.server = app.DecisionServer(("127.0.0.1", 0), app.Handler)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()

    @classmethod
    def tearDownClass(cls) -> None:
        cls.server.shutdown()
        cls.server.server_close()

    def setUp(self) -> None:
        self._saved = app._classifier
        app._classifier = FakeClassifier({"yes": 4.0, "no": 1.0})

    def tearDown(self) -> None:
        app._classifier = self._saved

    def call(
        self,
        method: str,
        path: str,
        body: bytes | None = None,
        headers: dict | None = None,
    ):
        conn = http.client.HTTPConnection(
            "127.0.0.1", self.server.server_address[1], timeout=5
        )
        try:
            conn.request(method, path, body=body, headers=headers or {})
            resp = conn.getresponse()
            payload = json.loads(resp.read() or b"null")
            return resp.status, payload, resp.getheader("Content-Type")
        finally:
            conn.close()

    def post(self, data) -> tuple[int, dict, str]:
        return self.call(
            "POST",
            "/decide",
            json.dumps(data).encode("utf-8"),
            {"Content-Type": "application/json"},
        )

    def test_the_health_probe_answers_on_the_servers_port(self) -> None:
        self.assertEqual(0, app.healthcheck(self.server.server_address[1]))

    def test_health_and_models(self) -> None:
        self.assertEqual(
            (200, {"status": "ok", "model": "fake/nli-model"}, "application/json"),
            self.call("GET", "/health"),
        )
        self.assertEqual(
            (200, {"models": [{"name": "fake/nli-model"}]}, "application/json"),
            self.call("GET", "/models"),
        )

    def test_decide_ok(self) -> None:
        status, payload, ctype = self.post(
            _request(q={"type": "yes_no", "instructions": "Is it?"})
        )
        self.assertEqual(200, status)
        self.assertEqual("application/json", ctype)
        self.assertEqual({"model", "answers", "usage"}, set(payload))
        self.assertEqual(
            {"type": "yes_no", "probability_of_yes": 0.8}, payload["answers"]["q"]
        )
        self.assertIsInstance(payload["usage"]["input_tokens"], int)

    def test_validation_error_is_422_with_detail(self) -> None:
        status, payload, _ = self.post(
            _request(q={"type": "nope", "instructions": "Q?"})
        )
        self.assertEqual(422, status)
        self.assertIn("must be one of", payload["detail"])

    def test_invalid_json_is_422(self) -> None:
        status, payload, _ = self.call("POST", "/decide", b"{not json")
        self.assertEqual((422, "invalid JSON body"), (status, payload["detail"]))
        status, _, _ = self.call("POST", "/decide", b"\xff\xfe")
        self.assertEqual(422, status)

    def test_empty_body_is_422(self) -> None:
        status, payload, _ = self.call("POST", "/decide", b"")
        self.assertEqual(422, status)
        self.assertIn("state", payload["detail"])

    def test_oversized_body_is_413(self) -> None:
        conn = http.client.HTTPConnection(
            "127.0.0.1", self.server.server_address[1], timeout=5
        )
        try:
            conn.putrequest("POST", "/decide")
            conn.putheader("Content-Length", str(app.MAX_BODY + 1))
            conn.endheaders()  # the body is never sent: the guard answers from the header alone
            resp = conn.getresponse()
            self.assertEqual(413, resp.status)
            self.assertIn("body too large", json.loads(resp.read())["detail"])
        finally:
            conn.close()

    def test_invalid_content_length_is_400(self) -> None:
        status, payload, _ = self.call(
            "POST", "/decide", None, {"Content-Length": "abc"}
        )
        self.assertEqual((400, "invalid Content-Length"), (status, payload["detail"]))

    def test_unknown_paths_are_404(self) -> None:
        self.assertEqual(404, self.call("GET", "/decide")[0])
        status, payload, _ = self.call("POST", "/nope", b"{}")
        self.assertEqual((404, "not found"), (status, payload["detail"]))

    def test_classifier_failure_is_500(self) -> None:
        app._classifier = FakeClassifier(fail=True)
        with self.assertLogs("decision", level="ERROR"):
            status, payload, _ = self.post(
                _request(q={"type": "yes_no", "instructions": "Q?"})
            )
        self.assertEqual(500, status)
        # The cause is logged, not sent: it carries the model's internals.
        self.assertEqual("inference failed", payload["detail"])

    def test_a_full_queue_turns_requests_away(self) -> None:
        held = [app._pending.acquire(blocking=False) for _ in range(app.MAX_PENDING)]
        try:
            status, payload, _ = self.post(
                _request(q={"type": "yes_no", "instructions": "Q?"})
            )
        finally:
            for acquired in held:
                if acquired:
                    app._pending.release()
        self.assertEqual((503, "busy, try again"), (status, payload["detail"]))

    def test_json_nested_too_deep_is_invalid_json(self) -> None:
        status, payload, _ = self.call("POST", "/decide", b"[" * 200_000)
        self.assertEqual((422, "invalid JSON body"), (status, payload["detail"]))

    def test_model_not_loaded_is_500(self) -> None:
        app._classifier = None
        status, payload, _ = self.post(
            _request(q={"type": "yes_no", "instructions": "Q?"})
        )
        self.assertEqual((500, "model not loaded"), (status, payload["detail"]))

    def test_keep_alive_survives_an_error_response(self) -> None:
        # The body is drained before branching, so the next request on the same
        # HTTP/1.1 connection is parsed cleanly.
        conn = http.client.HTTPConnection(
            "127.0.0.1", self.server.server_address[1], timeout=5
        )
        try:
            conn.request("POST", "/nope", body=b'{"x": 1}')
            first = conn.getresponse()
            first.read()
            conn.request(
                "POST",
                "/decide",
                body=json.dumps(_request(q={"type": "yes_no", "instructions": "Q?"})),
            )
            second = conn.getresponse()
            self.assertEqual((404, 200), (first.status, second.status))
            second.read()
        finally:
            conn.close()


if __name__ == "__main__":
    unittest.main()
