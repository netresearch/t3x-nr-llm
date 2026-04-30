<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\DTO;

use JsonSerializable;

/**
 * Typed value object for `Provider::$options` (REC #6 slice 20).
 *
 * `tx_nrllm_provider.options` is a free-form JSON column whose label in
 * TCA reads "Additional Options" and whose description reads "Additional
 * provider-specific options as JSON" — by design it carries
 * adapter-extras that go BEYOND the typed entity columns
 * (`api_key`, `endpoint_url`, `api_timeout`, `max_retries`,
 * `organization_id`). The placeholder shipped in TCA is
 * `{"custom_header": "value"}` and real test fixtures use `proxy`,
 * `custom_param`, etc., so the field is genuinely open-ended.
 *
 * That open-endedness is why slices 16e/16f initially stopped at the
 * `array<string, mixed>` surface. This slice walks the rest of the way:
 * a small set of well-known transport-level keys gets typed (`proxy`,
 * `customHeaders`) and everything else flows through `$extra` as
 * `array<string, mixed>` — same permissive shape callers already see
 * via `Provider::getOptionsArray()`, just behind a typed accessor.
 *
 * The DTO is the typed application-level surface; the entity still
 * persists JSON to keep Extbase property mapping working unchanged
 * (`Provider::$options` stays a `string` column). Round-trip is via
 * `fromJson()` / `toJson()`. `fromArray()` is permissive — unknown keys
 * are not dropped, they go into `$extra`, so a hand-edited DB row never
 * silently loses data.
 *
 * Constructor contract: like the sibling DTOs (`FallbackChain`,
 * `CapabilitySet`, `ModelSelectionCriteria`), the public constructor
 * TRUSTS its input. `fromArray()` and `fromJson()` are the safe entry
 * points for arbitrary input — they extract the well-known keys and
 * funnel the rest into `$extra` without throwing.
 */
