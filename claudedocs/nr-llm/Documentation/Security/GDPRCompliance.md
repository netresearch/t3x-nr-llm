# GDPR Compliance Guide - nr-llm Extension

## Overview

This document outlines GDPR (General Data Protection Regulation) compliance measures for the nr-llm TYPO3 extension.

## Legal Basis for Processing

### Identification of Personal Data

The nr-llm extension may process the following personal data:

1. **Backend User Data**
   - User ID
   - Username
   - IP addresses (in audit logs)
   - Browser user agent strings

2. **Content Data**
   - User-generated prompts (NOT stored, only processed)
   - LLM-generated responses (may contain personal data)
   - Content metadata (timestamp, length, token counts)

3. **Audit Data**
   - Activity logs (who accessed what, when)
   - Usage statistics (per user/site)
   - Error logs

### Legal Basis

Processing is based on:

1. **Legitimate Interest (Article 6(1)(f) GDPR)**
   - Security monitoring and audit logging
   - System performance optimization
   - Fraud prevention

2. **Contractual Necessity (Article 6(1)(b) GDPR)**
   - Providing LLM functionality as part of CMS service
   - Usage tracking for billing/quota management

3. **Legal Obligation (Article 6(1)(c) GDPR)**
   - Security incident logging
   - Compliance with ePrivacy regulations

## GDPR Principles Compliance

### 1. Lawfulness, Fairness, Transparency

**Implementation:**
- Clear documentation of data processing
- User consent for LLM processing (where required)
- Privacy notice in extension documentation
- Transparent audit logging

**Code Example:**
```php
// Privacy notice shown before first LLM use
public function showPrivacyNotice(): void
{
    $notice = "
        This system uses external LLM providers to process your content.
        Your prompts will be sent to [Provider Name] for processing.
        Please review our privacy policy for details on data handling.

        By continuing, you consent to this processing.
    ";

    // Display notice and require acknowledgment
}
```

### 2. Purpose Limitation

**Implementation:**
- Data used only for stated purposes
- No repurposing without consent
- Clear separation of data types

**Measures:**
```php
// Audit logs used ONLY for security monitoring
// NOT for:
// - Performance reviews of users
// - Marketing analysis
// - Third-party sales

// Code enforcement
class AuditLogger
{
    // Purpose clearly stated in method documentation
    /**
     * Log LLM request for SECURITY MONITORING only
     * Data NOT used for user profiling or marketing
     */
    public function logLlmRequest(...) { }
}
```

### 3. Data Minimization

**Implementation:**
- Prompt content NOT stored (only metadata)
- IP addresses anonymized after 30 days
- Minimal user data in logs

**Code Example:**
```php
// DON'T store full prompt
$auditLogger->logLlmRequest(
    'openai',
    'gpt-4',
    1500, // Token count only
    [
        'prompt_length' => strlen($prompt), // Length, not content
        'content_type' => 'page_content',   // Category, not data
    ]
);

// Full prompt is NOT logged
// ❌ BAD: 'prompt' => $userPrompt
```

### 4. Accuracy

**Implementation:**
- Users can view their own audit data
- Correction mechanisms for user data
- Regular data validation

**Code Example:**
```php
// User can view and verify their data
public function getUserData(int $userId): array
{
    return [
        'audit_logs' => $this->auditLogger->getAuditLog(['user_id' => $userId]),
        'usage_stats' => $this->usageTracker->getUserUsage($userId),
        'permissions' => $this->accessControl->getUserPermissions($userId),
    ];
}
```

### 5. Storage Limitation

**Implementation:**
- Configurable retention periods
- Automatic deletion after retention
- Anonymization for long-term storage

**Configuration:**
```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm']['security']['audit'] = [
    'retentionDays' => 90,        // Delete after 90 days
    'anonymizeAfterDays' => 30,   // Anonymize after 30 days
];
```

**Scheduled Cleanup:**
```php
// Scheduler task: Daily cleanup
class AuditCleanupTask extends AbstractTask
{
    public function execute(): bool
    {
        $auditLogger = GeneralUtility::makeInstance(AuditLogger::class);

        // Anonymize old logs (30+ days)
        $anonymized = $auditLogger->anonymizeOldLogs();

        // Delete very old logs (90+ days)
        $deleted = $auditLogger->cleanupOldLogs();

        return true;
    }
}
```

