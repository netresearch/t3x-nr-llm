# Rate Limiting & Quota Management - Usage Guide

> Implementation Guide: How to integrate and use the rate limiting system
> Target Audience: Extension developers, system administrators

---

## Quick Start

### Basic Usage in AI Service Manager

```php
<?php
namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Service\RateLimiterService;
use Netresearch\NrLlm\Service\QuotaManager;
use Netresearch\NrLlm\Service\UsageTracker;
use Netresearch\NrLlm\Service\CostCalculator;

class AiServiceManager
{
    public function __construct(
        private readonly RateLimiterService $rateLimiter,
        private readonly QuotaManager $quotaManager,
        private readonly UsageTracker $usageTracker,
        private readonly CostCalculator $costCalculator,
        private readonly ProviderFactory $providerFactory
    ) {}

    public function complete(string $prompt, array $options = []): AiResponse
    {
        $startTime = microtime(true);
        $userId = $GLOBALS['BE_USER']->user['uid'];
        $provider = $options['provider'] ?? 'openai';
        $model = $options['model'] ?? 'gpt-4-turbo';

        try {
            // Step 1: Check rate limits
            $this->checkRateLimits($userId, $provider);

            // Step 2: Estimate cost and check quotas
            $estimatedCost = $this->estimateAndCheckQuotas($prompt, $provider, $model, $userId);

            // Step 3: Execute request
            $providerInstance = $this->providerFactory->create($provider);
            $response = $providerInstance->complete($prompt, $options);

            // Step 4: Track actual usage
            $this->trackSuccess($userId, $provider, $model, $response, $startTime);

            // Step 5: Consume quotas
            $this->consumeQuotas($userId, $response->getTokenUsage());

            return $response;

        } catch (RateLimitExceededException $e) {
            $this->trackError($userId, $provider, 'rate_limited', $e, $startTime);
            throw $e;
        } catch (QuotaExceededException $e) {
            $this->trackError($userId, $provider, 'quota_exceeded', $e, $startTime);
            throw $e;
        } catch (\Exception $e) {
            $this->trackError($userId, $provider, 'error', $e, $startTime);
            throw $e;
        }
    }

    private function checkRateLimits(int $userId, string $provider): void
    {
        // Check multiple rate limit levels
        $this->rateLimiter->checkLimit('global', 'system', $provider);
        $this->rateLimiter->checkLimit('provider', $provider, $provider);
        $this->rateLimiter->checkLimit('user', (string)$userId, $provider);
    }

    private function estimateAndCheckQuotas(
        string $prompt,
        string $provider,
        string $model,
        int $userId
    ): array {
        // Estimate cost before execution
        $estimated = $this->costCalculator->estimateCost($provider, $model, $prompt);

        // Check all quota types
        $this->quotaManager->checkQuota('user', $userId, 'requests', 1.0, reserve: true);
        $this->quotaManager->checkQuota('user', $userId, 'tokens', $estimated['breakdown']['prompt_tokens']);
        $this->quotaManager->checkQuota('user', $userId, 'cost', $estimated['total'], reserve: true);

        return $estimated;
    }

    private function trackSuccess(
        int $userId,
        string $provider,
        string $model,
        AiResponse $response,
        float $startTime
    ): void {
        $this->usageTracker->trackUsage([
            'user_id' => $userId,
            'provider' => $provider,
            'model' => $model,
            'feature' => 'completion',
            'prompt_tokens' => $response->getPromptTokens(),
            'completion_tokens' => $response->getCompletionTokens(),
            'cache_hit' => 0,
            'status' => 'success',
            'start_time' => $startTime,
        ]);
    }

    private function consumeQuotas(int $userId, array $tokenUsage): void
    {
        $this->quotaManager->consumeQuota('user', $userId, 'requests', 1.0, 1.0);
        $this->quotaManager->consumeQuota('user', $userId, 'tokens', $tokenUsage['total']);

        $actualCost = $this->costCalculator->calculateCost(
            provider: $tokenUsage['provider'],
            model: $tokenUsage['model'],
            promptTokens: $tokenUsage['prompt'],
            completionTokens: $tokenUsage['completion']
        );

        $this->quotaManager->consumeQuota('user', $userId, 'cost', $actualCost['total']);
    }

    private function trackError(
        int $userId,
        string $provider,
        string $errorType,
        \Exception $exception,
        float $startTime
    ): void {
        $this->usageTracker->trackUsage([
            'user_id' => $userId,
            'provider' => $provider,
            'model' => 'unknown',
            'feature' => 'completion',
            'status' => $errorType,
            'error_code' => $exception->getCode(),
            'error_message' => $exception->getMessage(),
            'start_time' => $startTime,
        ]);
    }
}
```

