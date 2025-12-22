# Rate Limiting, Quota Management & Usage Tracking Architecture

> Analysis Date: 2025-12-22
> Purpose: Comprehensive cost control, abuse protection, and usage analytics for nr-llm extension

---

## 1. System Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                     AI Request Flow                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  User Request → AccessControl → RateLimiter → QuotaManager       │
│                                       ↓                           │
│                                  Provider API                     │
│                                       ↓                           │
│                   UsageTracker ← Response Processing              │
│                                       ↓                           │
│                              Cache & Return                       │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

### Key Requirements

1. **Rate Limiting**: Prevent API abuse, respect provider limits
2. **Quota Management**: Control costs per user/group/site
3. **Usage Tracking**: Audit trail, cost analysis, billing data
4. **Graceful Degradation**: Informative errors, not silent failures
5. **Performance**: Minimal overhead (<10ms per request)
6. **Accuracy**: Precise token counting and cost calculation

---

## 2. Rate Limiting Strategy

### Algorithm Selection Matrix

| Algorithm | Pros | Cons | Use Case |
|-----------|------|------|----------|
| **Token Bucket** | Allows bursts, smooth rate | Complex state management | Per-user limits |
| **Sliding Window** | Strict guarantees, predictable | Higher memory usage | Per-provider API limits |
| **Leaky Bucket** | Constant rate, simple | No burst allowance | Global rate limiting |
| **Fixed Window** | Simplest, least overhead | Burst at window edges | Non-critical limits |

### Implementation Decision

**Primary**: Token Bucket (per-user, per-provider)
**Secondary**: Sliding Window (global limits)
**Fallback**: Fixed Window (when cache unavailable)

### Rate Limiting Levels

```
┌─────────────────────────────────────────────────────────┐
│                Rate Limiting Hierarchy                   │
├─────────────────────────────────────────────────────────┤
│                                                           │
│  Level 1: Global Rate Limit (all requests)               │
│  ├─ 10,000 requests/hour system-wide                     │
│  ├─ Storage: Database with distributed lock              │
│  └─ Purpose: Protect infrastructure                      │
│                                                           │
│  Level 2: Per-Provider Rate Limit                        │
│  ├─ Respect external API limits (OpenAI: 3500/min)       │
│  ├─ Storage: Cache + database fallback                   │
│  └─ Purpose: Avoid provider throttling                   │
│                                                           │
│  Level 3: Per-User Rate Limit                            │
│  ├─ Configurable per user/group (default: 100/hour)      │
│  ├─ Storage: Cache-first, DB-backed                      │
│  └─ Purpose: Fair usage, prevent abuse                   │
│                                                           │
│  Level 4: Per-Feature Rate Limit                         │
│  ├─ Different limits for different features              │
│  ├─ Example: Translation (100/hr), Vision (20/hr)        │
│  └─ Purpose: Feature-specific cost control               │
│                                                           │
└─────────────────────────────────────────────────────────┘
```

---

## 3. Quota Management Design

### Quota Types

```yaml
Request_Quotas:
  unit: count
  scopes: [user, group, site, global]
  periods: [hourly, daily, weekly, monthly]
  actions: [warn_80, block_100, notify_admin]

Token_Quotas:
  unit: tokens
  types: [prompt, completion, total]
  scopes: [user, group, site, global]
  tracking: real-time

Cost_Quotas:
  unit: USD
  scopes: [user, group, site, global]
  periods: [daily, weekly, monthly, annual]
  precision: 6 decimal places
```

### Quota Hierarchy & Inheritance

```
Global Quota (Site-wide)
    ├─ Cost: $1000/month
    ├─ Requests: 100,000/month
    └─ Tokens: 10M/month
        │
        ├─ Group: Marketing
        │   ├─ Cost: $300/month (30% of global)
        │   ├─ Requests: 30,000/month
        │   └─ Users inherit group limits
        │       ├─ User: editor1 (default: $10/day)
        │       └─ User: editor2 (custom: $50/day)
        │
        └─ Group: Content Team
            ├─ Cost: $500/month (50% of global)
            └─ Per-user default: $15/day
```

