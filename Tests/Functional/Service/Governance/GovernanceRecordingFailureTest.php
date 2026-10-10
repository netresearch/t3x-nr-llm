<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Governance;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\GovernanceEvent;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Exception\GuardrailApprovalRequiredException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Exception\InputContextTrustZoneException;
use Netresearch\NrLlm\Provider\Middleware\GuardrailMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\Context\InputContextClassifier;
use Netresearch\NrLlm\Service\Context\InputContextTrustGate;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\GovernanceEventRepository;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\Guardrail\GuardrailInterface;
use Netresearch\NrLlm\Tests\Fixture\GuardrailIdentityDoubleTrait;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;

#[CoversClass(GovernanceEventRepository::class)]
#[CoversClass(GuardrailMiddleware::class)]
#[CoversClass(InputContextTrustGate::class)]
final class GovernanceRecordingFailureTest extends AbstractFunctionalTestCase
{
    private Connection $connection;

    /** @var list<string> */
    private array $restoreSchema = [];

    private bool $tableDropped = false;

    private GovernanceEventRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->getConnectionPool();
        $this->connection = $pool->getConnectionForTable('tx_nrllm_governance_event');
        $table = $this->connection
            ->createSchemaManager()
            ->introspectTable('tx_nrllm_governance_event');
        $this->restoreSchema = $this->connection->getDatabasePlatform()->getCreateTableSQL($table);
        $this->connection->executeStatement(
            'DROP TABLE tx_nrllm_governance_event',
        );
        $this->tableDropped = true;
        $this->repository = new GovernanceEventRepository($pool);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->tableDropped) {
                foreach ($this->restoreSchema as $statement) {
                    $this->connection->executeStatement($statement);
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    #[Test]
    #[DataProvider('loggerModes')]
    public function actualAuditDatabaseFailureDoesNotEscapeTheRecorder(bool $loggerThrows): void
    {
        $caught = null;
        try {
            $this->repositoryForDiagnostics($loggerThrows)->record(
                new GovernanceEvent(
                    correlationId: 'fixture',
                    decision: 'tool_denied',
                    reason: 'trustZone',
                    provider: '',
                    model: '',
                    configurationIdentifier: '',
                    beUser: 0,
                    toolName: 'restricted',
                    agentrunUid: 0,
                    guardrail: '',
                    detail: '',
                ),
            );
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        self::assertNull($caught, $caught?->getMessage() ?? '');
    }

    /**
     * @return array<string, array{bool, class-string<Throwable>, bool}>
     */
    public static function guardrailVerdicts(): array
    {
        return [
            'deny without logger' => [false, GuardrailViolationException::class, false],
            'deny with throwing logger' => [false, GuardrailViolationException::class, true],
            'approval without logger' => [true, GuardrailApprovalRequiredException::class, false],
            'approval with throwing logger' => [true, GuardrailApprovalRequiredException::class, true],
        ];
    }

    /**
     * @param class-string<Throwable> $expected
     */
    #[Test]
    #[DataProvider('guardrailVerdicts')]
    public function auditFailurePreservesTheTypedGuardrailDecision(
        bool $approval,
        string $expected,
        bool $loggerThrows,
    ): void {
        $result = $approval ? GuardrailResult::requireApproval('policy') : GuardrailResult::deny('policy');
        $guardrail = new class ($result) implements GuardrailInterface {
            use GuardrailIdentityDoubleTrait;

            public function __construct(
                private readonly GuardrailResult $result,
            ) {}

            public function checkOutput(
                CompletionResponse $response,
            ): GuardrailResult {
                return $this->result;
            }
        };
        $middleware = new GuardrailMiddleware(
            [$guardrail],
            governanceEvents: $this->repositoryForDiagnostics($loggerThrows),
        );
        $caught = null;
        try {
            $middleware->handle(
                ProviderCallContext::forConfiguration(
                    ProviderOperation::Chat,
                    new LlmConfiguration(),
                ),
                static fn(): CompletionResponse => new CompletionResponse(
                    'unsafe',
                    'fixture',
                    UsageStatistics::fromTokens(1, 1),
                ),
            );
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        self::assertInstanceOf($expected, $caught);
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function contextModes(): array
    {
        return [
            'enforce without logger' => [true, false],
            'enforce with throwing logger' => [true, true],
            'observe without logger' => [false, false],
            'observe with throwing logger' => [false, true],
        ];
    }

    #[Test]
    #[DataProvider('contextModes')]
    public function auditFailurePreservesTheContextEnforcementMode(
        bool $enforcing,
        bool $loggerThrows,
    ): void {
        $settings = self::createStub(ExtensionConfiguration::class);
        $settings
            ->method('get')
            ->willReturn(
                [
                    'tools' => [
                        'dataClassEnforcement' => $enforcing ? 'enforce' : 'observe',
                    ],
                ],
            );
        $gate = new InputContextTrustGate(
            new InputContextClassifier(),
            new TrustZoneResolver(),
            new DataClassEnforcementResolver($settings),
            $this->repositoryForDiagnostics($loggerThrows),
        );
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('classified');
        $configuration->setSystemPrompt('Classified context.');
        $configuration->setSystemPromptDataClass(
            ToolDataClass::SECRET_ADJACENT->value,
        );
        $caught = null;
        try {
            $gate->assertPermitted($configuration);
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        if ($enforcing) {
            self::assertInstanceOf(
                InputContextTrustZoneException::class,
                $caught,
            );
        } else {
            self::assertNull($caught, $caught?->getMessage() ?? '');
        }
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function loggerModes(): array
    {
        return ['no logger' => [false], 'throwing logger' => [true]];
    }

    private function repositoryForDiagnostics(
        bool $loggerThrows,
    ): GovernanceEventRepository {
        if (!$loggerThrows) {
            return $this->repository;
        }

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('warning')
            ->willThrowException(new RuntimeException('logger failure'));
        return new GovernanceEventRepository($this->getConnectionPool(), $logger);
    }
}