---

## Admin Backend Module Integration

### Quota Management UI

```php
<?php
namespace Netresearch\NrLlm\Backend\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;

class QuotaManagementController extends \TYPO3\CMS\Backend\Controller\AbstractBackendController
{
    public function __construct(
        private readonly QuotaManager $quotaManager,
        private readonly UsageTracker $usageTracker,
        private readonly ModuleTemplateFactory $moduleTemplateFactory
    ) {}

    public function indexAction(): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($this->request);

        // Get current user's quota status
        $userId = $GLOBALS['BE_USER']->user['uid'];
        $quotaStatus = $this->quotaManager->getQuotaStatus('user', $userId);

        // Get usage statistics
        $usageStats = $this->usageTracker->getUsageStats([
            'scope' => 'user',
            'scope_id' => (string)$userId,
            'date_from' => date('Y-m-d', strtotime('-30 days')),
        ]);

        $view->assignMultiple([
            'quotas' => $quotaStatus,
            'usageStats' => $usageStats,
            'isAdmin' => $GLOBALS['BE_USER']->isAdmin(),
        ]);

        return $view->renderResponse('QuotaManagement/Index');
    }

    public function updateQuotaAction(int $userId, string $quotaType, float $newLimit): ResponseInterface
    {
        if (!$GLOBALS['BE_USER']->isAdmin()) {
            throw new \RuntimeException('Only administrators can update quotas');
        }

        // Update quota configuration
        $connection = $this->connectionPool->getConnectionForTable('tx_nrllm_quota_config');

        $connection->update(
            'tx_nrllm_quota_config',
            [
                'daily_cost_limit' => $newLimit,
                'tstamp' => time(),
            ],
            [
                'config_scope' => 'user',
                'scope_id' => $userId,
            ]
        );

        $this->addFlashMessage('Quota updated successfully');

        return $this->redirect('index');
    }
}
```

### Usage Dashboard Template

```html
<!-- Resources/Private/Templates/QuotaManagement/Index.html -->
<html xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
      data-namespace-typo3-fluid="true">

<f:layout name="Default" />

<f:section name="Content">
    <h1>AI Usage Dashboard</h1>

    <!-- Quota Status Cards -->
    <div class="quota-cards">
        <f:for each="{quotas}" as="quota">
            <div class="card quota-card quota-status-{quota.status}">
                <div class="card-header">
                    <h3>{quota.type -> f:format.ucfirst()} Quota</h3>
                    <span class="badge badge-{quota.status}">{quota.status}</span>
                </div>
                <div class="card-body">
                    <div class="progress">
                        <div class="progress-bar progress-bar-{quota.status}"
                             style="width: {quota.percent_used}%"></div>
                    </div>
                    <p class="quota-details">
                        Used: {quota.used} / {quota.limit}
                        ({quota.percent_used}%)
                    </p>
                    <p class="quota-reset">
                        Resets: <f:format.date format="Y-m-d H:i:s">{quota.reset_at}</f:format.date>
                    </p>
                    <f:if condition="{quota.is_exceeded}">
                        <div class="alert alert-danger">
                            Quota exceeded. AI features are temporarily disabled.
                        </div>
                    </f:if>
                </div>
            </div>
        </f:for>
    </div>

    <!-- Usage Statistics Chart -->
    <div class="usage-chart-container">
        <h2>Usage Over Time</h2>
        <canvas id="usageChart"></canvas>
    </div>

    <!-- Usage Table -->
    <table class="table table-striped">
        <thead>
            <tr>
                <th>Date</th>
                <th>Requests</th>
                <th>Tokens</th>
                <th>Cost</th>
                <th>Cache Hit Rate</th>
            </tr>
        </thead>
        <tbody>
            <f:for each="{usageStats}" as="stat">
                <tr>
                    <td>{stat.stat_date}</td>
                    <td>{stat.total_requests}</td>
                    <td>{stat.total_tokens -> f:format.number(decimals: 0)}</td>
                    <td>${stat.total_cost -> f:format.number(decimals: 2)}</td>
                    <td>
                        {f:if(condition: '{stat.total_requests} > 0',
                              then: '{f:format.number(decimals: 1, val: \'{stat.cache_hits} / {stat.total_requests} * 100\')}%',
                              else: 'N/A')}
                    </td>
                </tr>
            </f:for>
        </tbody>
    </table>

    <script>
        // Chart.js implementation for usage visualization
        const ctx = document.getElementById('usageChart').getContext('2d');
        const usageData = <f:format.raw>{usageStats -> f:format.json()}</f:format.raw>;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: usageData.map(d => d.stat_date),
                datasets: [{
                    label: 'Cost (USD)',
                    data: usageData.map(d => d.total_cost),
                    borderColor: 'rgb(75, 192, 192)',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toFixed(2);
                            }
                        }
                    }
                }
            }
        });
    </script>
</f:section>
</html>
```

