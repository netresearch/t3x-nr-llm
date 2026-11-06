<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Doctrine\DBAL\Exception\ConnectionException;
use Netresearch\NrLlm\Domain\Enum\ExceptionHintAdmission;
use Netresearch\NrLlm\Domain\Enum\ExceptionHintRefusal;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ExceptionHint;
use Netresearch\NrLlm\Domain\ValueObject\LogExceptionEntry;
use Netresearch\NrLlm\Provider\Middleware\CacheMiddleware;
use Netresearch\NrLlm\Provider\Middleware\TelemetryMiddleware;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\ExceptionHintService;
use Netresearch\NrLlm\Service\Tool\SourcePathGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What a hint sends and how it is labelled (ADR-258).
 */
#[CoversClass(ExceptionHintService::class)]
#[CoversClass(ExceptionHint::class)]
final class ExceptionHintServiceTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private array $sent = [];

    /** @var array<mixed> */
    private array $metadata = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/nrllm-hint-' . bin2hex(random_bytes(4));
        mkdir($this->root);
        $this->root = (string) realpath($this->root);
        $this->sent = [];
    }

    protected function tearDown(): void
    {
        rmdir($this->root);
        parent::tearDown();
    }

    private function configuration(TrustZone $zone): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setIdentifier('p');
        $provider->setTrustZone($zone->value);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('hints');
        $configuration->setLlmModel($model);

        return $configuration;
    }

    private function entry(): LogExceptionEntry
    {
        return new LogExceptionEntry(
            timestamp: 1,
            level: 'CRITICAL',
            component: 'TYPO3.CMS.Core.Error.ProductionExceptionHandler',
            message: 'Connection refused for mysql://app:hunter2@db/app',
            exceptionClass: ConnectionException::class,
            frames: [
                ['file' => $this->root . '/vendor/doctrine/dbal/src/Driver.php', 'line' => 12, 'call' => '(throw site)'],
                ['file' => $this->root . '/packages/site/Classes/Repo.php', 'line' => 40, 'call' => "Vendor\\Site\\Repo->find('top-secret', Object(Foo))"],
            ],
        );
    }

    private function service(string $answer, bool $fails = false): ExceptionHintService
    {
        $llm = self::createStub(LlmServiceManagerInterface::class);
        $llm->method('chatWithConfiguration')->willReturnCallback(function (array $messages, LlmConfiguration $configuration, array $metadata) use ($answer, $fails): CompletionResponse {
            $this->metadata = $metadata;
            foreach ($messages as $message) {
                $this->sent[] = $message instanceof ChatMessage ? $message->content : '';
            }

            if ($fails) {
                throw new RuntimeException('HTTP 500 from https://api.example/v1?api_key=sk-123', 8978992057);
            }

            return new CompletionResponse($answer, 'm', new UsageStatistics(1, 1, 2));
        });

        return new ExceptionHintService($llm, new TrustZoneResolver(), new SourcePathGuard($this->root));
    }

    #[Test]
    public function aHintCitingAProjectFrameIsNoGuess(): void
    {
        $hint = $this->service('{"cause": "The database is down.", "next_step": "Check the DB host.", "frame": 1}')
            ->hint($this->entry(), $this->configuration(TrustZone::LOCAL));

        self::assertSame('The database is down.', $hint->cause);
        self::assertSame('Check the DB host.', $hint->nextStep);
        self::assertSame(1, $hint->frame);
        self::assertFalse($hint->guess);
        self::assertFalse($hint->messageWithheld);
    }

    /**
     * A cited vendor frame, a frame the stack does not have, or none: a guess.
     */
    #[Test]
    public function aHintWithoutAProjectFrameIsAGuess(): void
    {
        $vendor = $this->service('{"cause": "c", "next_step": "n", "frame": 0}')->hint($this->entry(), $this->configuration(TrustZone::LOCAL));
        self::assertTrue($vendor->guess);
        self::assertSame(0, $vendor->frame);

        $invented = $this->service('{"cause": "c", "next_step": "n", "frame": 7}')->hint($this->entry(), $this->configuration(TrustZone::LOCAL));
        self::assertTrue($invented->guess);
        self::assertNull($invented->frame);

        $none = $this->service("```json\n{\"cause\": \"c\", \"next_step\": \"n\", \"frame\": null}\n```")->hint($this->entry(), $this->configuration(TrustZone::LOCAL));
        self::assertTrue($none->guess);
        self::assertFalse($none->isRefused());
    }

    /**
     * Call arguments and credentials never reach the model; no source line is sent.
     */
    #[Test]
    public function argumentsAndCredentialsAreNotSent(): void
    {
        $this->service('{"cause": "c", "next_step": "n", "frame": 1}')->hint($this->entry(), $this->configuration(TrustZone::LOCAL));
        $sent = implode("\n", $this->sent);

        self::assertStringContainsString('Vendor\\Site\\Repo->find()', $sent);
        self::assertStringContainsString(ConnectionException::class, $sent);
        self::assertStringNotContainsString('top-secret', $sent);
        self::assertStringNotContainsString('hunter2', $sent);
        self::assertStringContainsString('Connection refused', $sent);
    }

    /**
     * A provider below SECRET_ADJACENT gets no message, and the hint says so.
     */
    #[Test]
    public function aPrivatelyHostedProviderGetsNoMessage(): void
    {
        $hint = $this->service('{"cause": "c", "next_step": "n", "frame": 1}')->hint($this->entry(), $this->configuration(TrustZone::PRIVATE_HOSTED));
        $sent = implode("\n", $this->sent);

        self::assertTrue($hint->messageWithheld);
        self::assertStringNotContainsString('Connection refused', $sent);
        self::assertStringContainsString('Vendor\\Site\\Repo->find()', $sent);
    }

    /**
     * A provider below SYSTEM_DIAGNOSTICS is not asked at all.
     */
    #[Test]
    public function anExternalProviderIsNotAsked(): void
    {
        $hint = $this->service('{"cause": "c", "next_step": "n", "frame": 1}')->hint($this->entry(), $this->configuration(TrustZone::EXTERNAL_EU));

        self::assertTrue($hint->isRefused());
        self::assertSame([], $this->sent);
        self::assertStringContainsString('"hints"', (string) $hint->refusal);
        self::assertSame(ExceptionHintRefusal::ZONE_ADMITS_NO_DIAGNOSTICS, $hint->reason);
        self::assertSame(['hints'], $hint->refusalArguments);
    }

    /**
     * The module marks each configuration with the same answer the hint applies.
     */
    #[Test]
    public function theAdmissionFollowsTheTrustZoneLadder(): void
    {
        $service = $this->service('{}');

        self::assertSame(ExceptionHintAdmission::FULL, $service->admission($this->configuration(TrustZone::LOCAL)));
        self::assertSame(ExceptionHintAdmission::WITHOUT_MESSAGE, $service->admission($this->configuration(TrustZone::PRIVATE_HOSTED)));
        self::assertSame(ExceptionHintAdmission::NONE, $service->admission($this->configuration(TrustZone::EXTERNAL_EU)));
        self::assertSame(ExceptionHintAdmission::NONE, $service->admission($this->configuration(TrustZone::EXTERNAL_GLOBAL)));
    }

    #[Test]
    public function theModelIsAskedInTheGivenLanguageAndEnglishByDefault(): void
    {
        $this->service('{"cause": "c", "next_step": "n", "frame": 1}')->hint($this->entry(), $this->configuration(TrustZone::LOCAL));
        self::assertStringContainsString('"next_step" in English;', implode("\n", $this->sent));

        $this->sent = [];
        $this->service('{"cause": "c", "next_step": "n", "frame": 1}')->hint($this->entry(), $this->configuration(TrustZone::LOCAL), 'German');
        self::assertStringContainsString('"next_step" in German;', implode("\n", $this->sent));
    }

    #[Test]
    public function aFailedCallOrAnUnreadableAnswerIsRefusedWithoutCredentials(): void
    {
        $failed = $this->service('', true)->hint($this->entry(), $this->configuration(TrustZone::LOCAL));
        self::assertTrue($failed->isRefused());
        self::assertSame(ExceptionHintRefusal::CALL_FAILED, $failed->reason);
        self::assertStringNotContainsString('sk-123', (string) $failed->refusal);
        self::assertStringNotContainsString('sk-123', implode(' ', $failed->refusalArguments));
        self::assertStringContainsString('HTTP 500', implode(' ', $failed->refusalArguments));

        $prose = $this->service('The database is probably down.')->hint($this->entry(), $this->configuration(TrustZone::LOCAL));
        self::assertTrue($prose->isRefused());
        self::assertSame(ExceptionHintRefusal::UNREADABLE_ANSWER, $prose->reason);

        $empty = $this->service('{"cause": " ", "next_step": "n"}')->hint($this->entry(), $this->configuration(TrustZone::LOCAL));
        self::assertTrue($empty->isRefused());
    }

    /**
     * Never from the answer cache, and attributed in the usage data.
     */
    #[Test]
    public function theCallBypassesTheAnswerCacheAndNamesItsSource(): void
    {
        $this->service('{"cause": "c", "next_step": "n", "frame": 1}')->hint($this->entry(), $this->configuration(TrustZone::LOCAL));

        self::assertFalse($this->metadata[CacheMiddleware::METADATA_ANSWER_CACHE] ?? null);
        self::assertSame('exception_hint', $this->metadata[TelemetryMiddleware::METADATA_SOURCE_OPERATION] ?? null);
        self::assertSame('nr_llm', $this->metadata[TelemetryMiddleware::METADATA_SOURCE_EXTENSION] ?? null);
    }

    /**
     * Core code inside the project root of a classic-mode installation is no project frame.
     */
    #[Test]
    public function aClassicModeCoreFrameIsAGuess(): void
    {
        $entry = new LogExceptionEntry(1, 'ERROR', 'c', 'm', 'RuntimeException', [
            ['file' => $this->root . '/typo3/sysext/core/Classes/A.php', 'line' => 3, 'call' => '(throw site)'],
        ]);

        self::assertTrue($this->service('{"cause": "c", "next_step": "n", "frame": 0}')->hint($entry, $this->configuration(TrustZone::LOCAL))->guess);

        $compiled = new LogExceptionEntry(1, 'ERROR', 'c', 'm', 'RuntimeException', [
            ['file' => $this->root . '/var/cache/code/fluid_template/Standalone_action_abc.php', 'line' => 3, 'call' => '(throw site)'],
        ]);
        self::assertTrue($this->service('{"cause": "c", "next_step": "n", "frame": 0}')->hint($compiled, $this->configuration(TrustZone::LOCAL))->guess, 'a compiled template is generated, not project code');

        $dotted = new LogExceptionEntry(1, 'ERROR', 'c', 'm', 'RuntimeException', [
            ['file' => $this->root . '/packages/../vendor/lib/A.php', 'line' => 3, 'call' => '(throw site)'],
        ]);
        self::assertTrue($this->service('{"cause": "c", "next_step": "n", "frame": 0}')->hint($dotted, $this->configuration(TrustZone::LOCAL))->guess, 'a path with dot segments');
    }

    /**
     * A logged message has no length limit; what is sent has.
     */
    #[Test]
    public function aLongMessageIsCut(): void
    {
        $long = new LogExceptionEntry(1, 'ERROR', 'c', str_repeat('a', 10000), 'RuntimeException');
        $this->service('{"cause": "c", "next_step": "n", "frame": null}')->hint($long, $this->configuration(TrustZone::LOCAL));

        self::assertStringContainsString(str_repeat('a', 4000), $this->sent[1] ?? '');
        self::assertStringNotContainsString(str_repeat('a', 4001), $this->sent[1] ?? '');
    }

    /**
     * Without class, stack or a message the provider may receive, nothing is asked.
     */
    #[Test]
    public function anEntryWithNothingToSendIsNotAsked(): void
    {
        $bare = new LogExceptionEntry(1, 'ERROR', 'c', 'something failed');

        self::assertTrue($this->service('{"cause": "c", "next_step": "n", "frame": null}')->hint($bare, $this->configuration(TrustZone::PRIVATE_HOSTED))->isRefused());
        self::assertSame([], $this->sent);
        self::assertFalse($this->service('{"cause": "c", "next_step": "n", "frame": null}')->hint($bare, $this->configuration(TrustZone::LOCAL))->isRefused(), 'the message alone is something to ask about');
    }
}