### Quota Actions & Thresholds

| Threshold | Action | Notification | Behavior |
|-----------|--------|--------------|----------|
| 50% | Log | None | Continue normally |
| 80% | Warn | Email to user | Show warning in UI |
| 90% | Alert | Email to user + admin | Prominent warning |
| 100% | Block | Email to user + admin | Reject requests with friendly error |
| 110% | Hard Block | Admin notification | Prevent all AI operations |

---

## 4. Database Schema

### Core Tables

```sql
-- =========================================================================
-- Rate Limiting State Storage
-- =========================================================================

CREATE TABLE tx_nrllm_ratelimit_state (
    uid int(11) NOT NULL auto_increment,

    -- Rate limit identifier
    limit_key varchar(255) NOT NULL,
    limit_scope varchar(50) NOT NULL,  -- 'global', 'provider', 'user', 'feature'
    scope_id varchar(100) DEFAULT '',  -- user_id, provider name, feature name

    -- Token bucket state
    tokens_available decimal(10,2) NOT NULL DEFAULT 0,
    tokens_capacity decimal(10,2) NOT NULL,
    last_refill_time int(11) NOT NULL,
    refill_rate decimal(10,2) NOT NULL,  -- tokens per second

    -- Sliding window state
    window_start int(11) NOT NULL,
    window_requests int(11) DEFAULT 0,
    window_size int(11) NOT NULL,  -- seconds

    -- Metadata
    tstamp int(11) DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY limit_key (limit_key),
    KEY scope_lookup (limit_scope, scope_id),
    KEY cleanup (tstamp)
) ENGINE=InnoDB;


-- =========================================================================
-- Quota Management
-- =========================================================================

CREATE TABLE tx_nrllm_quotas (
    uid int(11) NOT NULL auto_increment,

    -- Quota scope
    scope varchar(50) NOT NULL,  -- 'user', 'group', 'site', 'global'
    scope_id int(11) DEFAULT 0,

    -- Quota type
    quota_type varchar(50) NOT NULL,  -- 'requests', 'tokens', 'cost'
    quota_period varchar(20) NOT NULL,  -- 'hourly', 'daily', 'weekly', 'monthly'

    -- Period tracking
    period_start int(11) NOT NULL,
    period_end int(11) NOT NULL,

    -- Limits
    quota_limit decimal(15,6) NOT NULL,  -- Supports cost precision
    quota_used decimal(15,6) DEFAULT 0,
    quota_reserved decimal(15,6) DEFAULT 0,  -- For in-flight requests

    -- Thresholds
    warn_threshold decimal(5,2) DEFAULT 80.00,  -- Percentage
    alert_threshold decimal(5,2) DEFAULT 90.00,

    -- Status
    is_active tinyint(1) DEFAULT 1,
    is_exceeded tinyint(1) DEFAULT 0,
    exceeded_at int(11) DEFAULT 0,

    -- Notifications
    last_warning_sent int(11) DEFAULT 0,
    warning_count int(11) DEFAULT 0,

    -- Metadata
    tstamp int(11) DEFAULT '0' NOT NULL,
    crdate int(11) DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY scope_period (scope, scope_id, quota_type, period_start),
    KEY active_quotas (is_active, period_end),
    KEY exceeded_quotas (is_exceeded)
) ENGINE=InnoDB;


-- =========================================================================
-- Usage Tracking (Primary Event Log)
-- =========================================================================

CREATE TABLE tx_nrllm_usage (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    -- Request context
    user_id int(11) NOT NULL,
    user_group int(11) DEFAULT 0,
    site_id int(11) DEFAULT 0,

    -- AI provider details
    provider varchar(50) NOT NULL,
    model varchar(100) NOT NULL,
    feature varchar(100) NOT NULL,

    -- Token usage
    prompt_tokens int(11) DEFAULT 0,
    completion_tokens int(11) DEFAULT 0,
    total_tokens int(11) DEFAULT 0,

    -- Cost calculation
    estimated_cost decimal(10,6) DEFAULT 0.000000,
    cost_currency varchar(3) DEFAULT 'USD',
    pricing_version int(11) DEFAULT 1,  -- Track pricing table version

    -- Performance metrics
    request_time_ms int(11) DEFAULT 0,
    cache_hit tinyint(1) DEFAULT 0,

    -- Request metadata
    request_hash varchar(64) DEFAULT '',  -- SHA256 of normalized request
    ip_address varchar(45) DEFAULT '',
    user_agent text,

    -- Response status
    status varchar(20) DEFAULT 'success',  -- 'success', 'error', 'quota_exceeded', 'rate_limited'
    error_code varchar(50) DEFAULT '',
    error_message text,

    -- Audit trail
    tstamp int(11) DEFAULT '0' NOT NULL,
    crdate int(11) DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY user_tracking (user_id, tstamp),
    KEY provider_feature (provider, feature),
    KEY cost_analysis (tstamp, estimated_cost),
    KEY cache_analysis (cache_hit, tstamp),
    KEY request_dedup (request_hash),
    KEY status_tracking (status, tstamp)
) ENGINE=InnoDB;


-- =========================================================================
-- Provider Pricing Tables
-- =========================================================================

CREATE TABLE tx_nrllm_pricing (
    uid int(11) NOT NULL auto_increment,

    -- Provider identification
    provider varchar(50) NOT NULL,
    model varchar(100) NOT NULL,

    -- Pricing (per 1M tokens)
    input_cost_per_1m decimal(10,6) NOT NULL,
    output_cost_per_1m decimal(10,6) NOT NULL,
    currency varchar(3) DEFAULT 'USD',

    -- Version control
    version int(11) DEFAULT 1,
    effective_from int(11) NOT NULL,
    effective_until int(11) DEFAULT 0,  -- 0 = current

    -- Metadata
    source varchar(255) DEFAULT '',  -- URL to pricing page
    notes text,

    tstamp int(11) DEFAULT '0' NOT NULL,
    crdate int(11) DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY provider_model (provider, model, effective_until),
    KEY version_tracking (version, effective_from)
) ENGINE=InnoDB;


-- =========================================================================
-- Aggregated Usage Statistics (for performance)
-- =========================================================================

CREATE TABLE tx_nrllm_usage_stats (
    uid int(11) NOT NULL auto_increment,

    -- Aggregation key
    stat_date date NOT NULL,
    stat_hour tinyint(2) DEFAULT NULL,  -- NULL for daily aggregates

    -- Scope
    scope varchar(50) NOT NULL,  -- 'user', 'group', 'site', 'global', 'provider', 'feature'
    scope_id varchar(100) DEFAULT '',

    -- Aggregated metrics
    total_requests int(11) DEFAULT 0,
    total_tokens int(11) DEFAULT 0,
    total_cost decimal(12,6) DEFAULT 0,

    cache_hits int(11) DEFAULT 0,
    cache_misses int(11) DEFAULT 0,

    avg_request_time_ms decimal(10,2) DEFAULT 0,
    max_request_time_ms int(11) DEFAULT 0,

    error_count int(11) DEFAULT 0,

    -- Popular models/features
    top_model varchar(100) DEFAULT '',
    top_feature varchar(100) DEFAULT '',

    -- Metadata
    tstamp int(11) DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY stat_key (stat_date, stat_hour, scope, scope_id),
    KEY date_range (stat_date),
    KEY scope_analysis (scope, stat_date)
) ENGINE=InnoDB;


-- =========================================================================
-- Quota Configuration Templates
-- =========================================================================

CREATE TABLE tx_nrllm_quota_config (
    uid int(11) NOT NULL auto_increment,

    -- Configuration identity
    config_name varchar(100) NOT NULL,
    config_scope varchar(50) NOT NULL,  -- 'default', 'group', 'user'
    target_group int(11) DEFAULT 0,

    -- Quota limits
    hourly_request_limit int(11) DEFAULT 0,
    daily_request_limit int(11) DEFAULT 0,
    monthly_request_limit int(11) DEFAULT 0,

    daily_cost_limit decimal(10,6) DEFAULT 0,
    monthly_cost_limit decimal(10,6) DEFAULT 0,

    daily_token_limit int(11) DEFAULT 0,
    monthly_token_limit int(11) DEFAULT 0,

    -- Feature-specific limits (JSON)
    feature_limits text,  -- JSON: {"translation": {"daily": 100}, "vision": {"daily": 20}}

    -- Priority & inheritance
    priority int(11) DEFAULT 0,  -- Higher priority wins
    inherit_from int(11) DEFAULT 0,  -- UID of parent config

    -- Status
    is_active tinyint(1) DEFAULT 1,

    tstamp int(11) DEFAULT '0' NOT NULL,
    crdate int(11) DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY scope_lookup (config_scope, is_active),
    KEY inheritance (inherit_from)
) ENGINE=InnoDB;


-- =========================================================================
-- Notification History
-- =========================================================================

CREATE TABLE tx_nrllm_notifications (
    uid int(11) NOT NULL auto_increment,

    -- Notification context
    notification_type varchar(50) NOT NULL,  -- 'quota_warning', 'quota_exceeded', 'rate_limited'
    severity varchar(20) NOT NULL,  -- 'info', 'warning', 'critical'

    -- Target
    recipient_type varchar(50) NOT NULL,  -- 'user', 'admin', 'group_admin'
    recipient_id int(11) NOT NULL,

    -- Message details
    subject varchar(255) NOT NULL,
    message text,
    notification_data text,  -- JSON with additional context

    -- Status
    sent tinyint(1) DEFAULT 0,
    sent_at int(11) DEFAULT 0,

    -- Related records
    quota_uid int(11) DEFAULT 0,
    usage_uid int(11) DEFAULT 0,

    tstamp int(11) DEFAULT '0' NOT NULL,
    crdate int(11) DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY recipient_lookup (recipient_type, recipient_id, sent),
    KEY quota_notifications (quota_uid),
    KEY type_severity (notification_type, severity, crdate)
) ENGINE=InnoDB;
```

