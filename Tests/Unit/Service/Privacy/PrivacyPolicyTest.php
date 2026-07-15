<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Privacy;

use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Service\Privacy\ContentRedactor;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(PrivacyPolicy::class)]
final class PrivacyPolicyTest extends TestCase
{
    #[Test]
    public function levelDefaultsToMetadataWhenUnset(): void
    {
        self::assertSame(PrivacyLevel::METADATA, $this->policy([])->level());
    }

    #[Test]
    public function levelDefaultsToMetadataWhenInvalid(): void
    {
        self::assertSame(PrivacyLevel::METADATA, $this->policy(['privacy' => ['level' => 'bogus']])->level());
    }

    #[Test]
    public function levelReadsConfiguredValue(): void
    {
        self::assertSame(PrivacyLevel::FULL, $this->policy(['privacy' => ['level' => 'full']])->level());
    }

    #[Test]
    public function retentionDaysDefaultsToThirtyWhenUnset(): void
    {
        self::assertSame(30, $this->policy([])->retentionDays());
    }

    #[Test]
    public function retentionDaysClampsZeroAndNegativeToDefault(): void
    {
        self::assertSame(30, $this->policy(['privacy' => ['retentionDays' => '0']])->retentionDays());
        self::assertSame(30, $this->policy(['privacy' => ['retentionDays' => '-5']])->retentionDays());
    }

    #[Test]
    public function retentionDaysReadsConfiguredValue(): void
    {
        self::assertSame(7, $this->policy(['privacy' => ['retentionDays' => '7']])->retentionDays());
    }

    #[Test]
    public function filterContentReturnsNullForNullRegardlessOfLevel(): void
    {
        self::assertNull($this->policy(['privacy' => ['level' => 'full']])->filterContent(null));
    }

    #[Test]
    public function filterContentDropsContentAtNoneAndMetadata(): void
    {
        self::assertNull($this->policy(['privacy' => ['level' => 'none']])->filterContent('secret payload'));
        self::assertNull($this->policy(['privacy' => ['level' => 'metadata']])->filterContent('secret payload'));
    }

    #[Test]
    public function filterContentReturnsContentVerbatimAtFull(): void
    {
        self::assertSame(
            'verbatim payload',
            $this->policy(['privacy' => ['level' => 'full']])->filterContent('verbatim payload'),
        );
    }

    #[Test]
    public function filterContentRedactsAtRedactedLevel(): void
    {
        $out = $this->policy(['privacy' => ['level' => 'redacted']])->filterContent('mail me at john@example.com');

        self::assertIsString($out);
        self::assertStringNotContainsString('john@example.com', $out);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function policy(array $config): PrivacyPolicy
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($config);

        return new PrivacyPolicy($extensionConfiguration, new ContentRedactor());
    }
}
