# Contexts Extension AI/LLM Integration Analysis

> Extension: netresearch/t3x-contexts
> Repository: https://github.com/netresearch/t3x-contexts
> Analysis Date: 2025-12-22

---

## 1. Extension Overview

The **contexts extension** enables rule-based, multi-channel content visibility in TYPO3 CMS. It allows administrators to show/hide pages and content elements based on configurable matching conditions (contexts).

---

## 2. Current Rule Configuration Types

| Type | Rule Logic | Configuration | Notes |
|------|-----------|----------------|-------|
| **Domain** | Match HTTP_HOST | Multi-line domain list with optional wildcard prefix | `.example.org` for subdomains |
| **GET Parameter** | Match URL query parameters | Parameter name + optional values | Session persistence available |
| **IP Address** | Match client IP | CIDR notation, wildcards, IPv4 & IPv6 | X-Forwarded-For support |
| **HTTP Header** | Match request headers | Header name + optional values | User-Agent, Accept-Language, etc. |
| **Session Variable** | Match user session data | Variable name checking | Presence-based only |
| **Logical Combination** | Boolean expression evaluation | `&&`, `\|\|`, `!`, `><` (XOR), parentheses | Sophisticated expression parser |

---

## 3. Architecture Components

### Core Classes

| Class | Purpose |
|-------|---------|
| `Container` | Singleton context loader with dependency resolution |
| `AbstractContext` | Base class for all context types |
| `Factory` | Creates context instances from database records |
| `ContextMatcher` | Public API for context matching (with caching) |
| `LogicalExpressionEvaluator` | Full recursive expression parser with precedence |

### Integration Points

- **Fluid ViewHelper**: `{contexts:matches(alias:'mobile')}`
- **TypoScript Conditions**: `[contextMatch("mobile")]`
- **PHP API**: `ContextMatcher::getInstance()->matches('contextAlias')`
- **Expression Language**: TYPO3 v12+ expression language support

### Storage Tables

- `tx_contexts_contexts`: Context definitions
- `tx_contexts_settings`: Context-to-record visibility mappings

---

## 4. Current Limitations

### Manual, Repetitive Configuration
- No templates or wizards for common patterns
- No validation assistance (admins must know CIDR notation, regex)
- Domain configuration fragility (wildcard logic is implicit)
- No rule suggestions based on existing contexts

### Limited Matching Capabilities
- IP matching: CIDR/wildcard only, no geolocation
- Header matching: Literal string-based, no regex support
- GET parameter: Literal values only, no pattern matching
- No content-aware rules

### Configuration Discovery Gap
- No analytics on context usage or effectiveness
- No audit trail of rule creation/modification
- No conflict detection between rules

---

## 5. AI/LLM Enhancement Opportunities

### 5.1 Natural Language Rule Generation

**User Input Example:**
```
"Show this content only to users from Germany or France"
```

**AI Processing:**
1. Parse intent: Geographic targeting + multiple countries
2. Check available context types
3. Recommend approach: HTTP header "Accept-Language" OR geolocation
4. Generate rule configuration
5. Suggest refinements

**Implementation Files:**
- `Classes/Service/AiRuleGeneratorService.php`
- `Classes/Form/AiAssistantFormElement.php`
- REST endpoint: `/api/contexts/generate-rule`

---

### 5.2 Content-Based Context Suggestions

**Scenario:** Admin editing page about "Mobile Applications"

**AI Analysis:**
1. Extract content keywords: mobile, app, iOS, Android
2. Query existing contexts: Find "mobile_device", "tablet"
3. Analyze page structure: Single column, optimized images
4. Suggest contexts with confidence scores

**Implementation Files:**
- `Classes/Service/ContentAnalysisService.php`
- `Classes/Service/ContextRelevanceScorerService.php`
- `Classes/Service/VectorEmbeddingService.php`

---

### 5.3 Behavioral Pattern Detection

**Historical Data Analysis:**
```
Session patterns:
- IP=80.76.201.32 + User-Agent=iPhone + GET affID=partner_a
- Repeated 3+ times
```

**AI Suggestion:**
- Create combination context: `ip_range && affID=partner_a`
- Confidence: 98%
- Recommendation: "Create dedicated context for iOS Partner A users"

**Implementation Files:**
- `Classes/Service/SessionTrackingService.php`
- `Classes/Service/PatternDetectionService.php`
- `Classes/Backend/Module/ContextInsightsController.php`

---

### 5.4 Rule Validation & Conflict Detection

**Example Validation:**
```
Admin creates: "Show to IP=192.168.1.* AND NOT IP=192.168.1.100"

AI Validation Results:
⚠️ LOGICAL ERROR: Contradictory IP match rules
✓ SYNTAX: Valid expression syntax
⚠️ COVERAGE: Rule matches <0.1% of known traffic
⚠️ CONSISTENCY: Conflicts with 2 other contexts
```

**Implementation Files:**
- `Classes/Service/RuleValidationService.php`
- `Classes/Service/ConflictDetectionService.php`
- `Classes/Validator/ContextRuleValidator.php`

