# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
"""a2a-python 1.3.0 as a client of nr-llm's A2A server (spec 051, interop job).

    python -I interop_client.py <base-url> <token>

Every cell asserts; the script prints one line per cell and exits 1 on the
first mismatch. The server's profile is the TCK fixture, so the task outcomes
are chosen by the messageId prefix and no model is called.
"""

import asyncio
import json
import sys
import uuid

import httpx
from a2a.client import ClientConfig, create_client
from a2a.types import (
    CancelTaskRequest, GetTaskRequest, ListTasksRequest, Message, Part, Role,
    SendMessageConfiguration, SendMessageRequest, TaskState,
)


def msg(prefix: str, text: str = 'hello') -> Message:
    return Message(role=Role.ROLE_USER, message_id=f'{prefix}-{uuid.uuid4()}', parts=[Part(text=text)])


def check(name: str, ok: bool, detail: object) -> None:
    print(('PASS ' if ok else 'FAIL ') + name + ': ' + str(detail))
    if not ok:
        sys.exit(1)


async def last(events):
    result = None
    async for event in events:
        result = event
    return result


async def main() -> None:
    base, token = sys.argv[1], sys.argv[2]
    http = httpx.AsyncClient(timeout=60, headers={'Authorization': f'Bearer {token}'})
    client = await create_client(base, client_config=ClientConfig(streaming=False, httpx_client=http, supported_protocol_bindings=['JSONRPC']))

    event = await last(client.send_message(SendMessageRequest(message=msg('tck-complete-task'))))
    task = event.task
    check('SendMessage blocking', task.status.state == TaskState.TASK_STATE_COMPLETED and task.artifacts[0].parts[0].text == 'Hello from TCK', TaskState.Name(task.status.state))
    completed = task.id

    event = await last(client.send_message(SendMessageRequest(message=msg('tck-input-required'), configuration=SendMessageConfiguration(return_immediately=True))))
    waiting = event.task.id
    check('SendMessage returnImmediately', event.task.id != '', TaskState.Name(event.task.status.state))

    fetched = await client.get_task(GetTaskRequest(id=waiting))
    check('GetTask', fetched.status.state == TaskState.TASK_STATE_INPUT_REQUIRED, TaskState.Name(fetched.status.state))

    listed = await client.list_tasks(ListTasksRequest(page_size=1))
    check('ListTasks page', len(listed.tasks) == 1 and listed.next_page_token != '' and listed.total_size >= 2, f'{len(listed.tasks)} tasks, total {listed.total_size}')
    rest = await client.list_tasks(ListTasksRequest(page_size=100, page_token=listed.next_page_token))
    check('ListTasks next page', rest.next_page_token == '' and len(rest.tasks) == listed.total_size - 1, f'{len(rest.tasks)} tasks')

    # Stage 4 (A2A-MT3): a task waiting for input is answered with a message
    # that names it; the fixture completes it once the answer is taken in.
    event = await last(client.send_message(SendMessageRequest(message=msg('tck-input-required'), configuration=SendMessageConfiguration(return_immediately=True))))
    asked = event.task
    answer = msg('tck-complete-task', 'the answer')
    answer.task_id = asked.id
    answer.context_id = asked.context_id
    event = await last(client.send_message(SendMessageRequest(message=answer)))
    answered = event.task
    check('SendMessage continuing a task', answered.id == asked.id and answered.status.state == TaskState.TASK_STATE_COMPLETED and [m.parts[0].text for m in answered.history][-1] == 'the answer', TaskState.Name(answered.status.state))

    cancelled = await client.cancel_task(CancelTaskRequest(id=waiting))
    check('CancelTask', cancelled.status.state == TaskState.TASK_STATE_CANCELED, TaskState.Name(cancelled.status.state))

    for name, call, code in (
        ('CancelTask on a terminal task', lambda: client.cancel_task(CancelTaskRequest(id=completed)), 'TaskNotCancelable'),
        ('GetTask on an unknown task', lambda: client.get_task(GetTaskRequest(id=str(uuid.uuid4()))), 'TaskNotFound'),
    ):
        try:
            await call()
            check(name, False, 'no error')
        except Exception as error:  # noqa: BLE001
            check(name, code in type(error).__name__, type(error).__name__)

    event = await last(client.send_message(SendMessageRequest(message=msg('tck-message-response'))))
    check('SendMessage answered with a message', event.WhichOneof('payload') == 'message', event.WhichOneof('payload'))

    # A client of its own: the SDK's client carries A2A-Version by default.
    raw = await httpx.AsyncClient(headers={'Authorization': f'Bearer {token}'}).post(base, json={'jsonrpc': '2.0', 'id': 'v', 'method': 'GetTask', 'params': {'id': completed}})
    check('No A2A-Version is 0.3', raw.json().get('error', {}).get('code') == -32009, raw.text[:200])

    anonymous = await httpx.AsyncClient().post(base, headers={'A2A-Version': '1.0'}, json={'jsonrpc': '2.0', 'id': 'a', 'method': 'GetTask', 'params': {'id': completed}})
    check('No token is 401', anonymous.status_code == 401, anonymous.status_code)

    await client.close()
    print('RESULT ' + json.dumps({'cells': 'all passed'}))


if __name__ == '__main__':
    asyncio.run(main())
