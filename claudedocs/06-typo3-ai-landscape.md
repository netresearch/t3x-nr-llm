# TYPO3 AI/LLM Landscape Analysis

> Analysis Date: 2025-12-22
> Purpose: Understand existing TYPO3 AI features and gap analysis for ai_base extension

---

## 1. TYPO3 v14 Native AI Features

### Current Status
TYPO3 v14 does **NOT** include direct AI features in the Core.

### Planned Infrastructure (GenAI Toolbox)

| Component | Description | Timeline |
|-----------|-------------|----------|
| **GenAI-Toolbox API** | Modular framework for AI service integration | v14.1+ |
| **Integrations Hub** | Central hub for translation, AI, external tools | v14.1+ |
| **Workspace Compatibility** | AI modifications respect versioning/workspace | v14.0+ |
| **Content Blocks Integration** | Structured content paired with AI | v14.0+ |
| **Permission Enforcement** | AI access respects user permissions | v14.0+ |
| **MCP Support** | Exploring Model Context Protocol | Research |

### Release Timeline
- v14.0: November 25, 2025
- v14.1: January 20, 2026
- v14.2: March 31, 2026 (feature freeze)
- v14.3 LTS: April 21, 2026

### Design Philosophy
- Flexibility over vendor lock-in
- Privacy-first (users control data flow)
- Sustainability (adapts as AI evolves)

---

## 2. Existing TYPO3 AI Extensions

### Content Generation & Writing

#### t3_cowriter (Netresearch)
- **Package**: `netresearch/t3-cowriter`
- **Provider**: OpenAI GPT-4 only
- **Features**: RTE integration, inline text generation
- **Limitation**: Single provider

#### T3AI (NITSAN/T3Planet)
- **Package**: `nitsan-technologies/ns_t3ai`
- **Providers**: OpenAI, Anthropic, Google Gemini, Azure, DeepSeek, Mistral, Ollama
- **Features**:
  - Dashboard for content management
  - SEO tools
  - Custom prompts library
  - AI chatbot integration
  - AI voiceover
- **Architecture**: 30+ PHP classes, BaseClient service
- **Best Multi-Provider Support**

#### pagemachine/ai-tools
- **Package**: `pagemachine/ai-tools`
- **Features**:
  - AI image generation
  - Image variation creation
  - Automatic alt text
  - Multi-language alt text translation
- **Use Case**: WCAG/BITV compliance

---

### Translation & Localization

#### DeepL Translate Core (web-vision)
- **Package**: `web-vision/deepltranslate-core`
- **Provider**: DeepL API
- **Supported TYPO3**: v9-14+
- **Features**:
  - Translation Wizard integration
  - DeepL Glossary support
  - Formality settings
  - Auto language detection

#### TYPO3-AI (TYPO3-Headless)
- **Package**: TYPO3-Headless/typo3-ai
- **Provider**: OpenAI ChatGPT
- **Features**: Language detection, translation suggestions

---

### Search Enhancement

#### T3AS (TYPO3 AI Search)
- **Providers**: Gemini, Mistral, local models
- **Features**:
  - Semantic search (understands intent)
  - Vector embeddings
  - Local vector database
  - RAG capability
  - GDPR-friendly on-premises option

#### SEAL Search AI
- **Architecture**: Symfony AI Platform adapter
- **Features**: Embedding-based semantic search

---

### Image Generation

#### mkcontentai (DMK)
- **Package**: DMKEBUSINESSGMBH/typo3-mkcontentai
- **Providers**:
  - OpenAI DALL-E
  - Stable Diffusion API
  - alttext.ai

---

### Infrastructure & Standards

#### LLMS.txt Generator (web-vision)
- **Package**: `web-vision/ai-llms-txt`
- **Supported TYPO3**: v13, v14
- **Purpose**: Generate llms.txt for LLM crawling policies

#### TYPO3 MCP Server (hauptsacheNet)
- **Package**: `hn/typo3-mcp-server`
- **Protocol**: Model Context Protocol
- **Features**:
  - AI-safe page/record manipulation via workspaces
  - Translation assistance
  - Bulk updates
  - Document importing
- **Roadmap**: Image support planned

---

## 3. Gap Analysis

### Critical Gaps