---

## CLI Commands

### Check Quota Status

```php
<?php
namespace Netresearch\NrLlm\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class QuotaStatusCommand extends Command
{
    public function __construct(
        private readonly QuotaManager $quotaManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Check quota status for a user')
            ->addArgument('userId', InputArgument::REQUIRED, 'User ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userId = (int)$input->getArgument('userId');

        $quotaStatus = $this->quotaManager->getQuotaStatus('user', $userId);

        $io->title("Quota Status for User #$userId");

        foreach ($quotaStatus as $quota) {
            $io->section(ucfirst($quota['type']) . ' Quota');

            $io->table(
                ['Metric', 'Value'],
                [
                    ['Used', $quota['used']],
                    ['Limit', $quota['limit']],
                    ['Available', $quota['available']],
                    ['Percent Used', $quota['percent_used'] . '%'],
                    ['Status', $quota['status']],
                    ['Resets At', date('Y-m-d H:i:s', $quota['reset_at'])],
                ]
            );

            if ($quota['is_exceeded']) {
                $io->error('Quota exceeded!');
            } elseif ($quota['status'] === 'critical') {
                $io->warning('Quota critical - nearing limit');
            }
        }

        return Command::SUCCESS;
    }
}
```

### Archive Old Usage Data

```php
<?php
namespace Netresearch\NrLlm\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ArchiveUsageCommand extends Command
{
    public function __construct(
        private readonly UsageTracker $usageTracker
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Archive old usage data')
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Days to keep', 90);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = (int)$input->getOption('days');

        $output->writeln("<info>Archiving usage data older than $days days...</info>");

        $archivedCount = $this->usageTracker->archiveOldData($days);

        $output->writeln("<info>Archived $archivedCount records</info>");

        return Command::SUCCESS;
    }
}
```

### Update Pricing

```php
<?php
namespace Netresearch\NrLlm\Command;

class UpdatePricingCommand extends Command
{
    public function __construct(
        private readonly CostCalculator $costCalculator
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Update provider pricing')
            ->addArgument('provider', InputArgument::REQUIRED)
            ->addArgument('model', InputArgument::REQUIRED)
            ->addOption('input-cost', null, InputOption::VALUE_REQUIRED, 'Input cost per 1M tokens')
            ->addOption('output-cost', null, InputOption::VALUE_REQUIRED, 'Output cost per 1M tokens');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $provider = $input->getArgument('provider');
        $model = $input->getArgument('model');
        $inputCost = (float)$input->getOption('input-cost');
        $outputCost = (float)$input->getOption('output-cost');

        $newVersion = $this->costCalculator->updatePricing(
            provider: $provider,
            model: $model,
            inputCostPer1M: $inputCost,
            outputCostPer1M: $outputCost
        );

        $output->writeln("<info>Updated pricing for $provider/$model to version $newVersion</info>");

        return Command::SUCCESS;
    }
}
```

---

## Best Practices

### 1. Quota Reservation Pattern

For multi-step operations where you need to ensure quota availability:

```php
try {
    // Reserve quota before starting expensive operation
    $this->quotaManager->checkQuota('user', $userId, 'cost', $estimatedCost, reserve: true);

    // Perform operation
    $result = $this->performExpensiveOperation();

    // Consume reserved quota with actual cost
    $this->quotaManager->consumeQuota('user', $userId, 'cost', $actualCost, $estimatedCost);

} catch (\Exception $e) {
    // Release reserved quota on error
    $this->quotaManager->releaseQuota('user', $userId, 'cost', $estimatedCost);
    throw $e;
}
```

### 2. Graceful Degradation

Always provide user-friendly error messages:

