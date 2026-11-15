# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
"""A stream held open through nginx and PHP-FPM (spec 051 A2A-SSE3).

    python -I stream_check.py <base-url> <token>

The server runs under PHP-FPM behind nginx with nginx's FastCGI buffering at
its default (on). The script creates a task the TCK fixture leaves queued
(messageId `test-resubscribe-message-id`), subscribes to it and reads the
stream as it arrives. It asserts that the first event arrives at once, long
before the stream ends: a buffered response would deliver every byte only at
the end. Every check prints one line; the first mismatch exits 1.
"""

import http.client
import json
import sys
import time
import urllib.parse
import uuid


def check(name: str, ok: bool, detail: object) -> None:
    print(('PASS ' if ok else 'FAIL ') + name + ': ' + str(detail))
    if not ok:
        sys.exit(1)


def connect(base: str) -> tuple[http.client.HTTPConnection, str]:
    url = urllib.parse.urlsplit(base)
    return http.client.HTTPConnection(url.hostname, url.port or 80, timeout=120), url.path or '/'


def call(base: str, token: str, method: str, params: dict, stream: bool = False):
    connection, path = connect(base)
    body = json.dumps({'jsonrpc': '2.0', 'id': 'c-1', 'method': method, 'params': params})
    headers = {'Content-Type': 'application/json', 'A2A-Version': '1.0', 'Authorization': 'Bearer ' + token}
    if stream:
        headers['Accept'] = 'text/event-stream'
    connection.request('POST', path, body=body, headers=headers)
    return connection, connection.getresponse()


def main() -> None:
    base, token = sys.argv[1], sys.argv[2]

    message = {'messageId': f'test-resubscribe-message-id-{uuid.uuid4()}', 'role': 'ROLE_USER', 'parts': [{'text': 'hold'}]}
    connection, response = call(base, token, 'SendMessage', {'message': message, 'configuration': {'returnImmediately': True}})
    task = json.loads(response.read())['result']['task']
    connection.close()
    check('a task that stays open', task['status']['state'] in ('TASK_STATE_SUBMITTED', 'TASK_STATE_WORKING'), task['status']['state'])

    started = time.monotonic()
    connection, response = call(base, token, 'SubscribeToTask', {'id': task['id']}, stream=True)
    check('the answer is an event stream', response.getheader('Content-Type', '').startswith('text/event-stream'), response.getheader('Content-Type'))

    first = None
    lines = []
    buffer = b''
    while True:
        piece = response.read1(65536)
        if not piece:
            break
        buffer += piece
        while b'\n' in buffer:
            line, buffer = buffer.split(b'\n', 1)
            line = line.rstrip(b'\r')
            if line.startswith(b'data: '):
                elapsed = time.monotonic() - started
                event = json.loads(line[6:])
                lines.append((elapsed, 'data', next(iter(event['result']))))
                if first is None:
                    first = elapsed
            elif line.startswith(b': keep-alive'):
                lines.append((time.monotonic() - started, 'keep-alive', ''))
    ended = time.monotonic() - started
    connection.close()

    check('the first event is the task', bool(lines) and lines[0][1] == 'data' and lines[0][2] == 'task', lines[:1])
    check('the first event arrives at once', first is not None and first < 3.0, f'{first:.2f} s' if first is not None else None)
    check('the stream stays open after it', ended - first > 5.0, f'first event at {first:.2f} s, end at {ended:.2f} s')
    later = [entry for entry in lines[1:] if entry[0] - first > 3.0 and ended - entry[0] > 0.5]
    check('a later line arrives before the end', later != [], [f'{e[0]:.2f} s {e[1]} {e[2]}' for e in lines])
    print('RESULT ' + json.dumps({'first': round(first, 2), 'end': round(ended, 2), 'lines': len(lines)}))


if __name__ == '__main__':
    main()
