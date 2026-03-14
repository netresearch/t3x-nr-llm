<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-03-14 -->

# AGENTS.md — nr_llm

<!-- AGENTS-GENERATED:START overview -->
## Overview
TYPO3 v13.4+ extension providing a unified LLM provider abstraction layer. Supports OpenAI, Claude, Gemini, Groq, Mistral, Ollama, and OpenRouter through a standardized interface. PHP 8.2+ with PHPStan level 10.

**Three-tier architecture:** Providers (API connections) -> Models (per-provider) -> Configurations (use-case bundles)
<!-- AGENTS-GENERATED:END overview -->

<!-- AGENTS-GENERATED:START precedence -->
## Precedence
Scoped AGENTS.md files in subdirectories override this file for their scope. This file provides project-wide defaults.
<!-- AGENTS-GENERATED:END precedence -->

<!-- AGENTS-GENERATED:START scope-index -->
## Scoped Files

| Directory | Scope |
|-----------|-------|
| `Classes/AGENTS.md` | PHP source code patterns, architecture rules |
| `Configuration/AGENTS.md` | TYPO3 TCA, services, caching, backend routes |
| `Documentation/AGENTS.md` | RST docs, ADRs, branding, guides.xml |
| `Tests/AGENTS.md` | Testing patterns, coverage, test runner |
| `Resources/AGENTS.md` | Fluid templates, XLIFF, icons, JS/CSS |
| `.ddev/AGENTS.md` | Local development environment |
| `.github/workflows/AGENTS.md` | CI/CD workflows |
<!-- AGENTS-GENERATED:END scope-index -->

<!-- AGENTS-GENERATED:START setup -->
## Setup
```bash
# Local development
ddev start && ddev composer install

# Docker-based test runner (ALWAYS use this, never phpunit directly)
./Build/Scripts/runTests.sh -s unit         # Unit tests
./Build/Scripts/runTests.sh -s functional   # Functional tests
./Build/Scripts/runTests.sh -s phpstan      # Static analysis (level 10)
./Build/Scripts/runTests.sh -s cgl          # PHP-CS-Fixer (fix)
./Build/Scripts/runTests.sh -s cgl -n       # PHP-CS-Fixer (dry-run)
./Build/Scripts/runTests.sh -s rector -n    # Rector (dry-run)
./Build/Scripts/runTests.sh -s mutation     # Mutation testing
./Build/Scripts/runTests.sh -s e2e          # Playwright E2E
./Build/Scripts/runTests.sh -p 8.3          # Specify PHP version
```
<!-- AGENTS-GENERATED:END setup -->

<!-- AGENTS-GENERATED:START filemap -->
## Key Files

| File | Purpose |
|------|---------|
| `ext_emconf.php` | Extension metadata, version 0.5.0 |
| `ext_localconf.php` | Extension bootstrap |
| `composer.json` | Dependencies (composer.lock NOT committed) |
| `Build/phpunit.xml` | PHPUnit configuration |
| `Build/Scripts/runTests.sh` | Docker-based test runner (ALWAYS use this) |
| `infection.json.dist` | Mutation testing config (MSI >= 70%) |
| `grumphp.yml` | Pre-commit hooks (conventional commits) |
| `Configuration/Caching.php` | Cache config (no hardcoded backend, uses instance default) |
| `Configuration/Services.yaml` | DI container, autowiring |
<!-- AGENTS-GENERATED:END filemap -->

<!-- AGENTS-GENERATED:START directory-structure -->
## Directory Structure
```
nr_llm/
├── Classes/                    # 127 PHP source files
│   ├── Controller/Backend/     # Backend controllers, DTOs, Response objects
│   ├── Domain/                 # Entities, repositories, enums, DTOs, value objects
│   ├── Provider/               # LLM adapters + Contract interfaces + exceptions
│   ├── Service/                # Business logic, feature services, wizard, options
│   ├── Specialized/            # DeepL, speech, image generation (with sub-packages)
│   ├── Form/                   # TCA form elements (ModelIdElement, ModelConstraintsWizard)
│   ├── Utility/                # SafeCastTrait
│   └── DependencyInjection/    # Compiler passes
├── Configuration/              # TYPO3 config (TCA, services, caching, icons, routes)
├── Documentation/              # 61 RST files + guides.xml + brand assets
│   └── Adr/                    # 20 Architecture Decision Records (001-020)
├── Tests/                      # Unit, Integration, Functional, Fuzzy, Architecture, E2E
├── Resources/                  # Templates, XLIFF (EN+DE), icons, CSS, JS
└── Build/                      # PHPStan, Rector, Fractor configs + runTests.sh
```
<!-- AGENTS-GENERATED:END directory-structure -->

<!-- AGENTS-GENERATED:START code-style -->
## Code Style
- **PSR-12** with TYPO3 conventions via PHP-CS-Fixer
- `declare(strict_types=1);` in ALL PHP files
- All properties typed, all methods have return types
- Conventional commits: `feat|fix|docs|style|refactor|perf|test|build|ci|chore|revert|security(scope)?: message`
- Signed commits required (`git commit -S --signoff`)
<!-- AGENTS-GENERATED:END code-style -->

<!-- AGENTS-GENERATED:START security -->
## Security
- API keys encrypted via `sodium_crypto_secretbox` with domain-separated key derivation
- Never log or expose API keys in error messages
- Sanitize user input before sending to LLM providers
- Treat LLM responses as untrusted content
- Security advisories: https://github.com/netresearch/t3x-nr-llm/security/advisories/new
<!-- AGENTS-GENERATED:END security -->

<!-- AGENTS-GENERATED:START ci -->
## CI/CD

| Workflow | Purpose |
|----------|---------|
| `ci.yml` | Lint, PHPStan, unit/functional tests, Rector, mutation |
| `e2e.yml` | Playwright E2E tests |
| `docs.yml` | Documentation rendering |
| `security.yml` | Gitleaks, dependency review, composer audit |
| `release.yml` | Release with SBOM, Cosign signing, SLSA attestation |
| `ter-publish.yml` | Manual TER publish |
| `auto-merge-deps.yml` | Auto-merge dependency PRs |
| `community.yml` | Community health |
<!-- AGENTS-GENERATED:END ci -->

<!-- AGENTS-GENERATED:START examples -->
## Examples
> Prefer looking at real code in this repo. Key reference files:
> - Provider implementation: `Classes/Provider/OpenAiProvider.php`
> - Feature service: `Classes/Service/Feature/CompletionService.php`
> - Unit test: `Tests/Unit/Service/Feature/CompletionServiceTest.php`
> - Architecture test: `Tests/Architecture/ControllerLayerTest.php`
> - ADR format: `Documentation/Adr/Adr014AiPoweredWizardSystem.rst`
<!-- AGENTS-GENERATED:END examples -->

<!-- AGENTS-GENERATED:START help -->
## When Stuck
- Run tests: `./Build/Scripts/runTests.sh -s unit` (NEVER phpunit directly)
- Check ADRs in `Documentation/Adr/` for design rationale (20 ADRs)
- API docs: `Documentation/Api/` (9 reference pages)
- Issues: https://github.com/netresearch/t3x-nr-llm/issues
- Discussions: https://github.com/netresearch/t3x-nr-llm/discussions
<!-- AGENTS-GENERATED:END help -->