```php
try {
    $response = $this->aiService->complete($prompt);
} catch (RateLimitExceededException $e) {
    return new JsonResponse([
        'error' => 'rate_limit_exceeded',
        'message' => sprintf(
            'You have reached your rate limit. Please wait %d seconds before trying again.',
            $e->retryAfter
        ),
        'retry_after' => $e->retryAfter,
        'reset_at' => date('c', time() + $e->retryAfter),
    ], 429);
} catch (QuotaExceededException $e) {
    return new JsonResponse([
        'error' => 'quota_exceeded',
        'message' => sprintf(
            'Your %s quota has been exceeded. Quota resets on %s.',
            $e->quotaType,
            date('Y-m-d H:i:s', $e->resetAt)
        ),
        'quota_type' => $e->quotaType,
        'used' => $e->used,
        'limit' => $e->limit,
        'reset_at' => date('c', $e->resetAt),
    ], 402);
}
```

### 3. Monitoring & Alerting

Set up proactive monitoring:

```php
// Scheduler task to check global quotas
class QuotaMonitoringTask extends AbstractTask
{
    public function execute(): bool
    {
        $quotaManager = GeneralUtility::makeInstance(QuotaManager::class);
        $notificationService = GeneralUtility::makeInstance(NotificationService::class);

        $globalQuotas = $quotaManager->getQuotaStatus('global', 0);

        foreach ($globalQuotas as $quota) {
            if ($quota['percent_used'] >= 90) {
                $notificationService->sendAdminAlert(
                    subject: "Global {$quota['type']} quota at {$quota['percent_used']}%",
                    message: "Global quota is nearing limit. Consider increasing quota or reviewing usage patterns.",
                    data: $quota
                );
            }
        }

        return true;
    }
}
```

### 4. Cache Strategy

Maximize cache hits to reduce costs:

```php
class AiServiceManager
{
    private function getCacheKey(string $prompt, array $options): string
    {
        $normalized = [
            'prompt' => trim($prompt),
            'model' => $options['model'] ?? 'default',
            'temperature' => $options['temperature'] ?? 0.7,
            // Don't include user_id - allow cross-user cache hits
        ];

        return 'ai:response:' . hash('sha256', json_encode($normalized));
    }

    public function complete(string $prompt, array $options = []): AiResponse
    {
        $cacheKey = $this->getCacheKey($prompt, $options);

        // Check cache first
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false) {
            // Track cache hit (no quota consumption)
            $this->trackCacheHit($prompt, $cached);
            return $cached;
        }

        // ... execute request and cache response
    }
}
```

### 5. Cost Optimization

Automatically select cheapest provider for task:

```php
class ProviderSelector
{
    public function selectOptimalProvider(
        string $feature,
        array $requirements,
        int $estimatedTokens
    ): string {
        $providers = ['openai', 'anthropic', 'google'];
        $costs = [];

        foreach ($providers as $provider) {
            $model = $this->getDefaultModel($provider, $feature);

            if ($this->meetsRequirements($provider, $model, $requirements)) {
                $cost = $this->costCalculator->estimateCost(
                    provider: $provider,
                    model: $model,
                    prompt: str_repeat('x', $estimatedTokens * 4)
                );

                $costs[$provider] = $cost['total'];
            }
        }

        asort($costs);
        return array_key_first($costs);
    }
}
```

---

## Troubleshooting

### Issue: Rate Limit False Positives

**Problem**: Users hitting rate limits despite low usage.

**Solution**: Check cache backend configuration. If using file cache, atomic operations may fail.

```php
// Recommended: Use Redis for rate limiting
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_ratelimit'] = [
    'backend' => \TYPO3\CMS\Core\Cache\Backend\RedisBackend::class,
    'options' => [
        'hostname' => '127.0.0.1',
        'port' => 6379,
    ],
];
```

### Issue: Quota Not Resetting

**Problem**: Quotas don't reset at period boundaries.

**Solution**: Ensure `ensureQuotaPeriodCurrent()` is called on every quota check. Verify timezone configuration.

```php
// Check timezone settings
date_default_timezone_set($GLOBALS['TYPO3_CONF_VARS']['SYS']['phpTimeZone'] ?? 'UTC');
```

### Issue: Cost Calculation Inaccuracy

**Problem**: Estimated costs don't match provider bills.

**Solution**: Update pricing regularly and verify token counting.

```bash
# Update pricing via CLI
./vendor/bin/typo3 nrllm:pricing:update openai gpt-4-turbo --input-cost=10.00 --output-cost=30.00
```

### Issue: High Database Load

**Problem**: Usage tracking causing slow queries.

