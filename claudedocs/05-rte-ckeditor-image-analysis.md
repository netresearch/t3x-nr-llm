# RTE CKEditor Image Extension AI/LLM Integration Analysis

> Extension: netresearch/rte-ckeditor-image
> GitHub: https://github.com/netresearch/t3x-rte_ckeditor_image
> Analysis Date: 2025-12-22

---

## 1. Extension Overview

The **RTE CKEditor Image** extension restores comprehensive image handling to CKEditor in TYPO3, which was intentionally removed in TYPO3 v10.

### Current Version
- v13.1.0 (2025-12-20)
- TYPO3 13.4 LTS+
- PHP 8.2+
- License: AGPL-3.0-or-later

### Core Features
- Full TYPO3 FAL integration with native file browser
- "Magic Images" processing (cropping, scaling, TSConfig)
- Image dialog (width, height, alt text, title)
- Automatic aspect ratio maintenance
- SVG dimension extraction
- Quality selector (1x-6x processing)
- Lazy loading support
- Custom CSS classes via CKEditor 5 style system
- External image fetching and auto-upload

---

## 2. Current Architecture

### Backend Components
- **Controllers**:
  - `SelectImageController` - handles image selection via AJAX
  - `ImageRenderingController` - frontend rendering with TypoScript

### PSR-14 Events
- `AfterPrepareConfigurationForEditorEvent`
- `RteConfigurationListener` - injects backend routes into CKEditor config

### Current Metadata Handling
All metadata is **manual entry only**:
- Alternative text (alt)
- Title attributes
- Descriptions (optional)
- Width/height dimensions

---

## 3. AI Enhancement Opportunities

### 3.1 Auto-Generate Alt Text

**Impact**: Very High | **Effort**: 3-4 weeks | **Priority**: P0

**Workflow**:
```
1. User selects image in file browser
2. Before returning to CKEditor, submit image to vision API
3. Generate descriptive alt text using image analysis
4. Return with pre-filled alt field
5. User can accept, edit, or regenerate
```

**Vision API Options**:
| Provider | Strengths | Cost |
|----------|-----------|------|
| OpenAI GPT-4V | Best understanding | $0.01-0.03/image |
| Google Cloud Vision | OCR, object detection | $1.50/1000 images |
| Claude 3 Vision | Nuanced content | $0.003/image (input) |
| LLaVA (Local) | Privacy, no cost | Free (requires GPU) |

**Configuration**:
```yaml
rte_ckeditor_image:
  ai:
    altText:
      enabled: true
      provider: 'openai'
      autoFill: false  # suggestion vs auto-fill
      style: 'descriptive'  # descriptive, brief, seo-optimized
      maxLength: 125
```

---

### 3.2 Auto-Generate Titles

**Impact**: High | **Effort**: 2 weeks | **Priority**: P0

**Context-Aware Generation**:
- Extract surrounding content (paragraph, heading)
- Analyze image content via vision API
- Generate concise, contextually relevant titles
- Optimize for tooltip UX (50-60 char limit)

---

### 3.3 Image Description Generation

**Impact**: Medium | **Effort**: 3 weeks | **Priority**: P1

**Multi-Level Descriptions**:
```
BRIEF: "Dog playing in park"

STANDARD: "A golden retriever running through a sunny park
with green grass and trees in background"

DETAILED: "A medium-sized golden retriever dog captured
mid-run in an open park setting. The dog is in sharp focus,
displaying playful body language. Background shows mature
deciduous trees and well-maintained green lawn."

TECHNICAL: "Composition: Rule of thirds, Subject: left-center,
Lighting: Natural daylight, Golden hour, Colors: Golden, Green"
```

---

### 3.4 Image Content Analysis

**Impact**: Medium | **Effort**: 4 weeks | **Priority**: P1

**Analysis Response Structure**:
```json
{
    "objects": ["dog", "park", "tree"],
    "scene": {
        "type": "outdoor",
        "setting": "park",
        "timeOfDay": "golden_hour"
    },
    "colors": {
        "dominant": ["#D4A574", "#2E7D32"],
        "mood": "warm"
    },
    "text": [],
    "quality": {
        "score": 8.5,
        "sharpness": "good",
        "exposure": "optimal"
    },
    "accessibility": {
        "contrast": "adequate",
        "colorblindness": ["deuteranopia-safe"]
    }
}
```

---

### 3.5 Additional Features

| Feature | Impact | Priority |
|---------|--------|----------|
| Duplicate detection | Medium | P2 |
| SEO-aware metadata | Medium | P2 |
| WCAG compliance check | Medium | P2 |
| Copyright/watermark detection | Low | P3 |
| NSFW content filtering | Low | P3 |

---

## 4. Implementation Architecture

### Service Layer

```
SelectImageController (existing)
    ↓
ImageAiService (new)
    ├─→ VisionApiProviderInterface
    │   ├─→ OpenAiVisionProvider
    │   ├─→ GoogleCloudVisionProvider
    │   ├─→ AnthropicVisionProvider
    │   └─→ LocalVisionProvider
    ├─→ MetadataGeneratorService
    │   ├─→ AltTextGenerator
    │   ├─→ TitleGenerator
    │   └─→ DescriptionGenerator
    └─→ AnalysisCacheService
```

### New PSR-14 Events

```php
// Event: Image analysis requested
class ImageAnalysisRequestedEvent {
    public function __construct(
        private File $file,
        private string $analysisType,  // 'altText', 'full'
        private array $context = []
    ) {}
}

// Event: Analysis completed
class ImageAnalysisCompletedEvent {
    public function __construct(
        private File $file,
        private AnalysisResult $result
    ) {}
}

// Event: Alt text generated
class AltTextGeneratedEvent {
    public function __construct(
        private File $file,
        private string $generatedAltText,
        private float $confidence
    ) {}
}
```

