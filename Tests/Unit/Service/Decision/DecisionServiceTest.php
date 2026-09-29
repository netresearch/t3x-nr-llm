<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Decision;

use LogicException;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\DecisionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\StructuredCompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ChoiceQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionAnswer;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionSubject;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ProbabilityKind;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ScoreQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\SubjectField;
use Netresearch\NrLlm\Domain\ValueObject\Decision\YesNoQuestion;
use Netresearch\NrLlm\Domain\ValueObject\ModelResolution;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Exception\InputContextTrustZoneException;
use Netresearch\NrLlm\Exception\StructuredResponseMismatchException;
use Netresearch\NrLlm\Provider\Exception\InvalidDecisionResponseException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\Budget\BackendUserContextResolverInterface;
use Netresearch\NrLlm\Service\Decision\DecisionException;
use Netresearch\NrLlm\Service\Decision\DecisionRequest;
use Netresearch\NrLlm\Service\Decision\DecisionResult;
use Netresearch\NrLlm\Service\Decision\DecisionService;
use Netresearch\NrLlm\Service\Decision\Profile\DecisionProfile;
use Netresearch\NrLlm\Service\Decision\Profile\DecisionProfileProviderInterface;
use Netresearch\NrLlm\Service\Decision\Profile\DecisionProfileRegistry;
use Netresearch\NrLlm\Service\Decision\StructuredDecisionAsker;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\ModelSelectionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Option\DecisionOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use TypeError;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(DecisionService::class)]
#[CoversClass(DecisionProfileRegistry::class)]
#[CoversClass(DecisionException::class)]
#[CoversClass(DecisionResult::class)]
final class DecisionServiceTest extends TestCase
{
    private const PROFILE = 'test.rag_answer';

    private const CONFIGURATION = 'judge';

    /** What the manager was asked, per call. */
    private ?DecisionOptions $nativeOptions = null;

    /** The routing decision model selection took last. */
    private ?ModelResolution $resolution = null;

    /** What reading the extension setting throws, if anything. */
    private ?Throwable $settingFailure = null;

    /** The backend user the context resolver reports. */
    private ?int $resolvedBeUser = null;

    /** What the completion service was asked, per call. */
    private ?ChatOptions $chatOptions = null;

    private function profile(ToolDataClass $dataClass = ToolDataClass::EDITOR_CONTENT): DecisionProfile
    {
        return new DecisionProfile(
            identifier: self::PROFILE,
            version: 3,
            questions: [
                new YesNoQuestion('supported', 'Is every claim in `candidate` supported by `evidence`?'),
                new ChoiceQuestion('verdict', 'Overall?', ['publish', 'revise']),
                new ScoreQuestion('relevance', 'How relevant?', ['none', 'some', 'full']),
            ],
            requires: [SubjectField::Candidate, SubjectField::Evidence],
            dataClass: $dataClass,
        );
    }

    private function model(string $capabilities, TrustZone $zone = TrustZone::EXTERNAL_GLOBAL): Model
    {
        $provider = new Provider();
        $provider->setIdentifier('prov');
        $provider->setTrustZoneEnum($zone);

        $model = new Model();
        $model->setModelId('configured-model');
        $model->setCapabilities($capabilities);
        $model->setProvider($provider);

        return $model;
    }

    private function configuration(bool $active = true): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier(self::CONFIGURATION);
        $configuration->setIsActive($active);