---

## 5. Storage Strategy

### Cache vs Database Trade-offs

| Aspect | Cache (Redis/Memcache) | Database |
|--------|------------------------|----------|
| **Speed** | <1ms read/write | 5-20ms read/write |
| **Persistence** | Volatile (lost on restart) | Persistent |
| **Atomicity** | Limited (INCR, DECR) | Full ACID transactions |
| **Queries** | Key-value only | Complex queries, aggregations |
| **Scalability** | Horizontal scaling | Vertical + sharding |
| **Best For** | Hot path, frequent updates | Audit trail, reporting |

### Hybrid Storage Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    Storage Architecture                      │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│  Rate Limiting:                                               │
│  ├─ Primary: Cache (Redis preferred)                         │
│  ├─ Fallback: Database with row-level locking                │
│  └─ Sync: Background job writes cache → DB every 5min        │
│                                                               │
│  Quota Management:                                            │
│  ├─ Read: Cache-first, DB fallback                           │
│  ├─ Write: Dual-write (cache + DB)                           │
│  └─ Consistency: DB is source of truth                       │
│                                                               │
│  Usage Tracking:                                              │
│  ├─ Write: Database only (audit trail)                       │
│  ├─ Hot data: Cached aggregate queries (1hr TTL)             │
│  └─ Archival: Move >90 day data to archive table             │
│                                                               │
│  Pricing Data:                                                │
│  ├─ Storage: Database (versioned)                            │
│  ├─ Cache: Current pricing (24hr TTL)                        │
│  └─ Update: Admin UI + background sync job                   │
│                                                               │
└─────────────────────────────────────────────────────────────┘
```

---

## 6. Cost Calculation Design

### Pricing Table Structure

```php
// Initial pricing data (as of 2024)
$pricingData = [
    'openai' => [
        'gpt-4-turbo' => [
            'input_per_1m' => 10.00,
            'output_per_1m' => 30.00,
            'effective_from' => '2024-01-01',
        ],
        'gpt-3.5-turbo' => [
            'input_per_1m' => 0.50,
            'output_per_1m' => 1.50,
        ],
    ],
    'anthropic' => [
        'claude-3-opus-20240229' => [
            'input_per_1m' => 15.00,
            'output_per_1m' => 75.00,
        ],
        'claude-3-sonnet-20240229' => [
            'input_per_1m' => 3.00,
            'output_per_1m' => 15.00,
        ],
        'claude-3-haiku-20240307' => [
            'input_per_1m' => 0.25,
            'output_per_1m' => 1.25,
        ],
    ],
    'google' => [
        'gemini-pro' => [
            'input_per_1m' => 0.50,
            'output_per_1m' => 1.50,
        ],
        'gemini-pro-vision' => [
            'input_per_1m' => 0.50,
            'output_per_1m' => 1.50,
            'images_per_1000' => 0.25,
        ],
    ],
];
```

### Cost Calculation Algorithm

```
1. Retrieve current pricing for provider + model
2. Calculate input cost: (prompt_tokens / 1,000,000) * input_per_1m
3. Calculate output cost: (completion_tokens / 1,000,000) * output_per_1m
4. Add image costs if applicable: (image_count / 1000) * images_per_1000
5. Apply currency conversion if needed
6. Round to 6 decimal places
7. Store with pricing version reference
```

### Pricing Update Strategy

```yaml
Manual_Update:
  - Admin UI for price changes
  - Requires version increment
  - Historical tracking preserved

