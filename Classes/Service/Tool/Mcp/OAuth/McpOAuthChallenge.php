<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

/**
 * The Bearer challenge of a `WWW-Authenticate` header (RFC 6750 section 3,
 * RFC 9728 section 5.1; ADR-259).
 *
 * A header can carry several challenges ("Basic realm=x, Bearer scope=y"), and
 * a parameter value can be a quoted string holding commas. The parser walks the
 * header as RFC 9110 section 11.6.1 lays it out — a scheme token, then
 * `name=value` pairs, until a token that is not followed by `=` starts the
 * next scheme — and keeps the parameters of the Bearer challenge only.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class McpOAuthChallenge
{
    /**
     * @param array<string, string> $parameters lower-cased names
     */
    private function __construct(private array $parameters) {}

    public static function fromHeader(string $header): self
    {
        $length     = strlen($header);
        $offset     = 0;
        $scheme     = '';
        $parameters = [];
        while ($offset < $length) {
            $offset += strspn($header, " \t,", $offset);
            if (preg_match('/\G([!#$%&\'*+.^_`|~0-9A-Za-z-]+)/', $header, $match, 0, $offset) !== 1) {
                break;
            }

            $token = $match[1];
            $offset += strlen($token);
            $after = $offset + strspn($header, " \t", $offset);
            if ($after < $length && $header[$after] === '=' && ($after + 1 >= $length || $header[$after + 1] !== '=')) {
                $offset = $after + 1 + strspn($header, " \t", $after + 1);
                $value  = self::value($header, $offset);
                if (strcasecmp($scheme, 'Bearer') === 0) {
                    $parameters[strtolower($token)] ??= $value;
                }

                continue;
            }

            // A token without "=" starts a new challenge (token68 values are
            // not used by Bearer and are skipped with the scheme).
            if (strcasecmp($scheme, 'Bearer') === 0) {
                break;
            }

            $scheme = $token;
        }

        return new self($parameters);
    }

    public function parameter(string $name): string
    {
        return $this->parameters[strtolower($name)] ?? '';
    }

    private static function value(string $header, int &$offset): string
    {
        if (($header[$offset] ?? '') !== '"') {
            preg_match('/\G[^\s,]*/', $header, $match, 0, $offset);
            $offset += strlen($match[0] ?? '');

            return $match[0] ?? '';
        }

        $value = '';
        ++$offset;
        $length = strlen($header);
        while ($offset < $length) {
            $char = $header[$offset++];
            if ($char === '\\' && $offset < $length) {
                $value .= $header[$offset++];

                continue;
            }

            if ($char === '"') {
                break;
            }

            $value .= $char;
        }

        return $value;
    }
}
