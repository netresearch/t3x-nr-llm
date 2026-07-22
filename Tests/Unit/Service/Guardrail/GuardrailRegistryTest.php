<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Guardrail;

use LogicException;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Service\Guardrail\GuardrailInterface;
use Netresearch\NrLlm\Service\Guardrail\GuardrailRegistry;
use Netresearch\NrLlm\Service\Guardrail\InputGuardrailInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GuardrailRegistry::class)]
final class GuardrailRegistryTest extends TestCase
{
    #[Test]
    public function resolvesMandatoryPerIdentifierAndListsOnlyOptionalAsSelectable(): void
    {
        $registry = new GuardrailRegistry(
            [$this->outputGuardrail('secret-redaction', true), $this->outputGuardrail('content-filter', false)],
            [$this->inputGuardrail('secret-redaction', true)],
        );

        self::assertTrue($registry->isMandatoryIdentifier('secret-redaction'));
        self::assertFalse($registry->isMandatoryIdentifier('content-filter'));
        // Mandatory ids are never offered as selectable; the shared id is deduped.
        self::assertSame(['content-filter'], $registry->selectableIdentifiers());
    }

    #[Test]
    public function anyMandatorySideMakesTheIdentifierMandatory(): void
    {
        // Both sides agree mandatory — the identifier is mandatory and unlisted.
        $registry = new GuardrailRegistry(
            [$this->outputGuardrail('secret-redaction', true)],
            [$this->inputGuardrail('secret-redaction', true)],
        );

        self::assertTrue($registry->isMandatoryIdentifier('secret-redaction'));
        self::assertSame([], $registry->selectableIdentifiers());
    }

    #[Test]
    public function crossSideMandatoryDisagreementFailsClosed(): void
    {
        // The output side says mandatory, the input twin says optional — a
        // copy-paste error that would let a config disable one axis of a
        // security guardrail. The build must throw rather than ship it.
        $registry = new GuardrailRegistry(
            [$this->outputGuardrail('secret-redaction', true)],
            [$this->inputGuardrail('secret-redaction', false)],
        );

        $this->expectException(LogicException::class);
        $registry->isMandatoryIdentifier('secret-redaction');
    }

    #[Test]
    public function selectableIdentifiersAreSortedAndDeduped(): void
    {
        $registry = new GuardrailRegistry(
            [$this->outputGuardrail('zeta', false), $this->outputGuardrail('alpha', false), $this->outputGuardrail('alpha', false)],
            [],
        );

        self::assertSame(['alpha', 'zeta'], $registry->selectableIdentifiers());
    }

    private function outputGuardrail(string $id, bool $mandatory): GuardrailInterface
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

    private function inputGuardrail(string $id, bool $mandatory): InputGuardrailInterface
    {
        return new class ($id, $mandatory) implements InputGuardrailInterface {
            public function __construct(private readonly string $id, private readonly bool $mandatory) {}

            public function getIdentifier(): string
            {
                return $this->id;
            }

            public function isMandatory(): bool
            {
                return $this->mandatory;
            }

            public function checkInput(string $text): GuardrailResult
            {
                return GuardrailResult::allow();
            }
        };
    }
}