Automated_Sync:
  - Weekly cron job scrapes provider pages
  - Alerts admin on price changes
  - Auto-applies after admin approval

Version_Control:
  - Each price change = new version
  - Usage records reference pricing version
  - Enables historical cost analysis
```

### Currency Handling

```yaml
Primary_Currency: USD
  - All internal calculations in USD
  - Pricing tables store USD values

Display_Currency:
  - User preference (EUR, GBP, etc.)
  - Conversion at display time only
  - Exchange rates cached (daily update)

Multi_Currency_Quotas:
  - Quotas defined in single currency
  - Converted for display
  - Prevents exchange rate gaming
```

---

## 7. Performance Optimization

### Caching Strategy

```yaml
Response_Cache:
  key_format: "airesponse:{hash}"
  ttl: 3600  # 1 hour default
  storage: Redis/Database
  invalidation: Manual or time-based

Rate_Limit_Cache:
  key_format: "ratelimit:{scope}:{id}"
  ttl: 3600
  storage: Redis (atomic operations)
  sync_to_db: Every 5 minutes

Quota_Cache:
  key_format: "quota:{scope}:{id}:{period}"
  ttl: 300  # 5 minutes
  storage: Redis
  write_through: Yes (DB + cache)

Pricing_Cache:
  key_format: "pricing:{provider}:{model}"
  ttl: 86400  # 24 hours
  storage: Redis/Local memory
  update: On admin change or daily sync
