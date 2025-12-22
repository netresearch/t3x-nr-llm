# Security Architecture - nr-llm Extension

## Overview

Security-first architecture for TYPO3 extension handling sensitive LLM integration data.

## Threat Model

### Assets
- API keys (OpenAI, Anthropic, local LLM endpoints)
- User prompts (may contain sensitive business data)
- LLM responses (generated content)
- Configuration data (system prompts, model settings)

### Threats
1. **Unauthorized API key access** (SEV: CRITICAL)
   - Direct database access
   - Memory dumps
   - Log file exposure
   - Backend user privilege escalation

2. **Prompt injection attacks** (SEV: HIGH)
   - Malicious system prompt override
   - Context manipulation
   - Instruction leakage

3. **Data exfiltration** (SEV: HIGH)
   - Sensitive data in prompts sent to external LLMs
   - PII exposure through audit logs
   - API response caching

4. **Cross-site scripting** (SEV: MEDIUM)
   - Unescaped LLM output rendered in backend/frontend
   - Malicious code injection via generated content

5. **Authorization bypass** (SEV: HIGH)
   - Permission escalation
   - Cross-site access in multi-site setup
   - API key sharing between unauthorized sites

## Security Layers

### Layer 1: Encryption at Rest
- AES-256-GCM for API keys
- Encryption key derivation from TYPO3 encryptionKey + pepper
- Per-site encryption context
- Authenticated encryption (prevents tampering)

### Layer 2: Access Control
- TYPO3 BE user permissions integration
- Role-based access control (RBAC)
- Site-based isolation
- Principle of least privilege

### Layer 3: Audit & Monitoring
- Comprehensive event logging
- Anomaly detection hooks
- Audit trail immutability
- GDPR-compliant data retention

### Layer 4: Input/Output Sanitization
- Prompt injection prevention
- XSS protection
- Content filtering
- PII detection (optional)

## Compliance Considerations

### GDPR
- Right to access: Audit logs show LLM usage per user
- Right to erasure: Purge user prompt history
- Data minimization: Log metadata, not full prompts
- Purpose limitation: Clear consent for LLM processing
- Data transfer: Document third-party LLM processor agreements

### OWASP Top 10 (2021)
- A01 Broken Access Control → AccessControl component
- A02 Cryptographic Failures → ApiKeyManager encryption
- A03 Injection → Input sanitization
- A04 Insecure Design → Security-first architecture
- A05 Security Misconfiguration → Secure defaults
- A07 Identification/Authentication Failures → TYPO3 integration
- A09 Security Logging Failures → AuditLogger
- A10 SSRF → LLM endpoint validation

### LLM-Specific (OWASP LLM Top 10)
- LLM01 Prompt Injection → InputSanitizer filters
- LLM02 Insecure Output Handling → OutputSanitizer
- LLM03 Training Data Poisoning → N/A (external LLMs)
- LLM06 Sensitive Information Disclosure → PII detection
- LLM08 Excessive Agency → Rate limiting, quota enforcement

## Penetration Test Checklist

### Authentication & Authorization
- [ ] Attempt API key retrieval without proper permissions
- [ ] Cross-site API key access in multi-site setup
- [ ] Permission escalation via user group manipulation
- [ ] Session hijacking to access LLM features

### Cryptography
- [ ] Extract encryption key from code/config
- [ ] Brute force encrypted API keys
- [ ] Replay attack with captured encrypted data
- [ ] Key rotation failure scenarios

### Injection Attacks
- [ ] System prompt override via user input
- [ ] SQL injection in audit log queries
- [ ] XSS via LLM-generated content
- [ ] Command injection through model parameters

### Data Exposure
- [ ] API keys in error messages/logs
- [ ] Sensitive prompts in database/cache
- [ ] PII leakage through audit trails
- [ ] Memory dumps containing decrypted keys

### Rate Limiting & DoS
- [ ] Quota bypass through parallel requests
- [ ] Resource exhaustion via large prompts
- [ ] Cache poisoning attacks

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────┐
│                    TYPO3 Backend                        │
└─────────────────────┬───────────────────────────────────┘
                      │
                      ▼
         ┌────────────────────────┐
         │   AccessControl        │◄─── TYPO3 BE Users
         │   - Permission Check   │     User Groups
         │   - Site Isolation     │
         └───────────┬────────────┘
                     │ Authorized
                     ▼
         ┌────────────────────────┐
         │   InputSanitizer       │
         │   - Prompt Injection   │
         │   - PII Detection      │
         └───────────┬────────────┘
                     │ Sanitized
                     ▼
         ┌────────────────────────┐
         │   ApiKeyManager        │
         │   - Decrypt API Key    │◄─── Encrypted Storage
         │   - Retrieve Key       │     (tx_nrllm_apikeys)
         └───────────┬────────────┘
                     │ Decrypted Key
                     ▼
         ┌────────────────────────┐
         │   LLM Service Layer    │
         │   - API Request        │────► External LLM
         └───────────┬────────────┘     (OpenAI/Anthropic)
                     │ Response
                     ▼
         ┌────────────────────────┐
         │   OutputSanitizer      │
         │   - XSS Prevention     │
         │   - Content Validation │
         └───────────┬────────────┘
                     │ Safe Output
                     ▼
         ┌────────────────────────┐
         │   AuditLogger          │
         │   - Event Recording    │────► Audit Trail
         │   - Anomaly Detection  │     (tx_nrllm_audit)
         └────────────────────────┘
```

## Implementation Priority

1. **Phase 1: Critical Security** (Week 1)
   - ApiKeyManager with AES-256-GCM
   - AccessControl with TYPO3 permissions
   - Basic AuditLogger

2. **Phase 2: Attack Prevention** (Week 2)
   - InputSanitizer (prompt injection)
   - OutputSanitizer (XSS)
   - Rate limiting

3. **Phase 3: Compliance** (Week 3)
   - PII detection
   - GDPR features
   - Advanced audit analytics

## Configuration Security

### Secure Defaults
```php
// Configuration/TCA/Overrides/sys_template.php
'settings.security' => [
    'encryption' => [
        'algorithm' => 'aes-256-gcm',
        'keyDerivation' => 'pbkdf2',
        'iterations' => 100000,
    ],
    'audit' => [
        'logLevel' => 'info',
        'retentionDays' => 90,
        'anonymizeAfterDays' => 30,
    ],
    'sanitization' => [
        'enablePromptInjectionFilter' => true,
        'enablePiiDetection' => false,
        'maxPromptLength' => 50000,
    ],
    'rateLimit' => [
        'requestsPerHour' => 100,
        'requestsPerDay' => 1000,
    ],
]
```

## Key Management Strategy

### Development Environment
- Use TYPO3 encryptionKey directly
- Store test API keys encrypted
- Separate test LLM account

### Production Environment
- TYPO3 encryptionKey (min 96 chars)
- Additional pepper in LocalConfiguration
- Key rotation every 90 days
- HSM integration (optional)

### Multi-Site Setup
- Per-site encryption context (site identifier)
- Prevents cross-site key access
- Centralized key rotation capability
