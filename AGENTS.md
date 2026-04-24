<!-- Managed by agent: keep sections and order; edit content, not structure. Last Updated: 2026-04-24. Last verified: 2026-04-24 -->

# AGENTS.md — nr_llm

<!-- AGENTS-GENERATED:START overview -->
## Overview
TYPO3 v13.4+ extension providing a unified LLM provider abstraction layer. Supports OpenAI, Claude, Gemini, Groq, Mistral, Ollama, and OpenRouter through a standardized interface. PHP 8.2+ with PHPStan level 10.

**Three-tier architecture:** Providers (API connections) -> Models (per-provider) -> Configurations (use-case bundles)
<!-- AGENTS-GENERATED:END overview -->

<!-- AGENTS-GENERATED:START precedence -->
## Precedence
The closest AGENTS.md wins: scoped AGENTS.md files in subdirectories override this file for their scope. This root file provides project-wide defaults.
<!-- AGENTS-GENERATED:END precedence -->

<!-- AGENTS-GENERATED:START scope-index -->
## Index of scoped AGENTS.md

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
```
<!-- AGENTS-GENERATED:END setup -->

<!-- AGENTS-GENERATED:START commands -->
## Commands (verified 2026-04-24)

ALWAYS use the Docker test runner; never invoke `phpunit` / `phpstan` / `rector` directly. See `Build/Scripts/runTests.sh` for the full list and `make help` for shortcuts.

| Task | Command |
|------|---------|
| Unit tests | `./Build/Scripts/runTests.sh -s unit` |
| Functional tests | `./Build/Scripts/runTests.sh -s functional` |
| Static analysis (PHPStan level 10) | `./Build/Scripts/runTests.sh -s phpstan` |
| Code style (fix) | `./Build/Scripts/runTests.sh -s cgl` |
| Code style (dry-run) | `./Build/Scripts/runTests.sh -s cgl -n` |
| Rector (dry-run) | `./Build/Scripts/runTests.sh -s rector -n` |
| Mutation testing (Infection) | `./Build/Scripts/runTests.sh -s mutation` |
| E2E (Playwright) | `./Build/Scripts/runTests.sh -s e2e` |
| Pin PHP version | `./Build/Scripts/runTests.sh -p 8.3` |
| Coverage (HTML) | `./Build/Scripts/runTests.sh -s unitCoverage` |
<!-- AGENTS-GENERATED:END commands -->

<!-- AGENTS-GENERATED:START testing -->
## Testing

- Unit / Integration / Fuzzy / Functional / E2E suites — see `Tests/AGENTS.md` for layout details.
- PHPUnit configs: `Build/phpunit.xml` (unit + integration + fuzzy), `Build/FunctionalTests.xml` (functional + e2e-backend).
- Mutation: `infection.json.dist` (target MSI ≥ 70%).
- Architecture tests: `Tests/Architecture/` (phpat) — enforce layered boundaries (Controller → Service → Provider).
<!-- AGENTS-GENERATED:END testing -->

<!-- AGENTS-GENERATED:START development -->
## Development Workflow

1. Branch off `main` (worktree convention — see project memory).
2. Use `make` shortcuts (`make test-unit`, `make phpstan`, `make cgl`) — they delegate to `runTests.sh`.
3. Pre-commit hooks via `Build/captainhook.json` (auto-installed by composer plugin) run cgl + phpstan + commit-msg checks.
4. Sign commits with `git commit -S --signoff` (DCO required).
5. PRs target `main`. CI matrix: PHP 8.2–8.5 × TYPO3 13.4 / 14.0; merged via `--merge` strategy (preserves signatures).
<!-- AGENTS-GENERATED:END development -->

<!-- AGENTS-GENERATED:START filemap -->
## File Map

### Key Files

| File | Purpose |
|------|---------|
| `ext_emconf.php` | Extension metadata, version 0.7.0 |
| `ext_localconf.php` | Extension bootstrap |
| `composer.json` | Dependencies (composer.lock NOT committed) |
| `Build/phpunit.xml` | PHPUnit configuration |
| `Build/Scripts/runTests.sh` | Docker-based test runner (ALWAYS use this) |
| `infection.json.dist` | Mutation testing config (MSI >= 70%) |
| `Build/captainhook.json` | Git hooks (pre-commit, commit-msg, pre-push) |
| `Configuration/Caching.php` | Cache config (no hardcoded backend, uses instance default) |
| `Configuration/Services.yaml` | DI container, autowiring |
<!-- AGENTS-GENERATED:END filemap -->

<!-- AGENTS-GENERATED:START directory-structure -->
## Architecture

Three-tier model: **Provider → Model → Configuration**. See `Documentation/Adr/Adr001ThreeTierProviderArchitecture.rst` for the design rationale and `Classes/Provider/AGENTS.md` for adapter contracts.

### Directory Structure
```
nr_llm/
├── Classes/                    # 139 PHP source files
│   ├── Attribute/              # #[AsLlmProvider] auto-registration attribute
│   ├── Controller/Backend/     # Backend controllers, DTOs, Response objects
│   ├── DependencyInjection/    # Compiler passes (ProviderCompilerPass)
│   ├── Domain/                 # Entities, repositories, enums, DTOs, value objects
│   ├── Exception/              # Core domain exceptions
│   ├── Form/                   # TCA form elements (ModelIdElement, ModelConstraintsWizard)
│   ├── Provider/               # 7 LLM adapters + Contract interfaces + exceptions
│   ├── Service/                # Feature services, wizard, options, fallback chain
│   ├── Specialized/            # DeepL, speech (Whisper/TTS), image (DALL-E/FAL)
│   ├── Utility/                # SafeCastTrait
│   └── Widgets/DataProvider/   # Backend dashboard widgets (cost, requests)
├── Configuration/              # TYPO3 config (TCA, services, caching, icons, routes)
├── Documentation/              # 69 RST files + guides.xml + brand assets
│   └── Adr/                    # 26 Architecture Decision Records
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

