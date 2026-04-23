<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-04-23 -->

# AGENTS.md — Documentation

<!-- AGENTS-GENERATED:START overview -->
## Overview
TYPO3 RST documentation (69 files) restructured into granular sub-pages. Includes 26 ADRs, API reference (9 pages), and Netresearch branding. Built with `guides.xml` (TYPO3 docs theme, version 0.4.11).
<!-- AGENTS-GENERATED:END overview -->

<!-- AGENTS-GENERATED:START setup -->
## Setup
```bash
# Render documentation locally
docker run --rm -v $(pwd):/project ghcr.io/typo3-documentation/render-guides:latest

# CI renders via docs.yml workflow
```
<!-- AGENTS-GENERATED:END setup -->

## Tests
Docs are rendered and validated in CI via `.github/workflows/docs.yml`. Local render uses the docker command in Setup.

<!-- AGENTS-GENERATED:START filemap -->
## Key Files

| Path | Purpose |
|------|---------|
| `guides.xml` | Build config: theme, project metadata, interlinking, all extension attributes |
| `Index.rst` | Main entry point |
| `Includes.rst.txt` | Shared RST definitions |
| `Sitemap.rst` | Navigation |
| `Changelog.rst` | Version history |

### Documentation Sections (69 files total)

| Section | Files | Content |
|---------|-------|---------|
| `Administration/` | Providers, Models, Configurations, Tasks, Wizards | Backend CRUD guides |
| `Configuration/` | ProviderFields, ModelFields, ConfigFields, TaskFields, Settings | TCA field reference |
| `Api/` | LlmServiceManager, CompletionService, EmbeddingService, VisionService, TranslationService, ResponseObjects, OptionClasses, ProviderInterface, Exceptions | PHP API reference |
| `Testing/` | UnitTesting, FunctionalTesting, EndToEndTesting, CiConfiguration | Test guide |
| `Developer/` | Streaming, ToolCalling, CustomProviders, FeatureServices/ | Integration guide |
| `Architecture/` | Index | Design patterns |
| `Introduction/` | Index | Overview, features |
| `Installation/` | Index | Setup instructions |
| `Adr/` | 26 ADRs | Architecture Decision Records |

### Brand Assets

| File | Purpose |
|------|---------|
| `Images/netresearch-underline.svg` | Teal underline decoration for headings |
| `Images/netresearch-symbol.svg` | Netresearch symbol logo |
| `Images/netresearch-banner.png` | Banner image |
| `Images/netresearch-badge.png` | Badge image |
| `Images/netresearch-logo.png` | Full logo |
| `Images/backend-*.png` | 6 backend screenshots |
<!-- AGENTS-GENERATED:END filemap -->

<!-- AGENTS-GENERATED:START code-style -->
## Code style
- **UTF-8**, **4-space indentation**, **80 char max line length**, **LF line endings**
- **CamelCase** for file/directory names, **sentence case** for headings
- **Index.rst** required in EVERY subdirectory
- **PNG** for screenshots with `:alt:` text
- Inline code uses RST roles: `:php:`, `:file:`, `:typoscript:`
- Code blocks require `:caption:`

### Heading Levels
```rst
=============
Document Title (=, overlined)
=============

Chapter (=)
===========

Section (-)
-----------

Subsection (~)
~~~~~~~~~~~~~~
```
<!-- AGENTS-GENERATED:END code-style -->

<!-- AGENTS-GENERATED:START patterns -->
## Key Patterns

### TYPO3 Directives
- `.. confval::` for configuration values
- `.. versionadded::` / `.. versionchanged::` for versioning
- `.. card-grid::` for navigation grids
- `.. note::` / `.. warning::` / `.. tip::` for admonitions

### ADR Format (Adr/)
ADRs use numbered naming: `AdrNNNTitle.rst`. 26 exist; follow existing format for new ADRs.

### Branding
Documentation uses Netresearch branding: teal underline SVG for headings, emoji icons for feature cards, footer card with company info. See `guides.xml` `<extension>` attributes for project links.
<!-- AGENTS-GENERATED:END patterns -->

<!-- AGENTS-GENERATED:START security -->
## Security
- Never include real API keys in code examples
- Use placeholder values: `your-api-key-here`
- Link to security advisories, not inline vulnerability details
<!-- AGENTS-GENERATED:END security -->

<!-- AGENTS-GENERATED:START checklist -->
## PR Checklist
- [ ] Every directory has `Index.rst`
- [ ] 4-space indentation, no tabs
- [ ] Max 80 characters per line
- [ ] Code blocks have `:caption:`
- [ ] Inline code uses RST roles
- [ ] New pages added to parent `.. toctree::`
- [ ] `guides.xml` version matches `ext_emconf.php`
<!-- AGENTS-GENERATED:END checklist -->

<!-- AGENTS-GENERATED:START examples -->
## Examples
> **Look at existing pages for patterns:**
> - API reference: `Api/CompletionService.rst`
> - Admin guide: `Administration/Providers.rst`
> - Config reference: `Configuration/ProviderFields.rst`
> - ADR: `Adr/Adr014AiPoweredWizardSystem.rst`
<!-- AGENTS-GENERATED:END examples -->

<!-- AGENTS-GENERATED:START help -->
## When Stuck
- TYPO3 docs guide: https://docs.typo3.org/m/typo3/docs-how-to-document/main/en-us/
- Render locally with Docker (see Setup above)
- Check `guides.xml` for build configuration
- Existing 69 files serve as reference patterns
<!-- AGENTS-GENERATED:END help -->
