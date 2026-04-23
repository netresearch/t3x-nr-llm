<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

/**
 * Identifies which provider operation a middleware call corresponds to.
 *
 * Middleware uses this to decide whether it applies (e.g. a cache middleware
 * that caches deterministic embeddings but passes through stateful chat
 * completions) and to emit meaningful log / trace / metric labels.
 */
enum ProviderOperation: string
{
    case Chat = 'chat';
    case Completion = 'complete';
    case Embedding = 'embed';
    case Vision = 'vision';
    case Tools = 'tools';
    case Stream = 'stream';
}