**Solution**: Use aggregated stats table for reports, ensure proper indexing.

```sql
-- Verify indexes exist
SHOW INDEX FROM tx_nrllm_usage;

-- Add missing indexes if needed
CREATE INDEX idx_usage_user_date ON tx_nrllm_usage(user_id, DATE(FROM_UNIXTIME(tstamp)));
```

---

## Performance Benchmarks

### Target Metrics

| Operation | Target Latency | Notes |
|-----------|---------------|-------|
| Rate limit check | <5ms | Cache hit |
| Quota check | <10ms | Cache hit |
| Usage tracking | <20ms | Async if possible |
| Cost calculation | <5ms | Cached pricing |
| Notification send | <100ms | Email async |

### Optimization Tips

1. **Use Redis for hot data**: Rate limits, active quotas
2. **Batch database writes**: Aggregate stats updates
3. **Index frequently queried fields**: user_id, tstamp, provider
4. **Cache pricing data**: 24-hour TTL
5. **Async notifications**: Queue for background processing

---

## Security Checklist

- [ ] Rate limiting enabled globally
- [ ] Per-user quotas configured
- [ ] Pricing data stored securely (admin-only write access)
- [ ] Usage data anonymization after retention period
- [ ] IP address storage configurable (GDPR compliance)
- [ ] Admin notifications for quota violations
- [ ] Audit trail for quota configuration changes
- [ ] SQL injection protection in all queries
- [ ] Input validation on quota updates

---

## Migration Guide

### Upgrading from No Rate Limiting

1. **Install new database tables**:
   ```bash
   ./vendor/bin/typo3 upgrade:run
   ```

2. **Configure default quotas** in extension configuration

3. **Import pricing data**:
   ```php
   $costCalculator->importInitialPricing();
   ```

4. **Enable rate limiting gradually**:
   - Start with high limits
   - Monitor usage patterns
   - Adjust limits based on data

5. **Notify users** about new quota system

---

## Support & Resources

- **Documentation**: `/docs/rate-limiting.md`
- **Admin Module**: TYPO3 Backend → Tools → AI Usage
- **CLI Commands**: `./vendor/bin/typo3 help nrllm:*`
- **Logs**: Check `typo3temp/var/log/` for rate limiting errors
- **Support**: Create issue on GitHub with usage logs

---

## Appendix: SQL Queries for Common Reports

### Daily Cost by Provider

```sql
SELECT
    DATE(FROM_UNIXTIME(tstamp)) as date,
    provider,
    SUM(estimated_cost) as total_cost,
    COUNT(*) as request_count
FROM tx_nrllm_usage
WHERE tstamp >= UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY))
GROUP BY DATE(FROM_UNIXTIME(tstamp)), provider
ORDER BY date DESC, total_cost DESC;
```

### Top Users by Cost

```sql
SELECT
    u.user_id,
    be.username,
    SUM(u.estimated_cost) as total_cost,
    COUNT(*) as request_count,
    AVG(u.request_time_ms) as avg_response_time
FROM tx_nrllm_usage u
JOIN be_users be ON u.user_id = be.uid
WHERE u.tstamp >= UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 7 DAY))
GROUP BY u.user_id
ORDER BY total_cost DESC
LIMIT 10;
```

### Cache Effectiveness

```sql
SELECT
    DATE(FROM_UNIXTIME(tstamp)) as date,
    SUM(CASE WHEN cache_hit = 1 THEN 1 ELSE 0 END) as cache_hits,
    SUM(CASE WHEN cache_hit = 0 THEN 1 ELSE 0 END) as cache_misses,
    ROUND(SUM(CASE WHEN cache_hit = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as hit_rate,
    SUM(CASE WHEN cache_hit = 1 THEN 0 ELSE estimated_cost END) as costs_incurred,
    SUM(CASE WHEN cache_hit = 1 THEN estimated_cost ELSE 0 END) as costs_saved
FROM tx_nrllm_usage
WHERE tstamp >= UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY))
GROUP BY DATE(FROM_UNIXTIME(tstamp))
ORDER BY date DESC;
```

### Quota Violations

```sql
SELECT
    scope,
    scope_id,
    quota_type,
    quota_used,
    quota_limit,
    FROM_UNIXTIME(exceeded_at) as exceeded_at,
    FROM_UNIXTIME(period_end) as resets_at
FROM tx_nrllm_quotas
WHERE is_exceeded = 1
ORDER BY exceeded_at DESC;
```