### Controller Enhancement

```php
public function selectImageAction(ServerRequestInterface $request): ResponseInterface
{
    // ... existing code ...

    $file = $this->getSelectedFile($request);

    // Dispatch AI analysis event
    $this->eventDispatcher->dispatch(
        new ImageAnalysisRequestedEvent($file, 'full', [
            'pageContent' => $this->getCurrentPageContent()
        ])
    );

    return $this->jsonResponse([
        'file' => $file,
        'suggestions' => [
            'altText' => $altTextSuggestion,
            'title' => $titleSuggestion,
            'description' => $descriptionSuggestion,
        ]
    ]);
}
```

---

## 5. CKEditor Integration

### Dialog Enhancement

```javascript
// ImageDialogPlugin.js
class ImageDialogPlugin {
    modifyDialogDefinition(evt) {
        const definition = evt.data.definition;

        // Add AI suggestions section
        definition.tabs[0].contents.push({
            type: 'fieldset',
            label: 'AI Suggestions',
            children: [
                {
                    type: 'text',
                    id: 'aiAltText',
                    label: 'AI-Generated Alt Text'
                },
                {
                    type: 'button',
                    id: 'regenerateAlt',
                    label: 'Regenerate',
                    onClick: () => this.regenerateAltText()
                },
                {
                    type: 'button',
                    id: 'acceptAlt',
                    label: 'Accept',
                    onClick: () => this.acceptSuggestion()
                }
            ]
        });
    }

    async generateAltText() {
        const response = await fetch('/api/rte-ckeditor-image/analyze', {
            method: 'POST',
            body: JSON.stringify({
                imageUrl: this.getSelectedImageUrl(),
                analysisType: 'altText'
            })
        });
        return await response.json();
    }
}
```

---

## 6. Configuration

```yaml
# TypoScript setup
plugin.tx_rteckeditorimage {
    ai {
        enabled = 1
        provider = openai
        apiKey = {$plugin.tx_rteckeditorimage.ai.apiKey}

        # Feature toggles
        generateAltText = 1
        generateTitle = 1
        generateDescription = 1
        performAnalysis = 1

        # Generation styles
        altTextStyle = descriptive
        titleStyle = concise

        # Caching
        cacheResults = 1
        cacheTtl = 2592000  # 30 days

        # Batch processing
        batchProcessing = 1
        batchSize = 10
    }
}
```

---

## 7. Database Schema

```sql
-- Analysis cache table
CREATE TABLE tx_rteckeditorimage_analysis (
    uid INT PRIMARY KEY AUTO_INCREMENT,
    file_uid INT NOT NULL,
    file_hash VARCHAR(64) NOT NULL,

    alt_text TEXT,
    title VARCHAR(255),
    description_brief TEXT,
    description_standard TEXT,
    description_detailed TEXT,

    objects JSON,
    scene JSON,
    colors JSON,
    quality_score DECIMAL(3,2),

    provider VARCHAR(50),
    model VARCHAR(100),
    confidence DECIMAL(3,2),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP,

    UNIQUE KEY file_hash (file_hash),
    KEY file_uid (file_uid)
);
```

---

## 8. API Endpoints

```
POST /api/rte-ckeditor-image/generate-alt-text
  Input: { fileUid: 123, context: {...} }
  Output: { altText: "...", confidence: 0.92 }

POST /api/rte-ckeditor-image/analyze
  Input: { fileUid: 123, analysisType: "full" }
  Output: { objects: [...], scene: {...}, ... }

POST /api/rte-ckeditor-image/batch-analyze
  Input: { fileUids: [1,2,3] }
  Output: { results: [...] }
```

---

## 9. Implementation Phases

### Phase 1: MVP (Months 1-2)
- Alt text generation + title generation
- OpenAI Vision integration
- CKEditor dialog enhancement
- Basic caching

### Phase 2: Analysis (Months 2-3)
- Full image analysis
- Duplicate detection
- Additional providers (Google, Claude)
- Batch processing API

### Phase 3: Advanced (Month 3+)
- Context-aware SEO optimization
- WCAG compliance checking
- Local model support (LLaVA)

---

## 10. Security Considerations

### Content Privacy
- Implement opt-in for external API calls
- Add audit logging for all AI requests
- Cache results with encryption
- Provide local model alternatives
- GDPR compliance (images may be PII)

### Secure API Requests
```php
class SecureVisionApiProvider {
    public function analyze(File $file): AnalysisResult {
        if ($this->isPrivacySensitive($file)) {
            return $this->analyzeLocally($file);
        }
        return $this->analyzeViaApi($file);
    }
}
```

---

## 11. Success Metrics

| Metric | Current | Target |
|--------|---------|--------|
| Alt text completion rate | ~40% | >95% |
| Time to add alt text | 30-60 sec | <5 sec |
| WCAG compliance | Variable | >98% |
| Image metadata quality | Manual | AI-assisted |

---

## Sources

- [GitHub: t3x-rte_ckeditor_image](https://github.com/netresearch/t3x-rte_ckeditor_image)
- [Documentation](https://docs.typo3.org/p/netresearch/rte-ckeditor-image/main/en-us/)
- [Packagist](https://packagist.org/packages/netresearch/rte-ckeditor-image)
- [b13 Descriptive Images](https://b13.com/solutions/typo3/descriptive-images)