        return $configuration;
    }

    /**
     * @return array<string, DecisionAnswer>
     */
    private static function validAnswers(): array
    {
        return [
            'supported' => DecisionAnswer::yesNo('supported', 0.93),
            'verdict'   => DecisionAnswer::choice('verdict', 'publish', ['publish' => 0.8, 'revise' => 0.2], 0.6),
            'relevance' => DecisionAnswer::score('relevance', 1.7, ['0' => 0.0, '1' => 0.3, '2' => 0.7], 0.5),
        ];
    }

    /**
     * @param array<string, DecisionAnswer>|null $answers
     */
    private function nativeResponse(?array $answers = null, ?UsageStatistics $usage = null): DecisionResponse
    {
        return new DecisionResponse(
            answers: $answers ?? self::validAnswers(),
            model: 'jev-1.13.0-reported',
            usage: $usage ?? new UsageStatistics(41, 7, 48, 0.0172),
            probabilityKind: ProbabilityKind::Distribution,
            provider: 'typesafe',
        );
    }

    /**
     * @param Model|Throwable|null           $decisionModel what model selection resolves for the Decision operation
     * @param Model|Throwable|null           $chatModel     what it resolves for the Chat operation
     * @param DecisionResponse|Throwable     $native        what the manager answers or throws
     * @param array<string, mixed>|Throwable $structured    the structured payload the completion service returns, or what it throws
     * @param LlmConfiguration|false|null    $stored        the configuration the repository finds; false = the default active one
     */
    private function service(
        Model|Throwable|null $decisionModel = null,
        Model|Throwable|null $chatModel = null,
        DecisionResponse|Throwable|null $native = null,
        array|Throwable $structured = [],
        string $defaultConfiguration = self::CONFIGURATION,
        LlmConfiguration|false|null $stored = false,
        ?DecisionProfile $profile = null,
    ): DecisionService {
        $profile ??= $this->profile();
        $provider = new class ($profile) implements DecisionProfileProviderInterface {
            public function __construct(private readonly DecisionProfile $profile) {}

            public function getDecisionProfiles(): array
            {
                return [$this->profile];
            }
        };

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($stored === false ? $this->configuration() : $stored);

        $selection = self::createStub(ModelSelectionServiceInterface::class);
        $selection->method('resolveModelForCall')->willReturnCallback(
            function (LlmConfiguration $configuration, ?ProviderOperation $operation) use ($decisionModel, $chatModel): ModelResolution {
                self::assertSame(self::CONFIGURATION, $configuration->getIdentifier());
                // A fixed model is what either operation resolves, unless a
                // test gives the chat resolution its own answer.
                $resolved = $operation === ProviderOperation::Decision ? $decisionModel : ($chatModel ?? $decisionModel);
                if ($resolved instanceof Throwable) {
                    throw $resolved;
                }

                return $this->resolution = ModelResolution::withoutDecision($resolved);
            },
        );

        $manager = self::createStub(LlmServiceManagerInterface::class);
        $manager->method('decideForConfiguration')->willReturnCallback(
            function (DecisionSubject $subject, array $questions, LlmConfiguration $configuration, ?DecisionOptions $options, ?ModelResolution $resolution) use ($native): DecisionResponse {
                // The routing decision the zone was checked against, not a second one.
                self::assertSame($this->resolution, $resolution);
                // The profile's questions, about the request's subject, on the resolved configuration.
                self::assertTrue($subject->has(SubjectField::Candidate));
                self::assertCount(3, $questions);
                self::assertSame(self::CONFIGURATION, $configuration->getIdentifier());
                $this->nativeOptions = $options;
                if ($native instanceof Throwable) {
                    throw $native;
                }

                return $native ?? throw new LogicException('the native path must not be taken', 4672456950);
            },
        );

        $completion = self::createStub(CompletionServiceInterface::class);
        $completion->method('completeStructuredForConfiguration')->willReturnCallback(
            function (string $prompt, LlmConfiguration $configuration, array $schema, ?ChatOptions $options, ?ModelResolution $resolution) use ($structured): StructuredCompletionResponse {
                self::assertSame($this->resolution, $resolution);
                self::assertStringStartsWith('STATE (JSON):', $prompt);
                self::assertSame(self::CONFIGURATION, $configuration->getIdentifier());
                self::assertSame('object', $schema['type'] ?? null);
                $this->chatOptions = $options;
                if ($structured instanceof Throwable) {
                    throw $structured;
                }

                return new StructuredCompletionResponse(
                    data: $structured,
                    response: new CompletionResponse('{}', 'gpt-reported', new UsageStatistics(300, 20, 320), provider: 'openai'),
                    usage: new UsageStatistics(610, 44, 654, 0.31),
                    attempts: 2,
                );
            },
        );

        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        if ($this->settingFailure instanceof Throwable) {
            $extensionConfiguration->method('get')->willThrowException($this->settingFailure);
        } else {
            $extensionConfiguration->method('get')->willReturn($defaultConfiguration);
        }

        $beUsers = self::createStub(BackendUserContextResolverInterface::class);
        $beUsers->method('resolveBeUserUid')->willReturn($this->resolvedBeUser);

        return new DecisionService(
            new DecisionProfileRegistry([$provider]),
            $repository,
            $selection,
            new TrustZoneResolver(),
            $manager,
            new StructuredDecisionAsker($completion),
            $extensionConfiguration,
            $beUsers,
        );
    }

    private function request(
        ?DecisionSubject $subject = null,
        string $profile = self::PROFILE,
        ?string $configuration = null,
        ?int $beUserUid = null,
    ): DecisionRequest {
        return new DecisionRequest(
            $profile,
            $subject ?? new DecisionSubject(task: 'Q', candidate: 'A', evidence: ['S']),
            $configuration,
            $beUserUid,
            'my_ext',
            'judge_answer',
        );
    }

    /**
     * @param callable(): mixed $call
     */
    private function assertDecisionCode(int $code, callable $call): DecisionException
    {
        try {
            $call();
        } catch (DecisionException $e) {
            self::assertSame($code, $e->getCode(), $e->getMessage());

            return $e;
        }

        self::fail(sprintf('expected a DecisionException with code %d', $code));
    }

    #[Test]
    public function aDecisionModelAnswersNativelyAndItsReportIsHandedOut(): void
    {
        $result = $this->service(decisionModel: $this->model('decision'), native: $this->nativeResponse())
            ->evaluate($this->request());

        self::assertSame(self::PROFILE, $result->profile);
        self::assertSame(3, $result->profileVersion);
        self::assertSame(self::CONFIGURATION, $result->configuration);
        self::assertSame('typesafe', $result->provider);
        // The model the provider reported, not the one the record configures.
        self::assertSame('jev-1.13.0-reported', $result->model);
        self::assertSame(ProbabilityKind::Distribution, $result->probabilityKind);
        self::assertSame(0.93, $result->answer('supported')->value);
        self::assertSame('publish', $result->answer('verdict')->choice);
        self::assertSame(1.7, $result->answer('relevance')->value);
        self::assertSame(41, $result->inputTokens);
        self::assertSame(7, $result->outputTokens);
        self::assertSame(0.0172, $result->cost);
    }

    #[Test]
    public function theNativeCallCarriesTheCallerSourceAndTheBudgetSubject(): void
    {
        $this->service(decisionModel: $this->model('decision'), native: $this->nativeResponse())
            ->evaluate($this->request(beUserUid: 17));

        self::assertInstanceOf(DecisionOptions::class, $this->nativeOptions);
        self::assertSame(17, $this->nativeOptions->getBeUserUid());
        self::assertSame('my_ext', $this->nativeOptions->getCallerSourceExtension());
        self::assertSame('judge_answer', $this->nativeOptions->getCallerSourceOperation());
    }

    #[Test]
    public function theBudgetSubjectIsResolvedFromTheBackendUserWhenTheRequestNamesNone(): void
    {
        $this->resolvedBeUser = 23;
        $this->service(decisionModel: $this->model('decision'), native: $this->nativeResponse())
            ->evaluate($this->request());

        self::assertSame(23, $this->nativeOptions?->getBeUserUid());
    }

    #[Test]
    public function aBudgetSubjectTheRequestNamesIsNotReplacedByTheBackendUser(): void
    {
        $this->resolvedBeUser = 23;
        $this->service(decisionModel: $this->model('decision'), native: $this->nativeResponse())
            ->evaluate($this->request(beUserUid: 17));

        self::assertSame(17, $this->nativeOptions?->getBeUserUid());
    }

    #[Test]
    public function usageAllZeroIsReportedAsUnknownNotAsZero(): void
    {
        $result = $this->service(decisionModel: $this->model('decision'), native: $this->nativeResponse(usage: new UsageStatistics(0, 0, 0)))
            ->evaluate($this->request());

        self::assertNull($result->inputTokens);
        self::assertNull($result->outputTokens);
    }

    #[Test]
    public function aChatModelIsResolvedAgainForTheChatCallItRunsAs(): void
    {
        // Fixed on a chat model, the Decision resolution returns that model;
        // the structured call is routed as Chat and must carry that decision.
        $chat = $this->model('chat');
        $chat->setModelId('the-chat-routing');

        $this->service(
            decisionModel: $this->model('chat'),
            chatModel: $chat,
            structured: ['supported' => 'yes', 'verdict' => 'publish', 'relevance' => 1],
        )->evaluate($this->request());

        self::assertSame($chat, $this->resolution?->model);
    }

    #[Test]
    public function theBackendUserBecomesTheBudgetSubjectOnTheStructuredPathToo(): void
    {
        $this->resolvedBeUser = 23;

        $this->service(decisionModel: $this->model('chat'), structured: ['supported' => 'yes', 'verdict' => 'publish', 'relevance' => 1])
            ->evaluate($this->request());

        self::assertSame(23, $this->chatOptions?->getBeUserUid());
    }

    #[Test]
    public function aChatModelAnswersThroughStructuredOutputWithHardLabels(): void
    {
        $result = $this->service(
            decisionModel: $this->model('chat'),
            structured: ['supported' => 'no', 'verdict' => 'revise', 'relevance' => 2],
        )->evaluate($this->request(beUserUid: 17));

        self::assertSame('openai', $result->provider);
        self::assertSame('gpt-reported', $result->model);
        self::assertSame(ProbabilityKind::None, $result->probabilityKind);
        self::assertSame(0.0, $result->answer('supported')->value);
        self::assertSame('revise', $result->answer('verdict')->choice);
        // The highest level is a valid answer, not an out-of-range one.
        self::assertSame(2.0, $result->answer('relevance')->value);
        self::assertSame([], $result->answer('verdict')->probabilities);
        // Every attempt's tokens, the repair round-trip included.
        self::assertSame(610, $result->inputTokens);
        self::assertSame(44, $result->outputTokens);
        self::assertSame(0.31, $result->cost);

        self::assertInstanceOf(ChatOptions::class, $this->chatOptions);
        self::assertSame(17, $this->chatOptions->getBeUserUid());
        self::assertSame('my_ext', $this->chatOptions->getCallerSourceExtension());
    }

    #[Test]
    public function aModelThatDeclaresNoCapabilitiesIsAskedAsAChatModel(): void
    {
        $result = $this->service(
            decisionModel: $this->model(''),
            structured: ['supported' => 'yes', 'verdict' => 'publish', 'relevance' => 0],
        )->evaluate($this->request());

        self::assertSame(1.0, $result->answer('supported')->value);
    }

    #[Test]
    public function criteriaThatMatchNoDecisionModelFallBackToTheChatResolution(): void
    {
        $result = $this->service(
            decisionModel: new UnsupportedFeatureException('no decision model matches'),
            chatModel: $this->model('chat'),
            structured: ['supported' => 'yes', 'verdict' => 'publish', 'relevance' => 1],
        )->evaluate($this->request());

        self::assertSame('gpt-reported', $result->model);
    }

    #[Test]
    public function anEmbeddingModelCannotDecide(): void
    {
        $this->assertDecisionCode(DecisionException::MODEL_CANNOT_DECIDE, fn(): DecisionResult => $this->service(decisionModel: $this->model('embeddings'))->evaluate($this->request()));
    }

    #[Test]
    public function aConfigurationWithNoModelForEitherOperationCannotDecide(): void
    {
        $cause = new UnsupportedFeatureException('nothing matches');

        $e = $this->assertDecisionCode(DecisionException::MODEL_CANNOT_DECIDE, fn(): DecisionResult => $this->service(decisionModel: new UnsupportedFeatureException('no decision model'), chatModel: $cause)->evaluate($this->request()));
        self::assertSame($cause, $e->getPrevious());
    }

    #[Test]
    public function aConfigurationThatResolvesNoModelCannotDecide(): void
    {
        $this->assertDecisionCode(DecisionException::MODEL_CANNOT_DECIDE, fn(): DecisionResult => $this->service()->evaluate($this->request()));
    }

    #[Test]
    public function anUnknownProfileIsRefused(): void
    {
        $this->assertDecisionCode(DecisionException::UNKNOWN_PROFILE, fn(): DecisionResult => $this->service()->evaluate($this->request(profile: 'test.other')));
    }

    #[Test]
    public function aMissingRequiredSubjectFieldIsRefusedBeforeAnythingIsAsked(): void
    {
        $this->assertDecisionCode(DecisionException::MISSING_SUBJECT_FIELD, fn(): DecisionResult => $this->service(decisionModel: $this->model('decision'), native: new LogicException('must not be asked'))
            ->evaluate($this->request(new DecisionSubject(candidate: 'A'))));
    }

    #[Test]
    public function withoutAConfigurationAnywhereTheServiceRefuses(): void
    {
        $this->assertDecisionCode(DecisionException::NO_CONFIGURATION, fn(): DecisionResult => $this->service(defaultConfiguration: '  ')->evaluate($this->request(configuration: ' ')));
    }

    #[Test]
    public function theConfigurationTheRequestNamesWinsOverTheExtensionSetting(): void
    {
        // The extension setting names nothing: only the request can supply it.
        $result = $this->service(decisionModel: $this->model('decision'), native: $this->nativeResponse(), defaultConfiguration: '')
            ->evaluate($this->request(configuration: ' judge '));

        self::assertSame(self::CONFIGURATION, $result->configuration);
    }

    #[Test]
    public function anUnknownConfigurationIsRefused(): void
    {
        $this->assertDecisionCode(DecisionException::UNKNOWN_CONFIGURATION, fn(): DecisionResult => $this->service(stored: null)->evaluate($this->request()));
    }

    #[Test]
    public function anInactiveConfigurationIsRefused(): void
    {
        $this->assertDecisionCode(DecisionException::UNKNOWN_CONFIGURATION, fn(): DecisionResult => $this->service(stored: $this->configuration(active: false))->evaluate($this->request()));
    }

    #[Test]
    public function aProfileWhoseDataTheZoneMayNotReceiveIsRefusedBeforeAnythingIsAsked(): void
    {
        $this->assertDecisionCode(DecisionException::DATA_CLASS_NOT_PERMITTED, fn(): DecisionResult => $this->service(
            decisionModel: $this->model('decision', TrustZone::EXTERNAL_GLOBAL),
            native: new LogicException('must not be asked'),
            profile: $this->profile(ToolDataClass::SOURCE_CODE),
        )->evaluate($this->request()));
    }

    #[Test]
    public function aLocalModelMayReceiveWhatAnExternalOneMayNot(): void
    {
        $result = $this->service(
            decisionModel: $this->model('decision', TrustZone::LOCAL),
            native: $this->nativeResponse(),
            profile: $this->profile(ToolDataClass::SOURCE_CODE),
        )->evaluate($this->request());

        self::assertSame(0.93, $result->answer('supported')->value);
    }

    /**
     * @return iterable<string, array{Throwable, int}>
     */
    public static function failures(): iterable
    {
        yield 'a malformed answer' => [InvalidDecisionResponseException::forQuestion('TypeSafe', 'verdict', 'no chosen option'), DecisionException::INVALID_ANSWER];
        yield 'a rejected request (422)' => [new ProviderResponseException('subject too long', 422), DecisionException::REJECTED];
        yield 'an overloaded provider (529)' => [new ProviderResponseException('overloaded', 529), DecisionException::FAILED];
        yield 'a rate limit (429)' => [new ProviderResponseException('slow down', 429), DecisionException::FAILED];
        yield 'a wrong key (401)' => [new ProviderResponseException('unauthorized', 401), DecisionException::REJECTED];
        yield 'a body over the limit (413)' => [new ProviderResponseException('too large', 413), DecisionException::REJECTED];
        yield 'a context over the limit (400)' => [new ProviderResponseException('context length exceeded', 400), DecisionException::REJECTED];
        yield 'any other failure' => [new RuntimeException('connection refused'), DecisionException::FAILED];
        yield 'a provider that cannot decide' => [new UnsupportedFeatureException('cannot make decisions', 1795211061), DecisionException::MODEL_CANNOT_DECIDE];
    }

    #[Test]
    public function anUnsetExtensionSettingMeansNoConfiguration(): void
    {
        foreach ([
            new ExtensionConfigurationPathDoesNotExistException('no such path', 1795211098),
            new ExtensionConfigurationExtensionNotConfiguredException('not configured', 1795211096),
        ] as $unset) {
            $this->settingFailure = $unset;

            $this->assertDecisionCode(DecisionException::NO_CONFIGURATION, fn(): DecisionResult => $this->service()->evaluate($this->request()));
        }
    }

    #[Test]
    public function anUnreadableExtensionSettingIsNotMistakenForAnUnsetOne(): void
    {
        $this->settingFailure = new RuntimeException('cache backend unavailable', 1795211097);

        $this->expectExceptionMessage('cache backend unavailable');
        $this->service()->evaluate($this->request());
    }

    #[Test]
    public function aDefectInTheCodeIsNotDressedUpAsAFailedDecision(): void
    {
        $this->expectException(TypeError::class);

        $this->service(decisionModel: $this->model('decision'), native: new TypeError('a bug'))->evaluate($this->request());
    }

    #[Test]
    public function availabilityIsCheckedWithoutAskingAnything(): void
    {
        $service = $this->service(decisionModel: $this->model('decision'), native: new LogicException('must not be asked', 1795211099));

        $service->assertAvailable(self::PROFILE);

        $this->assertDecisionCode(DecisionException::UNKNOWN_PROFILE, static function () use ($service): void {
            $service->assertAvailable('test.other');
        });
        $this->assertDecisionCode(DecisionException::DATA_CLASS_NOT_PERMITTED, function (): void {
            $this->service(decisionModel: $this->model('decision'), profile: $this->profile(ToolDataClass::SOURCE_CODE))->assertAvailable(self::PROFILE);
        });
        $this->assertDecisionCode(DecisionException::NO_CONFIGURATION, function (): void {
            $this->service(defaultConfiguration: '')->assertAvailable(self::PROFILE);
        });
    }

    #[Test]
    #[DataProvider('failures')]
    public function aFailureOfTheNativeCallBecomesADecisionExceptionWithItsCause(Throwable $cause, int $code): void
    {
        $e = $this->assertDecisionCode($code, fn(): DecisionResult => $this->service(decisionModel: $this->model('decision'), native: $cause)->evaluate($this->request()));
        self::assertSame($cause, $e->getPrevious());
    }

    #[Test]
    public function aFailureOfTheStructuredCallBecomesADecisionExceptionWithItsCause(): void
    {
        $cause = new ProviderResponseException('bad request', 422);

        $e = $this->assertDecisionCode(DecisionException::REJECTED, fn(): DecisionResult => $this->service(decisionModel: $this->model('chat'), structured: $cause)->evaluate($this->request()));
        self::assertSame($cause, $e->getPrevious());
    }

    #[Test]
    public function aChatModelThatMissesTheSchemaTwiceGaveAnInvalidAnswer(): void
    {
        // What the real completion service throws after the repair round-trip.
        $cause = new StructuredResponseMismatchException('did not match the schema', 1784500002);

        $e = $this->assertDecisionCode(
            DecisionException::INVALID_ANSWER,
            fn(): DecisionResult => $this->service(decisionModel: $this->model('chat'), structured: $cause)->evaluate($this->request()),
        );
        self::assertSame($cause, $e->getPrevious()?->getPrevious());
    }

    #[Test]
    public function aStructuredAnswerOutsideTheAllowedValuesIsAnInvalidAnswer(): void
    {
        $this->assertDecisionCode(DecisionException::INVALID_ANSWER, fn(): DecisionResult => $this->service(decisionModel: $this->model('chat'), structured: ['supported' => 'maybe', 'verdict' => 'publish', 'relevance' => 1])
            ->evaluate($this->request()));
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function passedThrough(): iterable
    {
        yield 'budget' => [new BudgetExceededException(BudgetCheckResult::denied(BudgetCheckResult::LIMIT_DAILY_COST, 5.0, 1.0))];
        yield 'guardrail' => [new GuardrailViolationException('SecretGuardrail', 'secret found')];
        yield 'decision' => [DecisionException::noSuchAnswer('x.y', 'z')];
        yield 'input-context trust zone' => [InputContextTrustZoneException::forConfiguration('judge', TrustZone::EXTERNAL_GLOBAL, ToolDataClass::SOURCE_CODE, 'snippet policy')];
    }

    #[Test]
    #[DataProvider('passedThrough')]
    public function aPolicyDenialOrADecisionExceptionKeepsItsOwnIdentity(Throwable $denial): void
    {
        try {
            $this->service(decisionModel: $this->model('decision'), native: $denial)->evaluate($this->request());
            self::fail('expected the denial');
        } catch (Throwable $e) {
            self::assertSame($denial, $e);
        }
    }

    /**
     * @return iterable<string, array{array<string, DecisionAnswer>}>
     */
    public static function answersThatDoNotMatchTheQuestions(): iterable
    {
        $valid = self::validAnswers();

        yield 'a question left unanswered' => [array_diff_key($valid, ['verdict' => true])];
        yield 'an answer to a question never asked' => [[...$valid, 'extra' => DecisionAnswer::yesNo('extra', 1.0)]];
        yield 'an answer of the wrong type' => [[...$valid, 'supported' => DecisionAnswer::score('supported', 1.0)]];
        yield 'an answer filed under the wrong key' => [[...$valid, 'supported' => DecisionAnswer::yesNo('other', 1.0)]];
        yield 'an option the question does not offer' => [[...$valid, 'verdict' => DecisionAnswer::choice('verdict', 'delete')]];
        yield 'a probability for an option not offered' => [[...$valid, 'verdict' => DecisionAnswer::choice('verdict', 'publish', ['delete' => 1.0])]];
        yield 'a score above the highest level' => [[...$valid, 'relevance' => DecisionAnswer::score('relevance', 2.01)]];
        yield 'a probability for a level not offered' => [[...$valid, 'relevance' => DecisionAnswer::score('relevance', 1.0, ['3' => 1.0])]];
    }

    /**
     * @param array<string, DecisionAnswer> $answers
     */
    #[Test]
    #[DataProvider('answersThatDoNotMatchTheQuestions')]
    public function aResponseThatDoesNotMatchTheQuestionsIsNeverHandedOut(array $answers): void
    {
        $this->assertDecisionCode(DecisionException::INVALID_ANSWER, fn(): DecisionResult => $this->service(decisionModel: $this->model('decision'), native: $this->nativeResponse($answers))->evaluate($this->request()));
    }

    #[Test]
    public function aScoreAtTheHighestLevelIsValid(): void
    {
        $result = $this->service(
            decisionModel: $this->model('decision'),
            native: $this->nativeResponse([...self::validAnswers(), 'relevance' => DecisionAnswer::score('relevance', 2.0, ['2' => 1.0])]),
        )->evaluate($this->request());

        self::assertSame(2.0, $result->answer('relevance')->value);
    }

    #[Test]
    public function aResultRefusesAQuestionTheProfileDoesNotAsk(): void
    {
        $result = $this->service(decisionModel: $this->model('decision'), native: $this->nativeResponse())->evaluate($this->request());

        $this->assertDecisionCode(DecisionException::NO_SUCH_ANSWER, static fn(): DecisionAnswer => $result->answer('extra'));
    }

    #[Test]
    public function aDuplicateProfileIdentifierIsAnInvalidProfile(): void
    {
        $profile = new DecisionProfile('x.y', 1, [new YesNoQuestion('ok', 'Ok?')]);
        $provider = new class ($profile) implements DecisionProfileProviderInterface {
            public function __construct(private readonly DecisionProfile $profile) {}

            public function getDecisionProfiles(): array
            {
                return [$this->profile, $this->profile];
            }
        };

        $this->assertDecisionCode(DecisionException::INVALID_PROFILE, static fn(): ?DecisionProfile => (new DecisionProfileRegistry([$provider]))->find('x.y'));
    }

    #[Test]
    public function aProviderThatCannotBuildItsProfilesIsAnInvalidProfileWithItsCause(): void
    {
        $provider = new class implements DecisionProfileProviderInterface {
            public function getDecisionProfiles(): array
            {
                return [new DecisionProfile('x.y', 0, [new YesNoQuestion('ok', 'Ok?')])];
            }
        };

        $registry = new DecisionProfileRegistry([$provider]);

        // Asked for, the profile the broken provider may have declared names the failure.
        $e = $this->assertDecisionCode(DecisionException::INVALID_PROFILE, static fn(): ?DecisionProfile => $registry->find('x.y'));
        self::assertSame(1795211011, $e->getPrevious()?->getCode());
        self::assertSame([], $registry->all());
    }

    #[Test]
    public function oneBrokenProviderLeavesTheOthersProfilesUsable(): void
    {
        $broken = new class implements DecisionProfileProviderInterface {
            public function getDecisionProfiles(): array
            {
                return [new DecisionProfile('b.x', 1, [new ChoiceQuestion('pick', 'Pick', ['only'])])];
            }
        };
        $sound = new class implements DecisionProfileProviderInterface {
            public function getDecisionProfiles(): array
            {
                return [new DecisionProfile('a.ok', 1, [new YesNoQuestion('ok', 'Ok?')])];
            }
        };

        $registry = new DecisionProfileRegistry([$broken, $sound]);

        self::assertSame('a.ok', $registry->find('a.ok')?->identifier);
        self::assertSame(['a.ok'], array_map(static fn(DecisionProfile $p): string => $p->identifier, $registry->all()));
        $this->assertDecisionCode(DecisionException::INVALID_PROFILE, static fn(): ?DecisionProfile => $registry->find('b.x'));
    }

    #[Test]
    public function aProviderFailingOnceIsAskedAgainOnTheNextLookup(): void
    {
        $flaky = new class implements DecisionProfileProviderInterface {
            public int $calls = 0;

            public function getDecisionProfiles(): array
            {
                if (++$this->calls === 1) {
                    throw new RuntimeException('database failover', 1795211093);
                }

                return [new DecisionProfile('f.ok', 1, [new YesNoQuestion('ok', 'Ok?')])];
            }
        };
        $sound = new class implements DecisionProfileProviderInterface {
            public function getDecisionProfiles(): array
            {
                return [new DecisionProfile('a.ok', 1, [new YesNoQuestion('ok', 'Ok?')])];
            }
        };

        $registry = new DecisionProfileRegistry([$flaky, $sound]);

        // During the failure the flaky provider's profile names it.
        $this->assertDecisionCode(DecisionException::INVALID_PROFILE, static fn(): ?DecisionProfile => $registry->find('f.ok'));

        // A passing failure is not kept: the next lookup asks again, and a
        // build without a passing failure is kept from then on.
        self::assertSame('f.ok', $registry->find('f.ok')?->identifier);
        self::assertSame('a.ok', $registry->find('a.ok')?->identifier);
        self::assertSame(2, $flaky->calls);
    }

    #[Test]
    public function anIdentifierDeclaredTwiceIsWithheldWhileTheRestStays(): void
    {
        $first = new class implements DecisionProfileProviderInterface {
            public function getDecisionProfiles(): array
            {
                return [new DecisionProfile('x.dup', 1, [new YesNoQuestion('ok', 'Ok?')]), new DecisionProfile('x.one', 1, [new YesNoQuestion('ok', 'Ok?')])];
            }
        };
        $second = new class implements DecisionProfileProviderInterface {
            public function getDecisionProfiles(): array
            {
                return [new DecisionProfile('x.dup', 2, [new YesNoQuestion('ok', 'Ok?')])];
            }
        };

        $registry = new DecisionProfileRegistry([$first, $second]);

        self::assertSame(1, $registry->find('x.one')?->version);
        $e = $this->assertDecisionCode(DecisionException::INVALID_PROFILE, static fn(): ?DecisionProfile => $registry->find('x.dup'));
        self::assertStringContainsString('declared twice', $e->getMessage());
        self::assertNull($registry->find('x.none'), 'no failure recorded that could be this profile');
    }

    #[Test]
    public function theRegistryBuildsItsProfilesOnceAndListsThem(): void
    {
        $provider = new class implements DecisionProfileProviderInterface {
            public int $calls = 0;

            public function getDecisionProfiles(): array
            {
                ++$this->calls;

                return [new DecisionProfile('x.a', 1, [new YesNoQuestion('ok', 'Ok?')]), new DecisionProfile('x.b', 2, [new YesNoQuestion('ok', 'Ok?')])];
            }
        };

        $registry = new DecisionProfileRegistry([$provider]);

        self::assertSame(['x.a', 'x.b'], array_map(static fn(DecisionProfile $p): string => $p->identifier, $registry->all()));
        self::assertSame(2, $registry->find('x.b')?->version);
        self::assertNull($registry->find('x.c'));
        self::assertSame(1, $provider->calls);
    }
}
