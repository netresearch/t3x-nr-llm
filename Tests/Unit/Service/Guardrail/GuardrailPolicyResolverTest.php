<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Guardrail;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Service\Guardrail\GuardrailInterface;
use Netresearch\NrLlm\Service\Guardrail\GuardrailPolicyResolver;
use Netresearch\NrLlm\Service\Guardrail\GuardrailRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GuardrailPolicyResolver::class)]
final class GuardrailPolicyResolverTest extends TestCase
{
    private GuardrailInterface $mandatory;

    private GuardrailInterface $optional;

    private GuardrailPolicyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mandatory = $this->guardrail('secret-redaction', true);
        $this->optional  = $this->guardrail('content-filter', false);
        $this->resolver  = new GuardrailPolicyResolver(new GuardrailRegistry([$this->mandatory, $this->optional], []));
    }

    #[Test]
    public function aNullConfigurationRunsEveryGuardrail(): void
    {
        self::assertSame(
            [$this->mandatory, $this->optional],
            $this->resolver->filter([$this->mandatory, $this->optional], null),
        );
    }

    #[Test]
    public function anEmptySelectionRunsEveryGuardrail(): void
    {
        self::assertSame(
            [$this->mandatory, $this->optional],
            $this->resolver->filter([$this->mandatory, $this->optional], $this->config('')),
        );
    }

    #[Test]
    public function aSelectionKeepsMandatoryPlusTheSelectedOptional(): void
    {
        // Selecting only content-filter keeps it AND the mandatory guardrail.
        self::assertSame(
            [$this->mandatory, $this->optional],
            $this->resolver->filter([$this->mandatory, $this->optional], $this->config('content-filter')),
        );
    }

    #[Test]
    public function aSelectionDropsUnselectedOptionalButNeverMandatory(): void
    {
        // Selecting an unrelated id drops the optional guardrail but the
        // mandatory one is kept unconditionally.
        self::assertSame(
            [$this->mandatory],
            $this->resolver->filter([$this->mandatory, $this->optional], $this->config('something-else')),
        );
    }

    #[Test]
    public function anAllUnknownSelectionKeepsOnlyMandatory(): void
    {
        // A stale '0' or garbage value yields a non-empty list -> filtering mode
        // -> every optional dropped, the mandatory floor preserved (secure).
        self::assertSame(
            [$this->mandatory],
            $this->resolver->filter([$this->mandatory, $this->optional], $this->config('0')),
        );
    }

    private function config(string $allowedGuardrails): LlmConfiguration
    {
        $config = new LlmConfiguration();
        $config->setAllowedGuardrails($allowedGuardrails);

        return $config;
    }

    private function guardrail(string $id, bool $mandatory): GuardrailInterface
    {
        return new class ($id, $mandatory) implements GuardrailInterface {
            public function __construct(private readonly string $id, private readonly bool $mandatory) {}

            public function getIdentifier(): string
            {
                return $this->id;
            }

            public function isMandatory(): bool
            {
                return $this->mandatory;
            }

            public function checkOutput(CompletionResponse $response): GuardrailResult
            {
                return GuardrailResult::allow();
            }
        };
    }
}