---

### 5.5 Auto-Generated Documentation

**Context Rule:**
```
Type: Logical Combination
Expression: (mobile || tablet) && !admin_ip && lang=en
```

**AI-Generated Documentation:**
```
"This context matches users who are:
• Accessing from a mobile device OR tablet device
• AND are NOT connecting from the admin office IP range
• AND have English language preference

Typical Use Case: Display mobile-optimized layout for international users
Performance Impact: ~12% of traffic matches this context
Related Contexts: 'desktop', 'admin_access', 'de_language'"
```

**Implementation Files:**
- `Classes/Service/DocumentationGeneratorService.php`
- `Classes/Backend/Module/ContextDocumentationModule.php`

---

## 6. Implementation Roadmap

### Phase 1: Foundation (Weeks 1-2)
- Create `Classes/Service/LlmIntegrationService.php` (abstraction layer)
- Support OpenAI, Anthropic, open-source models
- Configuration in `ext_conf_template.txt`

### Phase 2: Rule Generation (Weeks 3-4)
- Natural language rule generator
- Backend form element for rule generation dialog
- Context type recommender
- Prompt engineering for context extraction

### Phase 3: Content Analysis (Weeks 5-6)
- Smart context suggestions
- Vector-based context similarity scorer
- Bulk suggestion application

### Phase 4: Validation (Week 7)
- Rule validation engine
- Conflict detection
- Real-time validation in form elements

### Phase 5: Analytics & Documentation (Week 8)
- Session tracking (privacy-first)
- Pattern detection engine
- Auto-documentation generator

---

## 7. Extension Points & Hooks

### Context Type Registration

```php
// In ext_localconf.php
\Netresearch\Contexts\Api\Configuration::registerContextType(
    key: 'ai_generated',
    title: 'AI-Generated Context',
    class: \Netresearch\AiContexts\Context\Type\AiGeneratedContext::class,
    flexFile: 'EXT:ai_contexts/Configuration/FlexForms/ContextType/AiGenerated.xml'
);
```

### Custom Context Type

```php
class AiGeneratedContext extends AbstractContext {
    public function match(array $arDependencies = []): bool {
        $aiRuleConfig = $this->getConfValue('ai_rule_definition');
        // ... evaluate based on AI-generated rules
    }
}
```

---

## 8. New Database Tables

```sql
-- AI suggestions for contexts
CREATE TABLE tx_contexts_ai_suggestions (
    uid INT PRIMARY KEY,
    pid INT,
    tstamp INT,
    crdate INT,
    context_uid INT,
    suggestion_text TEXT,
    confidence FLOAT,
    suggestion_type VARCHAR(50),
    dismissed TINYINT,
    created_rule_uid INT,
    notes TEXT
);

-- AI operation logs
CREATE TABLE tx_contexts_ai_logs (
    uid INT PRIMARY KEY,
    tstamp INT,
    feature_type VARCHAR(50),
    input_text TEXT,
    ai_response LONGTEXT,
    user_uid INT,
    status VARCHAR(20)
);
```

---

## 9. REST API Endpoints

```
POST /api/contexts/ai/generate-rule
  Input: { "description": "Show to mobile users from France" }
  Output: { "contextType": "combination", "rule": {...}, "confidence": 0.92 }

POST /api/contexts/ai/suggest-contexts
  Input: { "pageUid": 42, "contentAnalysis": "..." }
  Output: { "suggestions": [...], "confidence_scores": {...} }

POST /api/contexts/ai/validate-rule
  Input: { "expression": "(mobile && !mobile)" }
  Output: { "valid": false, "errors": [...], "suggestions": [...] }

POST /api/contexts/ai/generate-docs
  Input: { "contextUid": 5 }
  Output: { "documentation": "..." }
```

---

## 10. User Experience Improvements

### Current Workflow (Manual)
1. Create domain context for .ca, .us → Complex, error-prone
2. Create user-agent context for desktop → Requires manual browser list
3. Create logical combination → Manually write expression
4. Test and debug → Iterate until working
5. **Time: 10-15 minutes**

### With AI Enhancement
1. Click "Ask AI to create this rule"
2. Type: "Show product carousel only on desktop in US and Canada"
3. AI generates contexts automatically
4. Review and approve preview
5. One-click creation
6. **Time: < 2 minutes**

---

## 11. Success Metrics

| Metric | Current | Target |
|--------|---------|--------|
| Time to configure contexts | 10-15 min | < 2 min |
| Configuration accuracy | ~85% | > 98% |
| Rule reusability | Low | High (template library) |
| Admin satisfaction | Variable | Measurable improvement |

---

## 12. Security & Privacy

### Security
- API Key Management via TYPO3 secure storage
- Prompt Injection Prevention: Sanitize inputs
- Rate Limiting to prevent abuse
- Audit Logging for compliance

### Privacy
- No PII in LLM requests
- On-premises option (Ollama, LLaMA)
- GDPR Compliance: Encrypted storage, deletion on request
- Analytics: Aggregate-only data collection