### 6. Integrity and Confidentiality

**Implementation:**
- AES-256-GCM encryption for API keys
- Access controls and permissions
- Audit logging of all access
- HTTPS required for LLM requests

**Security Measures:**
```php
// Encryption at rest
$apiKeyManager->store('openai', $key); // AES-256-GCM

// Access control
$accessControl->requirePermission(AccessControl::PERMISSION_MANAGE_KEYS);

// Audit trail
$auditLogger->logKeyAccess($provider, $scope, $keyUid);

// HTTPS enforcement
if (!GeneralUtility::getIndpEnv('TYPO3_SSL')) {
    throw new \RuntimeException('HTTPS required for LLM requests');
}
```

## Data Subject Rights Implementation

### 1. Right to Access (Article 15)

**Implementation:**
```php
class DataSubjectAccessController
{
    /**
     * Export all personal data for a user (GDPR Article 15)
     */
    public function exportUserData(int $userId): array
    {
        $auditLogger = GeneralUtility::makeInstance(AuditLogger::class);
        $usageTracker = GeneralUtility::makeInstance(UsageTracker::class);

        return [
            'user_id' => $userId,
            'export_date' => date('Y-m-d H:i:s'),

            // Audit logs
            'audit_logs' => $auditLogger->getAuditLog([
                'user_id' => $userId,
            ]),

            // Usage statistics
            'usage_statistics' => $usageTracker->getUserUsage($userId),

            // Permissions
            'permissions' => [
                'use_llm' => $this->accessControl->hasPermission('use_llm'),
                'configure_prompts' => $this->accessControl->hasPermission('configure_prompts'),
                'manage_keys' => $this->accessControl->hasPermission('manage_keys'),
                'view_reports' => $this->accessControl->hasPermission('view_reports'),
            ],

            // Quota information
            'quota' => $this->getQuotaInfo($userId),
        ];
    }

    /**
     * Export to JSON for user download
     */
    public function exportToJson(int $userId): string
    {
        $data = $this->exportUserData($userId);
        return json_encode($data, JSON_PRETTY_PRINT);
    }
}
```

**Backend Module:**
```php
// Backend module: "LLM > My Data"
// Allows users to download their data as JSON
```

### 2. Right to Rectification (Article 16)

**Implementation:**
```php
// Users can update their preferences
public function updateUserPreferences(int $userId, array $preferences): void
{
    // Update user settings
    $connection = GeneralUtility::makeInstance(ConnectionPool::class)
        ->getConnectionForTable('be_users');

    $connection->update(
        'be_users',
        ['tx_nrllm_preferences' => json_encode($preferences)],
        ['uid' => $userId]
    );

    // Log the change
    $this->auditLogger->logConfigChange(
        "user_{$userId}_preferences",
        $oldPreferences,
        $preferences
    );
}
```

### 3. Right to Erasure (Article 17)

**Implementation:**
```php
class DataErasureService
{
    /**
     * Delete all personal data for a user (GDPR Article 17)
     */
    public function deleteUserData(int $userId, bool $fullDeletion = true): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class);

        if ($fullDeletion) {
            // Full deletion (user account deleted)

            // 1. Delete audit logs
            $connection->getConnectionForTable('tx_nrllm_audit')
                ->delete('tx_nrllm_audit', ['user_id' => $userId]);

            // 2. Delete usage data
            $connection->getConnectionForTable('tx_nrllm_usage')
                ->delete('tx_nrllm_usage', ['user_id' => $userId]);

            // 3. Delete user-specific API keys
            $connection->getConnectionForTable('tx_nrllm_apikeys')
                ->delete('tx_nrllm_apikeys', [
                    'scope' => 'user_' . $userId
                ]);

        } else {
            // Anonymization (user wants data removed but account kept)

            // 1. Anonymize audit logs
            $connection->getConnectionForTable('tx_nrllm_audit')
                ->update(
                    'tx_nrllm_audit',
                    [
                        'user_id' => 0,
                        'username' => '',
                        'ip_address' => '',
                        'user_agent' => '',
                        'anonymized' => 1,
                    ],
                    ['user_id' => $userId]
                );

            // 2. Anonymize usage data
            $connection->getConnectionForTable('tx_nrllm_usage')
                ->update(
                    'tx_nrllm_usage',
                    ['user_id' => 0],
                    ['user_id' => $userId]
                );
        }

        // Log erasure action
        $this->auditLogger->logDataErasure($userId, $fullDeletion);
    }
}
```

