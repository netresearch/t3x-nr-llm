<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

use function is_string;
use function ltrim;
use function parse_url;
use function preg_match;
use function preg_replace;
use function rawurlencode;
use function rtrim;
use function sprintf;
use function str_contains;

use const PHP_URL_PATH;

/**
 * Where an Azure OpenAI request goes, and how it authenticates (ADR-247).
 *
 * Azure serves two URL shapes, and which one a resource uses is the
 * operator's choice, made with the endpoint URL:
 *
 * - The v1 API: an endpoint ending in `/openai/v1`. Requests go to the
 *   OpenAI paths below it (`/openai/v1/chat/completions`), the deployment name
 *   is the body's `model`, and no `api-version` is sent.
 * - The deployment API: any other endpoint, normally the bare resource URL
 *   (`https://<resource>.openai.azure.com`). Requests go to
 *   `/openai/deployments/<deployment>/<operation>?api-version=<version>`, the
 *   deployment being the model id of the call.
 *
 * Both take the key in the `api-key` header, never as a bearer token.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class AzureOpenAiEndpoint
{
    /** The header Azure OpenAI reads the key from. */
    public const KEY_HEADER = 'api-key';

    /** The latest generally available version of the deployment API. */
    public const DEFAULT_API_VERSION = '2024-10-21';

    /** The provider option (Provider record, options JSON) that sets the version. */
    public const OPTION_API_VERSION = 'apiVersion';

    /**
     * Whether the endpoint names the v1 API.
     */
    public static function isV1(string $baseUrl): bool
    {
        $path = parse_url($baseUrl, PHP_URL_PATH);

        return is_string($path) && preg_match('#/openai/v1/?$#', $path) === 1;
    }

    /**
     * The URL of one operation (`chat/completions`, `embeddings`, `responses`,
     * `models`) for a deployment.
     */
    public static function url(string $baseUrl, string $operation, string $deployment, string $apiVersion): string
    {
        $operation = ltrim($operation, '/');
        // A query or fragment pasted with the endpoint would swallow the
        // operation path; the version travels in its own option.
        $base = rtrim((string) preg_replace('/[?#].*$/s', '', $baseUrl), '/');

        if (self::isV1($base)) {
            return $base . '/' . $operation;
        }

        // The resource root: a configured "/openai" suffix is the API prefix,
        // not part of the resource.
        $root    = (string) preg_replace('#/openai$#', '', $base);
        $version = rawurlencode(self::apiVersion($apiVersion));

        return match ($operation) {
            // Not per deployment: the resource's model list, and the Responses
            // API, which the deployment API serves only in preview versions and
            // the v1 API serves generally.
            'models'    => sprintf('%s/openai/models?api-version=%s', $root, $version),
            'responses' => $root . '/openai/v1/responses',
            default     => sprintf('%s/openai/deployments/%s/%s?api-version=%s', $root, rawurlencode($deployment), $operation, $version),
        };
    }

    /**
     * The configured version when it has the shape of one (`2024-10-21`,
     * `2025-04-01-preview`), else the default: the value ends up in a URL.
     */
    public static function apiVersion(string $configured): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}(-preview)?$/', $configured) === 1 ? $configured : self::DEFAULT_API_VERSION;
    }

    /**
     * Whether a host is an Azure OpenAI resource.
     */
    public static function isAzureHost(string $host): bool
    {
        return str_contains($host, '.') && preg_match('/\.(openai\.azure\.com|services\.ai\.azure\.com|cognitiveservices\.azure\.com)$/i', $host) === 1;
    }
}
