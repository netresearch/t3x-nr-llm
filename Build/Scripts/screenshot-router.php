<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * Router for `php -S` in Build/Scripts/screenshots.sh.
 *
 * Handing index.php to the built-in server directly makes it the router for
 * EVERY request, including /_assets/*, so the backend renders without a single
 * stylesheet — which is invisible in a test that only asserts text and fatal
 * for a screenshot. Returning false here tells the server to serve a real file
 * itself and to route only what does not exist.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = __DIR__ . '/../../.Build/Web' . (is_string($path) ? $path : '/');

if ($path !== '/' && is_string($path) && is_file($file)) {
    return false;
}

require __DIR__ . '/../../.Build/Web/index.php';