**TYPO3 Integration:**
```php
// Hook into TYPO3 user deletion
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][]
    = DataErasureHook::class;

class DataErasureHook
{
    public function processCmdmap_postProcess($command, $table, $id, $value, $tce)
    {
        if ($table === 'be_users' && $command === 'delete') {
            // User being deleted, erase their LLM data
            $erasureService = GeneralUtility::makeInstance(DataErasureService::class);
            $erasureService->deleteUserData((int)$id, true);
        }
    }
}
```

### 4. Right to Restriction of Processing (Article 18)

**Implementation:**
```php
// Add "restrict_llm_processing" flag to users
public function restrictProcessing(int $userId, bool $restrict = true): void
{
    $connection = GeneralUtility::makeInstance(ConnectionPool::class)
        ->getConnectionForTable('be_users');

    $connection->update(
        'be_users',
        ['tx_nrllm_restrict_processing' => $restrict ? 1 : 0],
        ['uid' => $userId]
    );

    // Enforce in LLM service
    if ($restrict) {
        // Block LLM requests for this user
        // But keep data for potential legal claims
    }
}

// Check before processing
public function canProcess(int $userId): bool
{
    $user = BackendUtility::getRecord('be_users', $userId);
    return empty($user['tx_nrllm_restrict_processing']);
}
```

### 5. Right to Data Portability (Article 20)

**Implementation:**
```php
class DataPortabilityService
{
    /**
     * Export data in machine-readable format (JSON)
     */
    public function exportForPortability(int $userId): array
    {
        return [
            'format' => 'JSON',
            'version' => '1.0',
            'generated_at' => date('c'), // ISO 8601

            // User data
            'user' => [
                'id' => $userId,
                'permissions' => $this->getUserPermissions($userId),
            ],

            // Activity data
            'activity' => [
                'audit_logs' => $this->getAuditLogs($userId),
                'usage_statistics' => $this->getUsageStats($userId),
            ],

            // Settings
            'settings' => $this->getUserSettings($userId),
        ];
    }

    /**
     * Import data from another system (if applicable)
     */
    public function importData(int $userId, array $data): void
    {
        // Validate format
        if ($data['format'] !== 'JSON' || $data['version'] !== '1.0') {
            throw new \InvalidArgumentException('Invalid data format');
        }

        // Import settings
        if (isset($data['settings'])) {
            $this->importUserSettings($userId, $data['settings']);
        }

        // Log import
        $this->auditLogger->logDataImport($userId);
    }
}
```

### 6. Right to Object (Article 21)

**Implementation:**
```php
// User can object to LLM processing
public function objectToProcessing(int $userId, string $reason): void
{
    // Log objection
    $this->auditLogger->logProcessingObjection($userId, $reason);

    // Disable LLM for this user
    $this->restrictProcessing($userId, true);

    // Notify administrators
    $this->notifyAdministrators("User {$userId} objected to LLM processing: {$reason}");

    // Manual review required for legitimate interest assessment
}
```

## Third-Party Data Processors

### LLM Provider Data Processing Agreements

When using external LLM providers (OpenAI, Anthropic, etc.), ensure:

1. **Data Processing Agreement (DPA)**
   - Signed DPA with each provider
   - GDPR compliance clauses
   - Sub-processor authorization

2. **Standard Contractual Clauses (SCC)**
   - For non-EU providers
   - Supplementary measures for data transfers

