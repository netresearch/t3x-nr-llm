<!-- Managed by agent: keep sections and order; edit content, not structure. Last Updated: 2026-08-19. Last verified: 2026-08-19 -->

# AGENTS.md — nr_llm

<!-- AGENTS-GENERATED:START overview -->
## Overview
TYPO3 v13.4/v14.3 extension providing a unified LLM provider abstraction layer. Supports OpenAI, Claude, Gemini, Groq, Mistral, Ollama, and OpenRouter through a standardized interface. PHP 8.2+ with PHPStan level 10. The authoritative version lives in `ext_emconf.php`.

**Three-tier architecture:** Providers (API connections) -> Models (per-provider) -> Configurations (use-case bundles). Component map and dependency rules: `docs/ARCHITECTURE.md`.
<!-- AGENTS-GENERATED:END overview -->

<!-- AGENTS-GENERATED:START precedence -->
## Precedence
The closest AGENTS.md wins: scoped AGENTS.md files in subdirectories override this file for their scope. This root file provides project-wide defaults.
<!-- AGENTS-GENERATED:END precedence -->

<!-- AGENTS-GENERATED:START scope-index -->
## Index of scoped AGENTS.md

| Directory | Scope |
|-----------|-------|
| `Classes/AGENTS.md` | PHP patterns, architecture rules, shared utilities, API-surface freeze |
| `Configuration/AGENTS.md` | TYPO3 TCA, services, caching, backend routes |
| `Documentation/AGENTS.md` | RST docs, ADRs, branding, version-prose surfaces |
| `Tests/AGENTS.md` | Testing patterns, coverage, test-runner environment traps |
| `Resources/AGENTS.md` | Fluid templates, XLIFF, icons, JS/CSS |
| `.ddev/AGENTS.md` | Local development environment, seed/demo data |
| `.github/workflows/AGENTS.md` | CI/CD workflows, release and dependency automation |

Architecture: `docs/ARCHITECTURE.md` · Execution plans: `docs/exec-plans/README.md`
<!-- AGENTS-GENERATED:END scope-index -->

<!-- AGENTS-GENERATED:START setup -->
## Setup
```bash
# Local development
ddev start && ddev composer install
```
<!-- AGENTS-GENERATED:END setup -->

<!-- AGENTS-GENERATED:START commands -->
## Commands (verified 2026-08-19)

ALWAYS use the Docker test runner; never invoke `phpunit` / `phpstan` / `rector` directly. See `Build/Scripts/runTests.sh` for the full list and `make help` for shortcuts.

| Task | Command |
|------|---------|
| Unit tests | `./Build/Scripts/runTests.sh -s unit` |
| Integration tests | `./Build/Scripts/runTests.sh -s integration` |
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