```

### Index Strategy

```sql
-- High-frequency queries optimized

-- User dashboard: recent usage
CREATE INDEX idx_usage_user_recent
ON tx_nrllm_usage(user_id, tstamp DESC);

-- Quota checking: active quotas for scope
CREATE INDEX idx_quota_active_scope
ON tx_nrllm_quotas(scope, scope_id, quota_type, period_end)
WHERE is_active = 1;

-- Cost reports: time-based aggregation
CREATE INDEX idx_usage_cost_time
ON tx_nrllm_usage(tstamp, estimated_cost, provider);

-- Rate limit lookups
CREATE INDEX idx_ratelimit_scope
ON tx_nrllm_ratelimit_state(limit_scope, scope_id, tstamp);

-- Pricing lookups: current pricing
CREATE INDEX idx_pricing_current
ON tx_nrllm_pricing(provider, model, effective_until)
WHERE effective_until = 0;
```

### Query Optimization

```sql
-- Use aggregated stats table for reporting
-- Instead of:
SELECT SUM(estimated_cost) FROM tx_nrllm_usage
WHERE user_id = 123 AND tstamp >= UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY));

-- Use:
SELECT SUM(total_cost) FROM tx_nrllm_usage_stats
WHERE scope = 'user' AND scope_id = '123'
AND stat_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY);

