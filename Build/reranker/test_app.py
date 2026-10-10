"""Exercise the real HTTP handler without downloading or invoking a model."""

# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH

from __future__ import annotations

import http.client
import importlib.util
import json
import os
import sys
import threading
import types
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch


class ModelScore:
    """A model scalar convertible to float but not directly JSON serializable."""

    def __init__(self, value):
        self.value = value

    def __float__(self):
        return self.value


class FakeCrossEncoder:
    def __init__(self, *args, **kwargs):
        self.calls = []
        self.initialization = (args, kwargs)

    def predict(self, pairs, **kwargs):
        self.calls.append((pairs, kwargs))
        return [ModelScore(0.5 + index / 10) for index in range(len(pairs))]


stub = types.ModuleType("sentence_transformers")
stub.CrossEncoder = FakeCrossEncoder
spec = importlib.util.spec_from_file_location(
    "reranker_under_test", Path(__file__).with_name("app.py")
)
app = importlib.util.module_from_spec(spec)
with patch.dict(sys.modules, {"sentence_transformers": stub}):
    spec.loader.exec_module(app)


class SettingsTest(unittest.TestCase):
    def load_app(self, environment):
        settings_spec = importlib.util.spec_from_file_location(
            "reranker_settings_under_test", Path(__file__).with_name("app.py")
        )
        module = importlib.util.module_from_spec(settings_spec)
        with (
            patch.dict(os.environ, environment, clear=True),
            patch.dict(sys.modules, {"sentence_transformers": stub}),
        ):
            settings_spec.loader.exec_module(module)
        return module

    def test_documented_defaults_reach_model_initialization(self):
        module = self.load_app({})
        self.assertEqual("0.0.0.0", module.HOST)
        self.assertEqual(8081, module.PORT)
        self.assertEqual(128, module.MAX_DOCUMENTS)
        self.assertEqual(16 * 1024 * 1024, module.MAX_BODY)
        self.assertEqual(
            (("BAAI/bge-reranker-v2-m3",), {"max_length": 512, "device": "cpu"}),
            module._model.initialization,
        )

    def test_all_environment_settings_reach_their_runtime_consumers(self):
        module = self.load_app(
            {
                "RERANKER_MODEL": "fixture/model",
                "RERANKER_DEVICE": "cuda",
                "RERANKER_HOST": "127.0.0.1",
                "RERANKER_PORT": "9191",
                "RERANKER_MAX_LENGTH": "123",
                "RERANKER_MAX_DOCUMENTS": "7",
                "RERANKER_MAX_BODY_BYTES": "1024",
            }
        )
        self.assertEqual(
            (("fixture/model",), {"max_length": 123, "device": "cuda"}),
            module._model.initialization,
        )
        self.assertEqual(7, module.MAX_DOCUMENTS)
        self.assertEqual(1024, module.MAX_BODY)
        server = MagicMock()
        with patch.object(
            module, "ThreadingHTTPServer", return_value=server
        ) as factory:
            module.main()
        factory.assert_called_once_with(("127.0.0.1", 9191), module.Handler)
        server.serve_forever.assert_called_once_with()

    def test_noninteger_limits_fail_before_model_initialization(self):
        for variable in (
            "RERANKER_PORT",
            "RERANKER_MAX_LENGTH",
            "RERANKER_MAX_DOCUMENTS",
            "RERANKER_MAX_BODY_BYTES",
        ):
            with (
                self.subTest(variable=variable),
                patch.object(stub, "CrossEncoder") as constructor,
            ):
                with self.assertRaises(ValueError):
                    self.load_app({variable: "invalid"})
                constructor.assert_not_called()


class HandlerTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server = app.ThreadingHTTPServer(("127.0.0.1", 0), app.Handler)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.thread.join(timeout=5)

    def setUp(self):
        app._model.calls.clear()
        self.connection = http.client.HTTPConnection(
            "127.0.0.1", self.server.server_port, timeout=5
        )
        self.addCleanup(self.connection.close)

    def request(self, method, path, body=None, headers=None):
        self.connection.request(method, path, body, headers or {})
        try:
            response = self.connection.getresponse()
        except http.client.RemoteDisconnected:
            self.fail(
                "The handler must return an HTTP response instead of disconnecting"
            )

        def reject_nonfinite(value):
            raise ValueError(f"Nonfinite JSON number: {value}")

        try:
            payload = json.loads(response.read(), parse_constant=reject_nonfinite)
        except ValueError as error:
            self.fail(f"The handler must return valid JSON: {error}")
        return response.status, payload

    def post(self, payload):
        return self.request(
            "POST", "/rerank", json.dumps(payload), {"Content-Type": "application/json"}
        )

    def assert_connection_still_usable(self):
        original_socket = self.connection.sock
        status, payload = self.request("GET", "/health")
        self.assertEqual(200, status)
        self.assertEqual("ok", payload["status"])
        self.assertIs(original_socket, self.connection.sock)

    def test_health_and_unknown_route_keep_the_connection_usable(self):
        self.assertEqual((404, {"error": "not found"}), self.request("GET", "/missing"))
        self.assert_connection_still_usable()

    def test_valid_batch_preserves_ids_order_and_scoring_options(self):
        status, payload = self.post(
            {
                "query": "question",
                "documents": [
                    {"id": "second", "text": "passage B"},
                    {"id": "first", "text": "passage A"},
                ],
            }
        )
        self.assertEqual(200, status)
        self.assertEqual(
            {"scores": [{"id": "second", "score": 0.5}, {"id": "first", "score": 0.6}]},
            payload,
        )
        self.assertEqual(
            [
                (
                    [("question", "passage B"), ("question", "passage A")],
                    {"batch_size": 32, "show_progress_bar": False},
                )
            ],
            app._model.calls,
        )
        self.assert_connection_still_usable()

    def test_empty_batch_does_not_invoke_the_model(self):
        self.assertEqual(
            (200, {"scores": []}), self.post({"query": "q", "documents": []})
        )
        self.assertEqual([], app._model.calls)

    def test_non_object_json_is_a_client_error_without_inference_or_disconnect(self):
        for payload in [None, [], [1], 42, True, "text"]:
            with self.subTest(payload=payload):
                status, response = self.post(payload)
                self.assertEqual(400, status)
                self.assertIn("error", response)
                self.assertEqual([], app._model.calls)
                self.assert_connection_still_usable()

    def test_malformed_json_and_encoding_are_client_errors(self):
        for body in [b"{", b"\xff", b'{"query":']:
            with self.subTest(body=body):
                status, _ = self.request("POST", "/rerank", body)
                self.assertEqual(400, status)
                self.assertEqual([], app._model.calls)
                self.assert_connection_still_usable()

    def test_invalid_request_fields_are_rejected_before_inference(self):
        for payload in [
            {},
            {"query": "", "documents": []},
            {"query": 1, "documents": []},
            {"query": "q"},
            {"query": "q", "documents": {}},
        ]:
            with self.subTest(payload=payload):
                self.assertEqual(400, self.post(payload)[0])
                self.assertEqual([], app._model.calls)
                self.assert_connection_still_usable()

    def test_malformed_document_entries_are_client_errors_before_inference(self):
        for document in [
            None,
            "passage",
            42,
            [],
            {},
            {"id": "one"},
            {"text": "passage"},
            {"id": 1, "text": "passage"},
            {"id": "one", "text": []},
        ]:
            with self.subTest(document=document):
                self.assertEqual(
                    400, self.post({"query": "q", "documents": [document]})[0]
                )
                self.assertEqual([], app._model.calls)
                self.assert_connection_still_usable()

    def test_document_limit_allows_the_boundary_and_rejects_the_next_item(self):
        documents = [{"id": str(index), "text": "passage"} for index in range(3)]
        with patch.object(app, "MAX_DOCUMENTS", 2):
            self.assertEqual(
                200, self.post({"query": "q", "documents": documents[:2]})[0]
            )
            app._model.calls.clear()
            self.assertEqual(413, self.post({"query": "q", "documents": documents})[0])
            self.assertEqual([], app._model.calls)
            self.assert_connection_still_usable()

    def test_unknown_post_route_drains_the_body(self):
        self.assertEqual(
            (404, {"error": "not found"}),
            self.request("POST", "/missing", b'{"unused":true}'),
        )
        self.assertEqual([], app._model.calls)
        self.assert_connection_still_usable()

    def test_body_byte_limit_accepts_the_boundary_and_closes_above_it(self):
        body = json.dumps(
            {"query": "Frage ä", "documents": [{"id": "one", "text": "passage"}]},
            ensure_ascii=False,
        ).encode("utf-8")
        with patch.object(app, "MAX_BODY", len(body)):
            self.assertEqual(
                (200, {"scores": [{"id": "one", "score": 0.5}]}),
                self.request("POST", "/rerank", body),
            )
            self.assert_connection_still_usable()
            app._model.calls.clear()
            status, _ = self.request("POST", "/rerank", body + b" ")
            self.assertEqual(413, status)
            self.assertEqual(b"", self.connection.sock.recv(1))
            self.assertEqual([], app._model.calls)

    def test_invalid_content_length_closes_without_scoring(self):
        for length, expected in [("invalid", 400), ("-1", 413)]:
            with self.subTest(length=length):
                connection = http.client.HTTPConnection(
                    "127.0.0.1", self.server.server_port, timeout=5
                )
                try:
                    connection.request(
                        "POST", "/rerank", b"x", {"Content-Length": length}
                    )
                    response = connection.getresponse()
                    self.assertEqual(expected, response.status)
                    json.loads(response.read())
                    self.assertEqual(b"", connection.sock.recv(1))
                finally:
                    connection.close()
        self.assertEqual([], app._model.calls)

    def test_model_failure_returns_an_error_and_keeps_the_handler_alive(self):
        with (
            patch.object(
                app._model, "predict", side_effect=RuntimeError("scorer failed")
            ),
            self.assertLogs("reranker", level="ERROR"),
        ):
            status, payload = self.post(
                {"query": "q", "documents": [{"id": "one", "text": "passage"}]}
            )
            self.assertEqual(500, status)
            self.assertIn("error", payload)
            self.assert_connection_still_usable()

    def test_nonfinite_model_scores_are_server_errors_with_valid_json(self):
        for score in (float("nan"), float("inf"), -float("inf")):
            with (
                self.subTest(score=score),
                patch.object(app._model, "predict", return_value=[score]),
                self.assertLogs("reranker", level="ERROR"),
            ):
                status, payload = self.post(
                    {"query": "q", "documents": [{"id": "one", "text": "passage"}]}
                )
                self.assertEqual(500, status)
                self.assertIn("error", payload)
                self.assert_connection_still_usable()


if __name__ == "__main__":
    unittest.main()