- Unit / Integration / Fuzzy / Functional / E2E suites — layout, runtime and environment traps in `Tests/AGENTS.md`.
- PHPUnit configs: `Build/phpunit.xml` (unit + integration + fuzzy; the unit suite also lists the two workflow tests under `Tests/E2E/`), `Build/FunctionalTests.xml` (functional + e2e-backend + e2e-tca). Every PHP test must sit in one of these suites — a directory in none of them runs in no job and reads as coverage (#658).
- Mutation: `infection.json.dist` (target MSI ≥ 70%). Architecture tests: `Tests/Architecture/` (phpat).
<!-- AGENTS-GENERATED:END testing -->

<!-- AGENTS-GENERATED:START development -->
## Development Workflow

1. **Specify before designing** for: a new feature, a new public API contract, a breaking or deprecating change, a security/credential topic, a change spanning several layers, a large compatibility rework ("usually" for a new provider; not for bugfixes, dependency updates, small refactors, docs). Write down what the change must do, what it explicitly does **not** do, and which suite proves each requirement — before choosing where the code goes. Every other gate here runs on a diff that already exists; this step is not machine-checked and cannot be. Keep it because it is the rule. Written specifications live in `specs/`, and what one must satisfy is stated in `.specify/memory/constitution.md` — both versioned, both readable without any tool. The Spec Kit CLI that generates the scaffolding is a workstation tool, not part of this repository: `uv tool install specify-cli`, then `specify init --here --integration <your-agent>` and `specify preset add --from https://github.com/netresearch/spec-kit-typo3/archive/refs/tags/v0.1.0.zip` (same for `spec-kit-typo3-llm`). Everything it writes is ignored, because it is generated, it pins one coding agent, and spec-kit records no recoverable origin for a preset (`"source": "local"` is hardcoded whatever the install path). Nothing checks that you ran it — the specification is the artifact, the tool is optional.
2. Branch off `main` (worktree convention — see project memory).
3. Use `make` shortcuts (`make test-unit`, `make phpstan`, `make cgl`) — they delegate to `runTests.sh`.
4. Pre-commit hooks via `Build/captainhook.json` (auto-installed by the captainhook composer plugin) run cgl + phpstan + commit-msg checks.
5. Sign commits with `git commit -S --signoff` (DCO required).
6. **Pre-push gate: `make gate`** — the six suites CI runs (cgl, phpstan, unit, fuzzy, rector `-n` pinned to PHP 8.2 as in CI, functional `-d sqlite`) plus the CHANGELOG check, as ONE command; invoking the six by hand is how `rector` and `fuzzy` get skipped. `make ci` is **not** this gate (older set: carries `integration`, omits `rector` and `functional`). Environment traps — stale `.Build`, the Rector PHP pin, the `platform_check.php` FATAL — are documented in `Tests/AGENTS.md`. Contract changes (setter clamps, validation ranges) have assertion twins in `Tests/Fuzzy/` — grep there before pushing.
7. PRs target `main`. CI matrix: PHP 8.2–8.5 × TYPO3 `^13.4` / `^14.3` — a local gate run covers ONE of those eight cells, so local green means "no known failure", not "CI will pass". PRs are merged via `--merge` strategy (preserves signatures). Release and dependency automation: `.github/workflows/AGENTS.md`.
<!-- AGENTS-GENERATED:END development -->

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
- **API keys MUST be stored as nr-vault UUID identifiers**, never as plaintext in TCA / yaml / env. `Documentation/Adr/Adr012ApiKeyEncryption.rst` records the application-level encryption this replaced and is `Superseded`; the nr-vault integration itself was decided by no ADR, which `Documentation/Adr/Index.rst` states explicitly.
- **No email addresses in public docs** — use the GitHub issues / discussions / security-advisories links only.
- **`CHANGELOG.md`**: append under the heading that already exists in `## [Unreleased]` — inserting before the first `### ` produces a duplicate heading. When merging `main` into a series of sibling branches, rebuild the section (take the incoming version verbatim, re-insert your own block) instead of trusting the merge. `composer ci:test:changelog` (pre-commit and CI) refuses the repeated result.
- **Changing the supported TYPO3/PHP range?** `VersionConsistencyTest` pins `composer.json`, `ext_emconf.php`, the `ci.yml` matrix and `Documentation/Api/SupportMatrix.rst` against each other — the UNCHECKED prose surfaces are listed in `Documentation/AGENTS.md`; update them in the same change.
<!-- AGENTS-GENERATED:END critical -->

<!-- AGENTS-GENERATED:START security -->
## Security
- API keys stored as nr-vault UUID identifiers (envelope encryption via nr-vault extension)
- Never log or expose API keys in error messages
- Sanitize user input before sending to LLM providers
- Treat LLM responses as untrusted content
- Security advisories: https://github.com/netresearch/t3x-nr-llm/security/advisories/new
<!-- AGENTS-GENERATED:END security -->

<!-- AGENTS-GENERATED:START help -->
## When Stuck
- Run tests: `./Build/Scripts/runTests.sh -s unit` (NEVER phpunit directly)
- Check ADRs in `Documentation/Adr/` for design rationale; `Adr/Index.rst` documents the record lifecycle
- API docs: `Documentation/Api/` · Component map: `docs/ARCHITECTURE.md`
- Issues: https://github.com/netresearch/t3x-nr-llm/issues
- Discussions: https://github.com/netresearch/t3x-nr-llm/discussions
<!-- AGENTS-GENERATED:END help -->