-- Pre-compute daily aggregates via cron job
```

---

## 8. Error Handling & User Experience

### Graceful Degradation Hierarchy

```
1. Request Received
   ↓
2. Rate Limit Check
   ├─ PASS → Continue
   └─ FAIL → Return friendly error
              "You've made 100 requests this hour. Please wait 15 minutes."
              Show: retry_after timestamp, current usage, limit
   ↓
3. Quota Check
   ├─ PASS → Continue
   └─ FAIL → Return quota exceeded error
              "Daily budget of $10 reached. Resets at midnight UTC."
              Show: current usage, limit, reset time, upgrade options
   ↓
4. Execute Request
   ├─ SUCCESS → Track usage, return response
   └─ PROVIDER ERROR →
       ├─ Fallback to alternative provider
       └─ Track partial usage (if tokens consumed)
```

### Error Response Format

```php
class RateLimitException extends \Exception
{
    public function __construct(
        public readonly int $currentUsage,
        public readonly int $limit,
        public readonly int $retryAfter,
        public readonly string $scope,
    ) {
        parent::__construct(
            "Rate limit exceeded. Used $currentUsage/$limit. Retry after $retryAfter seconds.",
            429
        );
    }

    public function toArray(): array
    {
        return [
            'error' => 'rate_limit_exceeded',
            'message' => $this->getMessage(),
            'current_usage' => $this->currentUsage,
            'limit' => $this->limit,
            'retry_after' => $this->retryAfter,
            'reset_at' => time() + $this->retryAfter,
            'scope' => $this->scope,
        ];
    }
}

class QuotaExceededException extends \Exception
{
    public function __construct(
        public readonly string $quotaType,
        public readonly float $used,
        public readonly float $limit,
        public readonly int $resetAt,
    ) {
        parent::__construct(
            "Quota exceeded for $quotaType. Used $used/$limit. Resets at " . date('Y-m-d H:i:s', $resetAt),
            402
        );
    }
}
```

### User Notifications

```yaml
Warning_Notifications:
  trigger: 80% quota threshold
  frequency: Once per period
  channels: [email, backend_notification]
  message: |
    You've used 80% of your daily AI quota.
    Used: $8.50 / $10.00
    Resets: Tonight at midnight UTC

Alert_Notifications:
  trigger: 90% quota threshold
  frequency: Once per period
  channels: [email, backend_notification, dashboard_banner]
  message: |
    Warning: Only 10% of your AI quota remaining.
    Consider upgrading or reducing usage.

Exceeded_Notifications:
  trigger: 100% quota threshold
  frequency: Once per period + on each rejection
  channels: [email, backend_notification, modal_on_attempt]
  message: |
    AI quota exceeded.
    Your $10 daily budget has been reached.
    Resets: Tonight at midnight UTC
    Contact admin to increase quota.