3. **Provider Selection**
```php
// Document provider compliance
class LlmProviderRegistry
{
    private const PROVIDERS = [
        'openai' => [
            'name' => 'OpenAI',
            'dpa_status' => 'signed',
            'dpa_url' => 'https://openai.com/policies/dpa',
            'scc_applicable' => true, // US company
            'certifications' => ['SOC2', 'ISO27001'],
            'data_location' => 'US',
        ],
        'anthropic' => [
            'name' => 'Anthropic',
            'dpa_status' => 'signed',
            'dpa_url' => 'https://anthropic.com/dpa',
            'scc_applicable' => true,
            'certifications' => ['SOC2'],
            'data_location' => 'US',
        ],
        // Add more providers
    ];

    public function getProviderCompliance(string $provider): array
    {
        return self::PROVIDERS[$provider] ?? null;
    }
}
```

### Data Transfer Considerations

**Prompt Content Sent to External Providers:**
- User consent required (explicit or implied)
- Purpose clearly stated
- Provider compliance verified

**Implementation:**
```php
// Consent check before external processing
public function sendToLlm(string $prompt, string $provider): void
{
    // Check if user has consented to external processing
    if (!$this->hasUserConsent($userId, $provider)) {
        throw new \RuntimeException(
            "User has not consented to data processing by {$provider}"
        );
    }

    // Verify provider compliance
    $compliance = $this->providerRegistry->getProviderCompliance($provider);
    if ($compliance['dpa_status'] !== 'signed') {
        throw new \RuntimeException(
            "No DPA in place with {$provider}"
        );
    }

    // Proceed with request
    // ...
}
```

## Privacy by Design and Default

### Privacy-Enhancing Features

1. **Minimal Data Collection**
```php
// Only collect what's necessary
$auditLogger->logLlmRequest(
    $provider,
    $model,
    $tokenCount,  // ✅ Necessary for quota
    // ❌ NOT logged: $fullPrompt (unnecessary)
);
```

2. **Automatic Anonymization**
```php
// Scheduler task runs daily
$auditLogger->anonymizeOldLogs(); // After 30 days
```

3. **Encryption by Default**
```php
// All API keys encrypted automatically
$apiKeyManager->store($provider, $key); // AES-256-GCM
```

4. **Access Controls**
```php
// Permissions enforced at every access point
$accessControl->requirePermission('manage_keys');
```

### Privacy Impact Assessment (PIA)

**When Required:**
- High-risk processing (large-scale, sensitive data)
- Systematic monitoring
- Automated decision-making

**Assessment Template:**
```markdown
# Privacy Impact Assessment - nr-llm Extension

## Processing Description
- Purpose: LLM content generation in CMS
- Data types: Prompts, responses, audit logs
- Data subjects: Backend users
- Recipients: External LLM providers

## Necessity and Proportionality
- Necessity: Required for LLM functionality
- Proportionality: Minimal data collected
- Alternatives considered: On-premise LLMs (higher cost)

## Risks Identified
1. Data transfer to non-EU providers
   - Mitigation: DPA + SCC in place
2. Potential sensitive data in prompts
   - Mitigation: PII detection (optional), user training
3. Audit log data retention
   - Mitigation: Automatic anonymization after 30 days

## Safeguards
- Encryption at rest (AES-256-GCM)
- Access controls (RBAC)
- Audit logging
- Data minimization (no full prompts logged)
- Automatic anonymization

## Conclusion
Processing is compliant with GDPR with appropriate safeguards.
```

## User Documentation

### Privacy Notice Template

```markdown
# Privacy Notice - LLM Content Generation

## What data we process
When you use the LLM features, we process:
- Your content prompts (sent to external LLM provider)
- Usage metadata (timestamp, token count, model used)
- Audit logs (who accessed what, when)

## Why we process this data
- To provide LLM content generation functionality
- To enforce usage quotas and prevent abuse
- To monitor security and system performance

## Who has access to your data
- System administrators (for security monitoring)
- External LLM providers (OpenAI, Anthropic) to process your prompts

## How long we keep your data
- Prompts: Not stored (processed in real-time only)
- Usage metadata: 90 days, then deleted
- Audit logs: Anonymized after 30 days, deleted after 90 days

## Your rights
You have the right to:
- Access your data (download as JSON)
- Request deletion of your data
- Object to processing
- Restrict processing
- Data portability

Contact: privacy@example.com

## Data transfers
Your prompts are sent to external LLM providers located outside the EU.
We have Data Processing Agreements and Standard Contractual Clauses in place.
```

