# TextDB Extension AI/LLM Integration Analysis

> Extension: netresearch/nr-textdb
> Package: netresearch/nr-textdb
> Analysis Date: 2025-12-22

---

## 1. Extension Overview

**TextDB** is a database-backed translation management system for TYPO3 that centralizes all frontend system strings (form labels, buttons, UI messages) in a backend database module instead of scattered XLIFF files.

### Core Functionality
- Database-driven translation storage (10+ languages)
- Backend module for editors/translators
- ViewHelper integration (`textdb:translate`, `textdb:textdb`)
- Automatic XLIFF import on first render
- Bulk operations and inline editing
- Change tracking for audit trails
- Migration CLI (`textdb:translate`)

### Technical Requirements
- PHP 8.2+
- TYPO3 13.4+
- Latest version: v3.0.3 (2025-11-27)

---

## 2. Current Limitations

### Manual Work
- All translations must be manually entered
- No machine translation integration
- No translation suggestions
- No quality assurance tools

### Missing Features
- No translation memory
- No terminology consistency checking
- No automated workflow/approval
- No collaborative features

---

## 3. AI Enhancement Opportunities

### 3.1 Quick-Translate (One-Click Bulk Translation)

**Impact**: Very High | **Effort**: 3-4 weeks | **Priority**: P0

**Implementation**:
```php
// Services/TranslationGenerationService.php
class TranslationGenerationService {
    public function generateMissingTranslations(
        string $targetLanguage,
        ?string $component = null,
        ?string $glossaryKey = null
    ): BatchResult {
        // 1. Find untranslated strings
        // 2. Batch generate via LLM API
        // 3. Apply glossary rules
        // 4. Return with confidence scores
    }
}
```

**Integration Points**:
- Backend module "Generate Missing Translations" button
- CLI command: `textdb:generate-translations --language=de`
- Scheduler task for periodic generation

---

### 3.2 Translation Suggestions (Real-Time Autocomplete)

**Impact**: High | **Effort**: 2-3 weeks | **Priority**: P0

**Implementation**:
```php
// API endpoint for AJAX suggestions
// POST /api/textdb/suggest-translation
{
    "sourceText": "Save",
    "sourceLanguage": "en",
    "targetLanguage": "de",
    "component": "checkout",
    "type": "button"
}

// Response
{
    "suggestions": [
        {"text": "Speichern", "confidence": 0.95, "source": "glossary"},
        {"text": "Sichern", "confidence": 0.87, "source": "ai"},
        {"text": "Ablegen", "confidence": 0.72, "source": "ai"}
    ]
}
```

---

### 3.3 Missing Translation Detection Dashboard

**Impact**: High | **Effort**: 1 week | **Priority**: P1

**Features**:
- List of untranslated strings by language
- Coverage percentage per language/component
- Quick-generate buttons
- Priority sorting (frequently accessed first)

---

### 3.4 Terminology Consistency Checker

**Impact**: High | **Effort**: 3-4 weeks | **Priority**: P1

**Implementation**:
```php
// Quality Check Report
Translation Quality Report for German
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
✅ Grammar: PASSED (99% confidence)
⚠️ Consistency: WARNING - "Speichern" used 3x, "Sichern" used 2x
✅ Terminology: PASSED (matches glossary)
⚠️ Length: WARNING - Button text 24 chars (max: 20)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Overall Score: 8.5/10
```

---

### 3.5 Translation Quality Scoring

**Impact**: Medium | **Effort**: 3 weeks | **Priority**: P2

**Scoring Dimensions**:
- Grammar/spelling (LLM validation)
- Terminology consistency
- Length appropriateness
- Context match (button vs. label vs. message)
- Glossary compliance

---

### 3.6 Semantic Translation Search

**Impact**: Medium | **Effort**: 2-3 weeks | **Priority**: P2

**Features**:
- Search by meaning, not just key
- Find similar translations for reuse
- Vector embeddings for semantic matching
- "Related Translations" sidebar

