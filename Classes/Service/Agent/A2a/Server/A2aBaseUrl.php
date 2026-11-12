<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

/**
 * A site's configured A2A base URL (spec 051 A2A-SV1, A2A-SV2, ADR-280).
 *
 * Everything the server puts into a URL comes from here, never from the
 * request. A request is an A2A request only when its scheme, host and port
 * equal the base's and its path lies below the base's path.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aBaseUrl
{
    public const CARD_PATH = '/.well-known/agent-card.json';

    private function __construct(
        public string $scheme,
        public string $host,
        public int $port,
        public string $path,
    ) {}

    /**
     * Null for anything but an absolute http(s) URL with a host and without
     * credentials, a query or a fragment.
     */
    public static function fromString(string $value): ?self
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $parts = parse_url($value);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host   = strtolower($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $path = rtrim($parts['path'] ?? '', '/');

        return new self($scheme, $host, $parts['port'] ?? ($scheme === 'https' ? 443 : 80), $path);
    }

    /**
     * The JSON-RPC endpoint, as the card publishes it.
     */
    public function endpoint(): string
    {
        return $this->origin() . ($this->path === '' ? '/' : $this->path);
    }

    public function cardUrl(): string
    {
        return $this->origin() . $this->path . self::CARD_PATH;
    }

    public function origin(): string
    {
        $default = $this->scheme === 'https' ? 443 : 80;

        return $this->scheme . '://' . $this->host . ($this->port === $default ? '' : ':' . $this->port);
    }

    /**
     * Whether a request's scheme, host and port are this base's.
     */
    public function matchesOrigin(string $scheme, string $host, ?int $port): bool
    {
        $scheme = strtolower($scheme);

        return $scheme === $this->scheme
            && strtolower($host) === $this->host
            && ($port ?? ($scheme === 'https' ? 443 : 80)) === $this->port;
    }

    /**
     * The request path relative to the base path ('' for the base itself),
     * null when the path is not below the base.
     */
    public function relativePath(string $requestPath): ?string
    {
        if ($this->path === '') {
            return rtrim($requestPath, '/');
        }

        if ($requestPath === $this->path || $requestPath === $this->path . '/') {
            return '';
        }

        return str_starts_with($requestPath, $this->path . '/') ? substr($requestPath, strlen($this->path)) : null;
    }
}
