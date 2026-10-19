- **Reranker POST framing is explicit.** Requests with any `Transfer-Encoding` header are rejected with HTTP 400 and a closed connection before reading or inference. Supported `Content-Length` bodies retain their byte limit and validation behavior; real-socket tests cover unsupported framing and connection closure.

- **Reranker sidecar validates its HTTP boundary (ADR-075).** Non-object JSON and
  documents without string IDs or text return HTTP 400. Non-finite model scores
  return HTTP 500 with valid JSON. Tests exercise the real HTTP handler, connection
  reuse and environment settings; the sidecar has its own CI check.