---

### 3.7 Translation Memory

**Impact**: Medium | **Effort**: 4-5 weeks | **Priority**: P3

**Features**:
- Build translation pair relationships
- Reuse translations for similar strings
- Learn from approved translations
- Cross-project memory sharing

---

## 4. Database Schema Extensions

```sql
ALTER TABLE tx_nrtextdb_domain_model_translation ADD (
    -- AI Quality Metrics
    quality_score DECIMAL(3,2),
    grammar_check_passed BOOLEAN,
    consistency_check_passed BOOLEAN,

    -- AI Metadata
    ai_generated BOOLEAN DEFAULT FALSE,
    ai_model_used VARCHAR(100),
    ai_confidence DECIMAL(3,2),

    -- Workflow
    review_status ENUM('draft', 'pending', 'approved', 'rejected'),
    reviewed_by INT,
    review_notes LONGTEXT,

    -- Performance
    embedding_vector LONGBLOB,

    -- Audit
    ai_suggested_at TIMESTAMP,
    quality_checked_at TIMESTAMP
);

-- Glossary table
CREATE TABLE tx_nrtextdb_glossary (
    uid INT PRIMARY KEY AUTO_INCREMENT,
    term VARCHAR(255) NOT NULL,
    translation VARCHAR(255) NOT NULL,
    source_language VARCHAR(10) NOT NULL,
    target_language VARCHAR(10) NOT NULL,
    context VARCHAR(100),
    is_preferred BOOLEAN DEFAULT TRUE,
    UNIQUE KEY term_lang (term, source_language, target_language)
);
```

---

## 5. Service Architecture

```
┌─────────────────────────────────────────┐
│         TextDB Backend Module            │
└───────────────┬─────────────────────────┘
                │
┌───────────────▼─────────────────────────┐
│      TextDB AI Service Layer             │
├─────────────────────────────────────────┤
│ TranslationSuggestionService            │
│ TranslationQualityService               │
│ TranslationGenerationService            │
│ EmbeddingService                        │
│ GlossaryService                         │
└───────────────┬─────────────────────────┘
                │
┌───────────────▼─────────────────────────┐
│       AI Base Provider Layer             │
├─────────────────────────────────────────┤
│ OpenAI │ Claude │ DeepL │ Gemini        │
└─────────────────────────────────────────┘
```

---

## 6. Configuration

```yaml
# ext_conf_template.txt
textdb_ai:
  enabled: true

  # Provider settings
  provider: 'openai'  # openai, claude, deepl
  apiKey: ''

  # Feature toggles
  features:
    suggestions: true
    qualityChecks: true
    autoGeneration: true
    embeddingSearch: true

  # Quality settings
  quality:
    minimumScore: 0.8
    enforceConsistency: true
    autoApproveHighConfidence: false

  # Glossary
  glossary:
    enabled: true
    autoAdd: true
```

---

## 7. Implementation Phases

### Phase 1 (MVP) - 3 months
- Quick-Translate (one-click bulk translation)
- Real-time suggestions
- Missing translation detection

### Phase 2 - 2 months
- Quality scoring system
- Terminology checker
- Consistency enforcement

### Phase 3 - 2 months
- Semantic search with embeddings
- Translation memory
- Approval workflow automation

---

## 8. Success Metrics

| Metric | Current | Target |
|--------|---------|--------|
| Translation time per string | 2-5 min | 10-30 sec |
| Manual vs. AI-assisted ratio | 100% / 0% | 30% / 70% |
| Translation accuracy | Variable | >95% |
| Coverage gaps detected | Manual | Automatic |

---

## Sources

- [TYPO3 Extension 'nr_textdb'](https://extensions.typo3.org/extension/nr_textdb)
- [Packagist: netresearch/nr-textdb](https://packagist.org/packages/netresearch/nr-textdb)
- [T3CON23 AI Trinity](https://typo3.com/blog/t3con-recap-ai-trinity)
