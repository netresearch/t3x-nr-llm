<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * A minimal A2A JSON-RPC endpoint for A2aVaultTransportTest, run with `php -S`.
 *
 * /rpc       answers GetTask with a task and echoes the request id.
 * /redirect  answers 307 to /target.
 * /target    must never be reached; every hit is counted.
 * /big       answers a body larger than any limit the test sets.
 *
 * Every request appends a line to the log file named by A2A_ROUTER_LOG.
 */

$log  = (string) getenv('A2A_ROUTER_LOG');
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$body = (string) file_get_contents('php://input');
file_put_contents($log, json_encode([
    'path'    => $path,
    'host'    => $_SERVER['HTTP_HOST'] ?? '',
    'version' => $_SERVER['HTTP_A2A_VERSION'] ?? '',
    'auth'    => isset($_SERVER['HTTP_AUTHORIZATION']) ? 'present' : 'absent',
]) . "\n", FILE_APPEND);

header('Content-Type: application/json');
if ($path === '/redirect') {
    http_response_code(307);
    header('Location: /target');

    return true;
}

if ($path === '/big') {
    echo str_repeat('x', 200000);

    return true;
}

$request = json_decode($body, true);
$id      = is_array($request) ? ($request['id'] ?? null) : null;
echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['id' => 't-1', 'status' => ['state' => 'TASK_STATE_WORKING']]]);

return true;