final readonly class ProviderOptions implements JsonSerializable
{
    /**
     * @param string|null           $proxy         HTTP proxy URL (e.g. `http://proxy.example.com:3128`).
     *                                             Null means "no proxy override" — the adapter uses its
     *                                             process-default routing.
     * @param array<string, string> $customHeaders Extra HTTP headers to send on every API call.
     *                                             Keys are header names, values are header values.
     *                                             Both must be strings; non-string values are dropped
     *                                             by `fromArray()` rather than silently coerced.
     * @param array<string, mixed>  $extra         Adapter-specific keys that don't fit a well-known
     *                                             slot. Preserved verbatim through round-trip so a
     *                                             hand-edited DB row never loses data when it passes
     *                                             through this DTO.
     */
    public function __construct(
        public ?string $proxy = null,
        public array $customHeaders = [],
        public array $extra = [],
    ) {}

    /**
     * Build from an arbitrary array (e.g. `json_decode($options, true)`).
     *
     * Well-known keys are extracted into typed properties; everything
     * else lands in `$extra`. Type-mismatches on the well-known keys
     * are dropped silently — the goal is "never crash on a hand-edited
     * row", not "validate every byte". Callers that need strict
     * validation should run their own checks before construction.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $proxy = null;
        if (isset($data['proxy']) && is_string($data['proxy']) && $data['proxy'] !== '') {
            $proxy = $data['proxy'];
        }

        $customHeaders = [];
        if (isset($data['customHeaders']) && is_array($data['customHeaders'])) {
            foreach ($data['customHeaders'] as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $customHeaders[$name] = $value;
                }
            }
        }

        $extra = $data;
        unset($extra['proxy'], $extra['customHeaders']);

        // Re-key extra so PHPStan / consumers see `array<string, mixed>`
        // (json_decode of a JSON object always yields string keys, but
        // a programmatic caller could hand us a list — defend against it).
        $extraStringKeyed = [];
        foreach ($extra as $key => $value) {
            if (is_string($key)) {
                $extraStringKeyed[$key] = $value;
            }
        }

        return new self(
            proxy: $proxy,
            customHeaders: $customHeaders,
            extra: $extraStringKeyed,
        );
    }

    /**
     * Build from the JSON string the entity persists.
     *
     * Empty string and any non-object JSON yield an empty options
     * object so consumers never have to null-check before reading.
     */
    public static function fromJson(string $json): self
    {
        if ($json === '') {
            return new self();
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return new self();
        }
        /** @var array<string, mixed> $decoded */
        return self::fromArray($decoded);
    }

    /**
     * Convert to an array suitable for `json_encode()` or for merging
     * into an adapter-init config.
     *
     * Empty well-known fields (null `proxy`, empty `customHeaders`)
     * are omitted so a freshly constructed empty DTO round-trips to
     * `[]` rather than `['proxy' => null, 'customHeaders' => []]`.
     *
     * Extra-key collisions: a key in `$extra` that shadows a
     * well-known key (e.g. `extra: ['proxy' => 'foo']`) is silently
     * overwritten by the typed field on output. This is intentional —
     * the typed field is the source of truth; if a caller stuffs
     * `proxy` into `$extra` directly, the next round-trip
     * (`fromArray(toArray())`) will normalise it back into the typed
     * slot.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = $this->extra;

        if ($this->customHeaders !== []) {
            $out['customHeaders'] = $this->customHeaders;
        } else {
            unset($out['customHeaders']);
        }

        if ($this->proxy !== null) {
            $out['proxy'] = $this->proxy;
        } else {
            unset($out['proxy']);
        }

        return $out;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * True when no proxy, no custom headers, and no extras are set.
     * Useful when the caller wants to persist `''` rather than `'[]'`
     * for an empty options object (matches the `LlmConfiguration`
     * `setOptions('')` convention for "nothing configured").
     */
    public function isEmpty(): bool
    {
        return $this->proxy === null
            && $this->customHeaders === []
            && $this->extra === [];
    }

    /**
     * Read a single key — checks the well-known typed fields first,
     * then falls through to `$extra`. Returns `$default` (null by
     * default) when the key is absent.
     *
     * Mirrors the read pattern that `getOptionsArray()` callers
     * already use (`$options['proxy'] ?? null`) so migration is a
     * straight substitution.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return match ($key) {
            'proxy' => $this->proxy ?? $default,
            'customHeaders' => $this->customHeaders === [] ? $default : $this->customHeaders,
            // `array_key_exists` instead of `??` so a stored `null`
            // value is preserved instead of being replaced with the
            // default — keeps `get()` and `has()` semantics aligned
            // (the latter already uses `array_key_exists`).
            default => array_key_exists($key, $this->extra) ? $this->extra[$key] : $default,
        };
    }

    /**
     * Membership check across well-known fields and `$extra`. A
     * well-known field counts as "present" only when it carries a
     * non-default value — `proxy` set to null or `customHeaders` set
     * to `[]` does NOT register, mirroring the `toArray()` omission
     * rule.
     */
    public function has(string $key): bool
    {
        return match ($key) {
            'proxy' => $this->proxy !== null,
            'customHeaders' => $this->customHeaders !== [],
            default => array_key_exists($key, $this->extra),
        };
    }

    /**
     * Return a new instance with the proxy set (or cleared via null).
     */
    public function withProxy(?string $proxy): self
    {
        if ($proxy === '') {
            $proxy = null;
        }
        return new self(
            proxy: $proxy,
            customHeaders: $this->customHeaders,
            extra: $this->extra,
        );
    }

    /**
     * Return a new instance with custom headers replaced.
     *
     * Non-string keys / values in `$headers` are dropped (mirrors
     * `fromArray()`'s normalisation) so the typed property always
     * carries `array<string, string>`.
     *
     * @param array<string, string> $headers
     */
    public function withCustomHeaders(array $headers): self
    {
        $sanitised = [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $sanitised[$name] = $value;
            }
        }
        return new self(
            proxy: $this->proxy,
            customHeaders: $sanitised,
            extra: $this->extra,
        );
    }

    /**
     * Return a new instance with an extra key set.
     *
     * Cannot be used to overwrite the well-known typed fields —
     * passing `proxy` or `customHeaders` here is a no-op and the
     * caller should use `withProxy()` / `withCustomHeaders()` instead.
     * Returning the same instance (rather than throwing) keeps the
     * fluent chain robust against accidental misuse.
     */
    public function withExtra(string $key, mixed $value): self
    {
        if ($key === 'proxy' || $key === 'customHeaders') {
            return $this;
        }
        $extra = $this->extra;
        $extra[$key] = $value;
        return new self(
            proxy: $this->proxy,
            customHeaders: $this->customHeaders,
            extra: $extra,
        );
    }
}
