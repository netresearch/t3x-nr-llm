# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
"""A forwarding proxy that adds the A2A client's bearer token (spec 051 A2A-SV11).

The A2A TCK at the pinned commit sends no Authorization header: its JSON-RPC
client sets only A2A-Version, although its documentation describes A2A_AUTH_*
variables. nr-llm's server refuses every unauthenticated JSON-RPC request
(A2A-SEC1), so the TCK job puts this proxy between the two instead of
relaxing the server. With A2A_TOKEN set it adds `Authorization: Bearer $A2A_TOKEN` when the
request carries none, passes the Host header through (the site's A2A base URL
names this proxy), and forwards to $A2A_UPSTREAM. Without A2A_TOKEN (the interop job) it adds
nothing. Nothing else is changed.

    A2A_TOKEN=... A2A_UPSTREAM=a2a-s3-sut:8080 python -I auth_proxy.py 8080
"""

import http.client
import os
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

UPSTREAM = os.environ['A2A_UPSTREAM']
TOKEN = os.environ.get('A2A_TOKEN', '')
HOP = {'connection', 'keep-alive', 'proxy-connection', 'transfer-encoding', 'upgrade', 'te', 'trailer'}


class Proxy(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def _forward(self) -> None:
        length = int(self.headers.get('Content-Length') or 0)
        body = self.rfile.read(length) if length else None
        headers = {k: v for k, v in self.headers.items() if k.lower() not in HOP}
        if TOKEN and not any(k.lower() == 'authorization' for k in headers):
            headers['Authorization'] = 'Bearer ' + TOKEN
        host, _, port = UPSTREAM.partition(':')
        connection = http.client.HTTPConnection(host, int(port or 80), timeout=180)
        connection.request(self.command, self.path, body=body, headers=headers)
        response = connection.getresponse()
        payload = response.read()
        self.send_response(response.status, response.reason)
        for key, value in response.getheaders():
            if key.lower() not in HOP and key.lower() != 'content-length':
                self.send_header(key, value)
        self.send_header('Content-Length', str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)
        connection.close()

    do_GET = do_POST = do_HEAD = do_PUT = do_DELETE = do_OPTIONS = _forward

    def log_message(self, fmt: str, *args) -> None:
        sys.stderr.write('proxy: ' + (fmt % args) + '\n')


if __name__ == '__main__':
    ThreadingHTTPServer(('0.0.0.0', int(sys.argv[1])), Proxy).serve_forever()