### Consent Mechanism

```php
// Show consent dialog on first LLM use
class ConsentManager
{
    public function requireConsent(int $userId, string $provider): void
    {
        if ($this->hasConsent($userId, $provider)) {
            return;
        }

        // Show consent dialog in backend
        $notice = "
            By using this LLM feature, your content will be sent to {$provider}
            for processing. {$provider} is located outside the EU.

            We have a Data Processing Agreement in place to protect your data.

            [ ] I understand and consent to this processing

            [Cancel] [Accept]
        ";

        // Store consent
        if ($userAccepts) {
            $this->storeConsent($userId, $provider);
        }
    }

    private function storeConsent(int $userId, string $provider): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrllm_consents');

        $connection->insert('tx_nrllm_consents', [
            'user_id' => $userId,
            'provider' => $provider,
            'consent_date' => time(),
            'ip_address' => GeneralUtility::getIndpEnv('REMOTE_ADDR'),
            'consent_text' => $notice,
        ]);
    }
}
```

## Compliance Checklist

### Implementation Checklist
- [x] Lawful basis documented
- [x] Data minimization (no full prompts logged)
- [x] Storage limitation (retention policies)
- [x] Encryption at rest (AES-256-GCM)
- [x] Access controls (RBAC)
- [x] Audit logging
- [x] Automatic anonymization
- [x] User data export (Article 15)
- [x] User data deletion (Article 17)
- [ ] Consent mechanism (if required)
- [ ] Privacy notice displayed
- [ ] DPA with LLM providers
- [ ] Privacy Impact Assessment (if required)

### Operational Checklist
- [ ] Scheduled cleanup tasks configured
- [ ] Audit log retention verified
- [ ] Data export functionality tested
- [ ] Deletion procedures tested
- [ ] Staff trained on GDPR procedures
- [ ] Data breach response plan in place
- [ ] Regular compliance reviews scheduled

## Data Breach Response

### Detection
```php
// Monitor for suspicious activity
$suspiciousEvents = $auditLogger->getAuditLog([
    'event_type' => AuditLogger::EVENT_SUSPICIOUS_ACTIVITY,
    'severity' => AuditLogger::SEVERITY_CRITICAL,
]);

if (count($suspiciousEvents) > threshold) {
    $this->triggerBreachInvestigation();
}
```

### Response Procedure

1. **Immediate Actions (within 24 hours)**
   - Identify scope of breach
   - Contain the breach
   - Document everything

2. **Assessment (within 72 hours)**
   - Determine if personal data was compromised
   - Assess risk to data subjects
   - Decide if notification required

3. **Notification (within 72 hours of detection)**
   - Supervisory authority (if high risk)
   - Affected data subjects (if high risk)
   - Document decision process

4. **Post-Incident**
   - Root cause analysis
   - Implement preventive measures
   - Update procedures

### Breach Log Template
```php
class BreachLog
{
    private array $breach = [
        'incident_id' => '',
        'detected_at' => '',
        'detected_by' => '',
        'type' => '', // unauthorized access, data leak, etc.
        'scope' => [
            'affected_users' => 0,
            'data_types' => [],
        ],
        'risk_assessment' => '', // low, medium, high
        'notification_required' => false,
        'actions_taken' => [],
        'lessons_learned' => '',
    ];
}
```

## Summary

The nr-llm extension implements GDPR compliance through:

1. **Technical Measures**
   - Encryption (AES-256-GCM)
   - Access controls (RBAC)
   - Audit logging
   - Automatic anonymization

2. **Organizational Measures**
   - Privacy by design
   - Data minimization
   - Retention policies
   - User rights implementation

3. **Documentation**
   - Privacy notices
   - DPAs with processors
   - Consent records
   - Breach response procedures

4. **User Rights**
   - Access (data export)
   - Rectification
   - Erasure (deletion/anonymization)
   - Restriction
   - Portability
   - Objection

For questions or concerns, contact your Data Protection Officer.
