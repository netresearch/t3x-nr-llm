<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-08-19 -->

# AGENTS.md — Documentation

<!-- AGENTS-GENERATED:START overview -->
## Overview
TYPO3 RST documentation restructured into granular sub-pages. Includes the ADRs, the API reference, and Netresearch branding. Built with `guides.xml` (TYPO3 docs theme, version 0.4.11).
<!-- AGENTS-GENERATED:END overview -->

<!-- AGENTS-GENERATED:START setup -->
## Setup
```bash
# Render documentation locally
docker run --rm -v $(pwd):/project ghcr.io/typo3-documentation/render-guides:latest

# CI renders via docs.yml workflow
```
<!-- AGENTS-GENERATED:END setup -->

<!-- AGENTS-GENERATED:START tests -->
## Build/Tests
Docs are rendered and validated in CI via `.github/workflows/docs.yml`. Local render uses the docker command in Setup.
<!-- AGENTS-GENERATED:END tests -->

<!-- AGENTS-GENERATED:START filemap -->
## Key Files

| Path | Purpose |
|------|---------|
| `guides.xml` | Build config: theme, project metadata, interlinking, all extension attributes |
| `Index.rst` | Main entry point |
| `Includes.rst.txt` | Shared RST definitions |
| `Sitemap.rst` | Navigation |
| `Changelog.rst` | Version history |

### Documentation Sections

| Section | Files | Content |
|---------|-------|---------|
| `Administration/` | one page per backend surface (Providers, Models, Configurations, Tasks, Wizards, Tools, Skills, Permissions, Analytics, Governance, AgentRuns, McpServers, PromptSnippets, SpecializedServices, UserBudgets, DataRetention) | Backend CRUD guides |
| `Configuration/` | ProviderFields, ModelFields, ConfigFields, TaskFields, Settings | TCA field reference |
| `Api/` | one page per public service or contract; `Stability.rst` states the `@api` promise | PHP API reference |
| `Testing/` | UnitTesting, FunctionalTesting, EndToEndTesting, CiConfiguration | Test guide |
| `Developer/` | one page per integration topic (IntegrationGuide, Streaming, ToolCalling, CustomProviders, ProviderRegistration, FallbackChain, ConfigurationPresets, EndpointProtection, QualityEvaluation, SafeMarkdownRendering) plus `FeatureServices/` | Integration guide |
| `Architecture/` | Index | Design patterns |
| `Introduction/` | Index | Overview, features |
| `Installation/` | Index | Setup instructions |
| `Adr/` | `AdrNNNTitle.rst` | Architecture Decision Records |

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
ADRs use numbered naming: `AdrNNNTitle.rst`; follow the existing format for new ones.

`Adr/Index.rst` documents the record lifecycle — the `:Status:` vocabulary and the paired `:Amends:` / `:Amended:` and `:Supersedes:` / `:Superseded:` fields. An ADR that overturns part of an earlier one edits that earlier record in the same change; `Tests/Unit/AdrLifecycleTest.php` fails the build when only one end of an ADR-to-ADR link is written. A record superseded by something that is not an ADR names it in prose and has no counterpart — see `Adr/Index.rst` for that carve-out.

### Branding
Documentation uses Netresearch branding: teal underline SVG for headings, emoji icons for feature cards, footer card with company info. See `guides.xml` `<extension>` attributes for project links.

### Supported-version prose surfaces (unchecked by tests)
`VersionConsistencyTest` pins `composer.json`, `ext_emconf.php`, the `ci.yml` matrix and `Api/SupportMatrix.rst` against each other — it does **not** see the prose. These repeat the supported TYPO3/PHP range with nothing checking them; update them by hand in the same change, and grep for the old floor before you finish (this list is what has been found, not a guarantee):

- repo-root `README.md` (the two badges and the Requirements list)
- `Installation/Index.rst`
- `Introduction/Index.rst`
- `Developer/FeatureServices/Index.rst`
- `Testing/CiConfiguration.rst` (hand-copied matrix excerpt)
- `Developer/IntegrationGuide.rst` (the TER constraint in its `ext_emconf.php` example)

Repo-root `BASELINE.md`'s "Multi-version CI" row is the one prose surface that IS asserted, by `Tests/Unit/BaselineConsistencyTest.php`.
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
- Existing pages serve as reference patterns
<!-- AGENTS-GENERATED:END help -->