<!-- AGENTS-GENERATED:START critical -->
## Critical Constraints

- **NEVER run `phpunit` / `phpstan` / `rector` directly** — always via `Build/Scripts/runTests.sh`. Direct invocations bypass the Docker PHP-version isolation and produce non-reproducible results.
- **NEVER commit `composer.lock`** — TYPO3 extensions are libraries; the lock file would conflict with project-level resolution.
- **NEVER hardcode a cache backend in `Configuration/Caching.php`** — let the host instance configure Redis/Valkey/Memcached transparently. Specify only `frontend`, `options`, and `groups`.
- **NEVER take TYPO3 backend screenshots below 1440px viewport** — sidebar and table columns get cut off.
- **API keys MUST be stored as nr-vault UUID identifiers**, never as plaintext in TCA / yaml / env. See `Documentation/Adr/Adr012ApiKeyStorageVault.rst`.
- **No email addresses in public docs** — use the GitHub issues / discussions / security-advisories links only.
<!-- AGENTS-GENERATED:END critical -->

<!-- AGENTS-GENERATED:START heuristics -->
## Heuristics — Quick Decisions

- **Where does the new feature service live?** `Classes/Service/Feature/` (one directory per feature, e.g. `Completion`, `Embedding`, `Translation`). Each feature has a service + DTO + tests.
- **Adding a new LLM provider?** Implement `Classes/Provider/Contract/LlmProviderInterface`, add `#[AsLlmProvider('name')]` attribute (auto-registers via `ProviderCompilerPass`), add the provider icon to `Resources/Public/Icons/provider-<name>.svg`.
- **Where does TCA live?** Per-table file under `Configuration/TCA/` for new tables; `Configuration/TCA/Overrides/` to extend existing tables (incl. `pages`, `tt_content`).
- **Stuck on a "this works locally but breaks in CI" issue?** Reproduce inside `Build/Scripts/runTests.sh -s <suite>` first — it uses the same Docker PHP image as CI.
- **Adding a config option?** TCA + `LLL:` translation key in `Resources/Private/Language/locallang*.xlf` for both EN and DE.
- **Touching the public surface?** Add an ADR under `Documentation/Adr/`. Format: `Adr<N>Description.rst`.
<!-- AGENTS-GENERATED:END heuristics -->

<!-- AGENTS-GENERATED:START utilities -->
## Shared Utilities — Don't Reinvent

- **Type narrowing**: `Classes/Utility/SafeCastTrait` — `safeIntCast`, `safeStringCast`, `safeArrayCast`. Use these before `(int)` / `(string)` casts to surface bad input as exceptions.
- **Provider invocation**: `Classes/Service/Feature/FallbackChain` — use this rather than calling providers directly; it handles retries, fallback ordering, and error mapping.
- **Cost tracking**: emit a `\Netresearch\NrLlm\Event\LlmRequestCompletedEvent` — the `UsageTrackerService` listens and aggregates. Don't write to the usage table directly.
- **Cache config**: `Configuration/Caching.php` already declares the `nrllm_responses`, `nrllm_models` caches. Add new caches there (no hardcoded backend).
<!-- AGENTS-GENERATED:END utilities -->

<!-- AGENTS-GENERATED:START security -->
## Security
- API keys stored as nr-vault UUID identifiers (envelope encryption via nr-vault extension)
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
## Golden Samples

Prefer looking at real code in this repo over inventing new patterns. Canonical reference files:

| Concern | Reference |
|---------|-----------|
| Provider implementation | `Classes/Provider/OpenAiProvider.php` |
| Feature service | `Classes/Service/Feature/CompletionService.php` |
| Unit test | `Tests/Unit/Service/Feature/CompletionServiceTest.php` |
| Functional test | `Tests/Functional/Service/UsageTrackerServiceTest.php` |
| Architecture test | `Tests/Architecture/ControllerLayerTest.php` |
| ADR format | `Documentation/Adr/Adr014AiPoweredWizardSystem.rst` |
| Backend controller | `Classes/Controller/Backend/ProviderController.php` |
| TCA form element | `Classes/Form/ModelIdElement.php` |
<!-- AGENTS-GENERATED:END examples -->

<!-- AGENTS-GENERATED:START help -->
## When Stuck
- Run tests: `./Build/Scripts/runTests.sh -s unit` (NEVER phpunit directly)
- Check ADRs in `Documentation/Adr/` for design rationale (20 ADRs)
- API docs: `Documentation/Api/` (9 reference pages)
- Issues: https://github.com/netresearch/t3x-nr-llm/issues
- Discussions: https://github.com/netresearch/t3x-nr-llm/discussions
<!-- AGENTS-GENERATED:END help -->