| Gap | Impact | Current State |
|-----|--------|---------------|
| **No Unified Provider Abstraction** | High | Each extension implements own integration |
| **No "AI Base" Foundation** | High | No reusable provider framework |
| **Limited Provider Support** | Medium | Most extensions support 1-3 providers |
| **No Standardized Events** | Medium | No pre/post hooks for AI operations |
| **No Frontend AI** | Medium | All features backend-only |
| **Image/Media AI Limited** | Medium | Only alt text, no full metadata |
| **No Workflow Integration** | Medium | No AI-powered scheduling/automation |
| **No Real-time Streaming** | Low | No streaming response support |
| **No Prompt Management** | Low | No shared prompt versioning |
| **No Cost Management** | Low | No token tracking across extensions |

### What ai_base Should Provide

1. **Provider Abstraction Interface**
   - Unified API for Chat, Embeddings, Vision, Image Generation
   - Streaming response support
   - Standard error handling

2. **Provider Registry**
   - Plugin system for adding providers
   - Runtime provider selection
   - Fallback chains

3. **Configuration Management**
   - Centralized API key management
   - Per-site configuration
   - Environment variable support

4. **Event System**
   - Pre/post generation hooks
   - Content validation events
   - Provider selection events

5. **Utility Services**
   - Token counting
   - Cost calculation
   - Rate limiting
   - Retry logic

6. **Logging & Audit**
   - AI operation tracking
   - Token usage reporting
   - Cost attribution

7. **Security Layer**
   - Permission checking
   - Data sanitization
   - Audit logging

---

## 4. Provider Comparison

| Provider | Extensions Using | Strengths |
|----------|------------------|-----------|
| **OpenAI** | t3_cowriter, T3AI, mkcontentai | Wide adoption, mature API |
| **Anthropic Claude** | T3AI | Long context, safety |
| **Google Gemini** | T3AI, T3AS | Multimodal, competitive pricing |
| **DeepL** | deepltranslate-core | Purpose-built translation |
| **Ollama** | T3AI | Local, privacy, no cost |
| **Azure OpenAI** | T3AI | Enterprise, SLA |
| **Mistral** | T3AI, T3AS | Open-weight, Europe-based |

---

## 5. Recommendation

### Build ai_base to:

1. **Fill the Gap**: No existing extension provides a pure abstraction layer
2. **Enable Ecosystem**: Extensions can focus on features, not provider integration
3. **Standardize**: Common patterns for all AI operations
4. **Future-Proof**: Easy to add new providers as they emerge
5. **Complement T3AI**: Not competition - T3AI is feature-rich, ai_base is infrastructure

### Differentiation from T3AI

| Aspect | T3AI | ai_base |
|--------|------|---------|
| **Purpose** | Full-featured AI extension | Infrastructure/library |
| **Target** | End users | Extension developers |
| **UI** | Complete backend module | Minimal (configuration only) |
| **Features** | Content generation, SEO, chatbot | Provider abstraction, events |
| **Dependencies** | Self-contained | Depends on other extensions |

---

## 6. Integration Strategy

### ai_base + Netresearch Extensions

```
┌─────────────────────────────────────────────────────┐
│                 ai_base Extension                    │
│  ┌──────────────────────────────────────────────┐   │
│  │         Provider Abstraction Layer            │   │
│  │  OpenAI | Anthropic | Gemini | DeepL | Ollama │   │
│  └──────────────────────────────────────────────┘   │
│  ┌──────────────────────────────────────────────┐   │
│  │              Core Services                    │   │
│  │  Events | Config | Cache | Rate Limit | Audit │   │
│  └──────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────┘
          │              │              │
          ▼              ▼              ▼
┌─────────────┐  ┌─────────────┐  ┌─────────────┐
│   textdb    │  │rte_ckeditor │  │  contexts   │
│ Translation │  │   Image AI  │  │  Rule Gen   │
└─────────────┘  └─────────────┘  └─────────────┘
```

---

## Sources

- [TYPO3 v14 AI Integrations Strategy](https://news.typo3.com/archive/typo3-v14-ai-integrations)
- [T3AI Documentation](https://docs.t3planet.com/en/latest/ExtNsT3AI/)
- [t3_cowriter GitHub](https://github.com/netresearch/t3x-cowriter)
- [DeepL Translate Core Docs](https://docs.typo3.org/p/web-vision/deepltranslate-core/main/en-us/)
- [TYPO3 MCP Server](https://github.com/hauptsacheNet/typo3-mcp-server)
- [Best TYPO3 AI Extensions 2025](https://t3planet.de/en/blog/best-typo3-ai-extensions/)