Admin_Notifications:
  trigger: Global quota at 90% or user exceeds quota
  frequency: Once per day
  channels: [email]
  message: |
    AI Usage Alert
    - Global quota: 90% used ($900 / $1000)
    - User 'editor1' exceeded quota (3 times today)
    - Top costs: Vision API ($450), Translation ($300)
```

---

## 9. Monitoring & Analytics

### Key Metrics Dashboard

```yaml
Real_Time_Metrics:
  - Current rate limit usage (gauge)
  - Active quotas status (progress bars)
  - Requests per minute (time series)
  - Estimated cost per hour (counter)
  - Cache hit rate (percentage)

Daily_Reports:
  - Total requests by provider
  - Cost breakdown by feature
  - Top users by usage/cost
  - Error rate trends
  - Quota violations

Weekly_Analysis:
  - Cost trends (week-over-week)
  - Usage patterns (peak hours)
  - Provider performance comparison
  - Feature adoption rates
  - ROI analysis per feature

Monthly_Executive_Summary:
  - Total spend vs budget
  - Cost per active user
  - Feature utilization
  - Savings from caching
  - Recommendations for optimization
```

### Alerting Thresholds

```yaml
Critical_Alerts:
  - Global quota exceeded
  - Provider API errors >5% in 5min
  - Rate limit violations >100/min
  - Cost spike >200% vs average

Warning_Alerts:
  - Any quota at 80%
  - Cache hit rate <40%
  - Average response time >2s
  - Provider fallbacks >10% of requests

Info_Alerts:
  - New user quota warnings
  - Daily usage summary (if >$50)
  - Pricing updates available
```

---

## 10. Configuration Examples

### Extension Configuration

```php
# ext_conf_template.txt

# cat=rate_limiting; type=int+; label=Global Requests per Hour
rateLimiting.global.requestsPerHour = 10000

# cat=rate_limiting; type=int+; label=Default User Requests per Hour
rateLimiting.user.requestsPerHour = 100

# cat=rate_limiting; type=options[cache,database,hybrid]; label=Storage Backend
rateLimiting.storage = hybrid

# cat=quotas; type=int+; label=Default Daily Request Limit
quotas.default.dailyRequests = 100

# cat=quotas; type=float; label=Default Daily Cost Limit (USD)
quotas.default.dailyCost = 10.00

# cat=quotas; type=float; label=Default Monthly Cost Limit (USD)
quotas.default.monthlyCost = 200.00

# cat=quotas/notifications; type=int+; label=Warning Threshold (%)
quotas.notifications.warningThreshold = 80

# cat=quotas/notifications; type=int+; label=Alert Threshold (%)
quotas.notifications.alertThreshold = 90

# cat=usage_tracking; type=boolean; label=Enable Usage Tracking
usageTracking.enabled = 1

# cat=usage_tracking; type=boolean; label=Store IP Addresses
usageTracking.storeIpAddress = 0

# cat=usage_tracking; type=int+; label=Archive Usage Data After (days)
usageTracking.archiveAfterDays = 90

# cat=pricing; type=options[manual,auto_sync]; label=Pricing Update Mode
pricing.updateMode = auto_sync

# cat=pricing; type=string; label=Exchange Rate API Key (optional)
pricing.exchangeRateApiKey =
```

### Per-Provider Rate Limits

```yaml
# Configuration/RateLimits.yaml

providers:
  openai:
    requests_per_minute: 3500
    tokens_per_minute: 90000
    tokens_per_day: 1000000

  anthropic:
    requests_per_minute: 1000
    tokens_per_minute: 40000

  google:
    requests_per_minute: 60
    requests_per_day: 1500

  ollama:
    # Local - no external limits
    requests_per_minute: 0  # unlimited
