# SPDX-License-Identifier: Apache-2.0
# SPDX-FileCopyrightText: 2025 The A2A Authors; 2026 Netresearch DTT GmbH
"""The interop partner of nr-llm's A2A client (spec 051, A2A-S0-5).

Adapted from a2aproject/a2a-python's sample `hello_world_agent.py` (Apache-2.0),
as run in the stage-0 spike: the same executor (SUBMITTED, WORKING with a status
message, one second of work, one text artifact, COMPLETED), the same in-memory
task store, but only the HTTP transports, a bind address separate from the
public URL the card advertises, and an HTTP+JSON interface listed before the
JSON-RPC one so the client's interface selection is exercised.
"""

import argparse
import asyncio
import logging

import uvicorn

from fastapi import FastAPI

from a2a.server.agent_execution.agent_executor import AgentExecutor
from a2a.server.agent_execution.context import RequestContext
from a2a.server.events.event_queue import EventQueue
from a2a.server.request_handlers import DefaultRequestHandler
from a2a.server.routes import (
    add_a2a_routes_to_fastapi,
    create_agent_card_routes,
    create_jsonrpc_routes,
    create_rest_routes,
)
from a2a.server.tasks.inmemory_task_store import InMemoryTaskStore
from a2a.server.tasks.task_updater import TaskUpdater
from a2a.types import (
    AgentCapabilities,
    AgentCard,
    AgentInterface,
    AgentSkill,
    Part,
    Task,
    TaskState,
    TaskStatus,
)


class InteropExecutor(AgentExecutor):
    def __init__(self) -> None:
        self.running_tasks: set[str] = set()

    async def cancel(self, context: RequestContext, event_queue: EventQueue) -> None:
        task_id = context.task_id
        self.running_tasks.discard(task_id or '')
        updater = TaskUpdater(event_queue=event_queue, task_id=task_id or '', context_id=context.context_id or '')
        await updater.cancel()

    async def execute(self, context: RequestContext, event_queue: EventQueue) -> None:
        message = context.message
        task_id = context.task_id
        context_id = context.context_id
        if not message or not task_id or not context_id:
            return

        self.running_tasks.add(task_id)
        await event_queue.enqueue_event(
            Task(id=task_id, context_id=context_id, status=TaskStatus(state=TaskState.TASK_STATE_SUBMITTED), history=[message])
        )
        updater = TaskUpdater(event_queue=event_queue, task_id=task_id, context_id=context_id)
        await updater.start_work(message=updater.new_agent_message(parts=[Part(text='Processing your question...')]))

        query = context.get_user_input() or ''
        await asyncio.sleep(1)
        if task_id not in self.running_tasks:
            return

        await updater.add_artifact(parts=[Part(text=f'echo: {query}')], name='response', last_chunk=True)
        await updater.complete()


def main() -> None:
    parser = argparse.ArgumentParser(description='A2A interop partner for nr-llm')
    parser.add_argument('--bind', default='0.0.0.0')
    parser.add_argument('--port', type=int, default=18120)
    parser.add_argument('--public-url', required=True, help='Base URL the card advertises, e.g. http://host.docker.internal:18120')
    args = parser.parse_args()
    base = args.public_url.rstrip('/')

    card = AgentCard(
        name='nr-llm interop partner',
        description='a2a-python 1.3.0 sample executor for the nr-llm interop job.',
        version='1.0.0',
        capabilities=AgentCapabilities(streaming=True, push_notifications=False),
        default_input_modes=['text/plain'],
        default_output_modes=['text/plain'],
        skills=[AgentSkill(id='echo', name='Echo', description='Echoes the message.', tags=['interop'])],
        supported_interfaces=[
            AgentInterface(protocol_binding='HTTP+JSON', protocol_version='1.0', url=f'{base}/a2a/rest'),
            AgentInterface(protocol_binding='JSONRPC', protocol_version='0.3', url=f'{base}/a2a/jsonrpc'),
            AgentInterface(protocol_binding='JSONRPC', protocol_version='1.0', url=f'{base}/a2a/jsonrpc'),
        ],
    )
    handler = DefaultRequestHandler(agent_executor=InteropExecutor(), task_store=InMemoryTaskStore(), agent_card=card)
    app = FastAPI()
    add_a2a_routes_to_fastapi(
        app,
        agent_card_routes=create_agent_card_routes(agent_card=card),
        jsonrpc_routes=create_jsonrpc_routes(request_handler=handler, rpc_url='/a2a/jsonrpc', enable_v0_3_compat=True),
        rest_routes=create_rest_routes(request_handler=handler, path_prefix='/a2a/rest', enable_v0_3_compat=True),
    )
    logging.basicConfig(level=logging.INFO)
    uvicorn.run(app, host=args.bind, port=args.port)


if __name__ == '__main__':
    main()
