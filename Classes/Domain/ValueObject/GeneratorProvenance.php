<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * Verified, content-free identity of one successful serving terminal (ADR-220). @internal.
 */
final readonly class GeneratorProvenance
{
    public const METADATA_KEY = 'nr_llm_serving_generator';

    private function __construct(
        public string $providerIdentifier,
        public string $modelId,
        public string $reportedModelId,
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        if (!is_array($value)) {
            return null;
        }

        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['modelId', 'providerIdentifier', 'reportedModelId', 'version'] || $value['version'] !== 1 || !is_string($value['providerIdentifier']) || !is_string($value['modelId']) || !is_string($value['reportedModelId']) || preg_match('/\A[A-Za-z0-9_.-]{1,100}\z/', $value['providerIdentifier']) !== 1 || !self::validModelIdentifier($value['modelId']) || !self::validModelIdentifier($value['reportedModelId'])) {
            return null;
        }

        return new self(
            $value['providerIdentifier'],
            $value['modelId'],
            $value['reportedModelId'],
        );
    }

    private static function validModelIdentifier(string $value): bool
    {
        return preg_match('~\A[A-Za-z0-9_./:@+-]{1,150}\z~', $value) === 1 && !str_contains($value, '://');
    }

    /**
     * @return array{version: 1, providerIdentifier: string, modelId: string, reportedModelId: string}
     */
    public function toArray(): array
    {
        return [
            'version' => 1,
            'providerIdentifier' => $this->providerIdentifier,
            'modelId' => $this->modelId,
            'reportedModelId' => $this->reportedModelId,
        ];
    }

    public function sameGenerator(self $other): bool
    {
        return $this->providerIdentifier === $other->providerIdentifier && $this->modelId === $other->modelId && $this->reportedModelId === $other->reportedModelId;
    }
}