```

---

## 11. Implementation Checklist

### Phase 1: Core Infrastructure
- [ ] Database schema implementation
- [ ] RateLimiter service with token bucket algorithm
- [ ] QuotaManager service with period tracking
- [ ] UsageTracker service with event logging
- [ ] Cache abstraction layer

### Phase 2: Storage & Persistence
- [ ] Redis/Cache integration for rate limits
- [ ] Database fallback mechanism
- [ ] Background sync job (cache → DB)
- [ ] Index optimization
- [ ] Archival strategy for old usage data

### Phase 3: Cost Calculation
- [ ] PricingService with version control
- [ ] Initial pricing data import
- [ ] Cost calculation in usage tracker
- [ ] Currency conversion support
- [ ] Pricing update admin UI

### Phase 4: Quota Management
- [ ] Quota configuration UI
- [ ] Quota inheritance system
- [ ] Period rollover logic
- [ ] Quota reservation for in-flight requests
- [ ] Exceeded quota handling

### Phase 5: User Experience
- [ ] Friendly error messages
- [ ] Usage dashboard for end users
- [ ] Email notification system
- [ ] Backend module integration
- [ ] Real-time usage widgets

### Phase 6: Monitoring & Analytics
- [ ] Usage statistics aggregation job
- [ ] Admin analytics dashboard
- [ ] Cost reports
- [ ] Alert system configuration
- [ ] Export functionality (CSV, PDF)

### Phase 7: Testing & Optimization
- [ ] Unit tests for rate limiting algorithms
- [ ] Integration tests for quota management
- [ ] Load testing for high-traffic scenarios
- [ ] Cache performance optimization
- [ ] Database query optimization

---

## 12. Security Considerations

### Preventing Abuse

```yaml
Input_Validation:
  - Validate all user inputs before quota checks
  - Sanitize before usage tracking
  - Prevent SQL injection in custom filters

Rate_Limit_Bypass_Prevention:
  - Use atomic operations for counters
  - Validate timestamps (prevent clock skew attacks)
  - Hash user identifiers (prevent enumeration)
  - Monitor for unusual patterns

Quota_Manipulation_Prevention:
  - Server-side validation only (no client trust)
  - Audit log for quota changes
  - Require admin approval for increases
  - Alerts on suspicious quota exhaustion patterns

Data_Privacy:
  - Optional IP address storage (GDPR)
  - Anonymize usage data after 90 days
  - User-controlled data export
  - Configurable retention policies
```

### Access Control

```yaml
Permissions:
  - view_own_usage: All authenticated users
  - view_group_usage: Group admins
  - view_all_usage: Site admins
  - manage_quotas: Site admins only
  - update_pricing: Super admins only
  - export_data: Admins + data protection officer
```

---

## 13. Future Enhancements

### Planned Features

1. **Predictive Quotas**: ML-based usage prediction
2. **Dynamic Pricing**: Real-time pricing updates via API
3. **Cost Optimization**: Auto-select cheapest provider for task
4. **Usage Analytics AI**: Anomaly detection, cost optimization suggestions
5. **Multi-Tenancy**: Complete isolation for multi-site setups
6. **Budget Alerts**: Slack/Teams integration
7. **Chargebacks**: Department-level cost allocation
8. **API Quotas**: Rate limiting for external API consumers

---

## 14. Success Metrics

| Metric | Target | Measurement |
|--------|--------|-------------|
| Rate limit overhead | <10ms per request | P95 latency |
| Quota check overhead | <5ms per request | P95 latency |
| Cache hit rate (usage) | >80% | Cache stats |
| Cost calculation accuracy | ±1% | Audit vs provider bills |
| False positive rate limits | <0.1% | User complaints |
| Quota notification delivery | >99% | Email logs |
| Query performance | <100ms | Slow query log |
| Storage efficiency | <50MB per 10k requests | Database size |

---

## Document Cross-References

- `00-implementation-roadmap.md`: Overall project timeline
- `01-ai-base-architecture.md`: Core architecture design
- Provider implementations: Integration points for rate limiting
- Backend module: UI for quota management
