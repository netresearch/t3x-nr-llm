<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Decision;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionSubject;
use Netresearch\NrLlm\Domain\ValueObject\Decision\YesNoQuestion;
use Netresearch\NrLlm\Domain\ValueObject\ModelResolution;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Exception\GuardrailApprovalRequiredException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Exception\InputContextTrustZoneException;
use Netresearch\NrLlm\Service\Budget\BackendUserContextResolverInterface;
use Netresearch\NrLlm\Service\Decision\DecisionException;
use Netresearch\NrLlm\Service\Decision\DecisionRequest;
use Netresearch\NrLlm\Service\Decision\DecisionService;
use Netresearch\NrLlm\Service\Decision\Profile\DecisionProfile;
use Netresearch\NrLlm\Service\Decision\Profile\DecisionProfileProviderInterface;
use Netresearch\NrLlm\Service\Decision\Profile\DecisionProfileRegistry;
use Netresearch\NrLlm\Service\Decision\StructuredDecisionAsker;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\ModelSelectionServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use TypeError;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(DecisionService::class)]
final class DecisionPreflightFailureTest extends TestCase
{
    #[Test]
    #[DataProvider('preflightFailures')]
    public function infrastructureFailuresHaveThePublicDecisionType(
        string $stage,
        bool $availability,
        string $context,
    ): void {
        $cause = new RuntimeException('dependency unavailable');
        $failure = $this->failure($this->service($stage, $cause), $availability, $stage);

        self::assertInstanceOf(DecisionException::class, $failure);
        self::assertSame(DecisionException::FAILED, $failure->getCode());
        self::assertSame($cause, $failure->getPrevious());
        self::assertStringContainsString($context, $failure->getMessage());
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function preflightFailures(): iterable
    {
        foreach ([
            'setting' => 'decision.configuration',
            'repository' => 'judge',
            'routing' => 'judge',
            'routing-default' => 'judge',
        ] as $stage => $context) {
            foreach ([false, true] as $availability) {
                yield $stage . ($availability ? '-availability' : '-evaluate') => [$stage, $availability, $context];
            }
        }

        yield 'budget-subject-evaluate' => ['budget', false, 'judge'];
    }

    #[Test]
    #[DataProvider('policyFailures')]
    public function preflightPolicyFailuresKeepTheirIdentity(
        Throwable $cause,
        bool $availability,
    ): void {
        self::assertSame(
            $cause,
            $this->failure(
                $this->service('routing', $cause),
                $availability,
                'routing',
            ),
        );
    }

    /**
     * @return iterable<string, array{Throwable, bool}>
     */
    public static function policyFailures(): iterable
    {
        $causes = [
            'budget' => new BudgetExceededException(
                BudgetCheckResult::denied(
                    BudgetCheckResult::LIMIT_DAILY_COST,
                    5.0,
                    1.0,
                ),
            ),
            'blocked' => new GuardrailViolationException('SecretGuardrail', 'secret found'),
            'approval' => new GuardrailApprovalRequiredException(
                'ReviewGuardrail',
                'review required',
            ),
            'input-context' => InputContextTrustZoneException::forConfiguration(
                'judge',
                TrustZone::EXTERNAL_GLOBAL,
                ToolDataClass::SOURCE_CODE,
                'snippet policy',
            ),
            'decision' => DecisionException::noSuchAnswer('test.preflight', 'absent'),
        ];
        foreach ($causes as $name => $cause) {
            foreach ([false, true] as $availability) {
                yield $name . ($availability ? '-availability' : '-evaluate') => [$cause, $availability];
            }
        }
    }

    #[Test]
    #[DataProvider('publicMethods')]
    public function engineErrorsRemainVisible(bool $availability): void
    {
        $cause = new TypeError('programming defect');

        self::assertSame(
            $cause,
            $this->failure(
                $this->service('routing', $cause),
                $availability,
                'routing',
            ),
        );
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function publicMethods(): iterable
    {
        yield 'evaluate' => [false];
        yield 'availability' => [true];
    }

    private function failure(
        DecisionService $service,
        bool $availability,
        string $stage,
    ): Throwable {
        $configuration = in_array($stage, ['setting', 'routing-default'], true) ? null : 'judge';
        try {
            if ($availability) {
                $service->assertAvailable('test.preflight', $configuration);
            } else {
                $service->evaluate(
                    new DecisionRequest(
                        'test.preflight',
                        new DecisionSubject(candidate: 'answer'),
                        $configuration,
                    ),
                );
            }
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail(
            'The dependency failure must prevent a decision or a successful availability check.',
        );
    }

    private function service(string $stage, Throwable $cause): DecisionService
    {
        $profile = new DecisionProfile(
            'test.preflight',
            1,
            [new YesNoQuestion('valid', 'Is the candidate valid?')],
        );
        $profiles = new class ($profile) implements DecisionProfileProviderInterface {
            public function __construct(
                private readonly DecisionProfile $profile,
            ) {}

            public function getDecisionProfiles(): array
            {
                return [$this->profile];
            }
        };
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('judge');
        $configuration->setIsActive(true);

        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::EXTERNAL_GLOBAL);

        $model = new Model();
        $model->setModelId('judge-model');
        $model->setCapabilities('decision');
        $model->setProvider($provider);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository
            ->method('findOneByIdentifier')
            ->willReturnCallback(
                static function () use ($stage, $cause, $configuration): LlmConfiguration {
                    if ($stage === 'repository') {
                        throw $cause;
                    }

                    return $configuration;
                },
            );
        $selection = self::createStub(ModelSelectionServiceInterface::class);
        $selection
            ->method('resolveModelForCall')
            ->willReturnCallback(
                static function () use ($stage, $cause, $model): ModelResolution {
                    if (in_array($stage, ['routing', 'routing-default'], true)) {
                        throw $cause;
                    }

                    return ModelResolution::withoutDecision($model);
                },
            );
        $settings = self::createStub(ExtensionConfiguration::class);
        $settings
            ->method('get')
            ->willReturnCallback(
                static function () use ($stage, $cause): string {
                    if ($stage === 'setting') {
                        throw $cause;
                    }

                    return 'judge';
                },
            );
        $budgetSubject = self::createStub(BackendUserContextResolverInterface::class);
        $budgetSubject
            ->method('resolveBeUserUid')
            ->willReturnCallback(
                static function () use ($stage, $cause): int {
                    if ($stage === 'budget') {
                        throw $cause;
                    }

                    return 1;
                },
            );
        $manager = self::createMock(LlmServiceManagerInterface::class);
        $manager->expects(self::never())->method('decideForConfiguration');
        $completion = self::createMock(CompletionServiceInterface::class);
        $completion
            ->expects(self::never())
            ->method('completeStructuredForConfiguration');

        return new DecisionService(
            new DecisionProfileRegistry([$profiles]),
            $repository,
            $selection,
            new TrustZoneResolver(),
            $manager,
            new StructuredDecisionAsker($completion),
            $settings,
            $budgetSubject,
        );
    }
}
