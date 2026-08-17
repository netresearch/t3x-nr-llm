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

- Unit / Integration / Fuzzy / Functional / E2E suites — see `Tests/AGENTS.md` for layout details.
- PHPUnit configs: `Build/phpunit.xml` (unit + integration + fuzzy; the unit suite also lists the two workflow tests under `Tests/E2E/`), `Build/FunctionalTests.xml` (functional + e2e-backend + e2e-tca). Every PHP test must sit in one of these suites — a directory in none of them runs in no job and reads as coverage (#658).
- Mutation: `infection.json.dist` (target MSI ≥ 70%).
- Architecture tests: `Tests/Architecture/` (phpat) — enforce layered boundaries (Controller → Service → Provider).
<!-- AGENTS-GENERATED:END testing -->

<!-- AGENTS-GENERATED:START development -->
### Demo data for screenshots and manual testing

`ddev seed-ollama` creates 1 provider (Local Ollama), 3 models and 4 configurations; `ddev seed-tasks` creates 13 tasks across 4 categories (SQL in `.ddev/sql/`). `ddev install-v14` auto-runs seed-ollama but NOT seed-tasks — run both before documentation screenshots so populated views are visible.

### Functional suite runtime

The full `./Build/Scripts/runTests.sh -s functional` run includes ~34 provider-connection smoke tests that make REAL outbound HTTPS calls to unreachable providers (deliberate 502 mapping) — the full run takes ~35 min locally while per-class runs stay fast. Scope local runs to the touched test classes; leave the full matrix to CI.

## Development Workflow

1. **Specify before designing, for the classes of change below.** Write down what
   the change must do, what it explicitly does **not** do, and which suite proves
   each requirement — before choosing where the code goes. The format is not
   prescribed here; writing it at all is the rule.

   | Change | Specify first |
   |--------|---------------|
   | Bugfix, dependency update, small internal refactor, documentation only | no |
   | New provider | usually |
   | New feature, new public API contract, breaking or deprecating change, security or credential topic, a change spanning several layers, large compatibility rework | yes |

   Why this step exists as a rule: every other gate in this repository runs on a
   diff that already exists. `make gate`, the api-surface snapshot, PHPStan, the
   CHANGELOG check and the ADR checkbox all presuppose code. Nothing before this
   step checked anything, so a wrong premise about *what* was wanted survived
   until review, and a wrong premise about the public surface survived until
   `api-surface.txt` failed.

   **This one is not machine-checked, and cannot be here.** `ci:test:repo` sees
   the working tree, not the pull request; the job that could see one is
   `pr-quality`, which belongs to the shared `netresearch/.github` workflow and
   is not this repository's to extend. Keep it because it is the rule, not
   because something will catch you.
2. Branch off `main` (worktree convention — see project memory).
3. Use `make` shortcuts (`make test-unit`, `make phpstan`, `make cgl`) — they delegate to `runTests.sh`.
4. Pre-commit hooks via `Build/captainhook.json` (auto-installed by composer plugin) run cgl + phpstan + commit-msg checks.
5. Sign commits with `git commit -S --signoff` (DCO required).
6. **Pre-push gate: `make gate`.** It runs the six suites CI runs — `cgl`,
   `phpstan`, `unit`, `fuzzy`, `rector -n` (pinned to PHP 8.2, as in CI) and
   `functional -d sqlite` — plus the CHANGELOG check. Run it as one command:
   invoking the six by hand is how `rector` and `fuzzy` get skipped, which is
   then found by the CI matrix a push later.

   `make ci` is **not** this gate. It is an older set that carries `integration`
   and omits `rector` and `functional`; both targets are kept because they
   answer different questions.

   Contract changes (setter clamps, validation ranges) have assertion twins in
   `Tests/Fuzzy/` — grep there before pushing.
7. PRs target `main`. CI matrix: PHP 8.2–8.5 × TYPO3 `^13.4` / `^14.3`; merged via `--merge` strategy (preserves signatures).
<!-- AGENTS-GENERATED:END development -->

<!-- AGENTS-GENERATED:START filemap -->
## File Map

### Key Files

| File | Purpose |
|------|---------|
| `ext_emconf.php` | Extension metadata and the authoritative version |
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

Three-tier model: **Provider → Model → Configuration**. See `Documentation/Adr/Adr013ThreeLevelConfigurationArchitecture.rst` for the design rationale and `Classes/AGENTS.md` for adapter contracts.

### Directory Structure
```
nr_llm/
├── Classes/                    # PHP source
│   ├── Attribute/              # #[AsLlmProvider] auto-registration attribute
│   ├── Controller/Backend/     # Backend controllers, DTOs, Response objects
│   ├── DependencyInjection/    # Compiler passes (ProviderCompilerPass)
│   ├── Domain/                 # Entities, repositories, enums, DTOs, value objects
│   ├── Exception/              # Core domain exceptions
│   ├── Form/                   # TCA form elements (ModelIdElement, ModelConstraintsWizard)
│   ├── Provider/               # 7 LLM adapters + Contract interfaces + exceptions
│   ├── Service/                # Feature services, wizard, options, fallback chain
│   ├── Specialized/            # DeepL, speech (Whisper/TTS), image (DALL-E/FAL)
│   ├── Utility/                # SafeCastTrait, ErrorMessageSanitizerTrait
│   └── Widgets/DataProvider/   # Backend dashboard widgets (cost, requests)
├── Configuration/              # TYPO3 config (TCA, services, caching, icons, routes)
├── Documentation/              # RST docs + guides.xml + brand assets
│   └── Adr/                    # Architecture Decision Records
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
- **API keys MUST be stored as nr-vault UUID identifiers**, never as plaintext in TCA / yaml / env. `Documentation/Adr/Adr012ApiKeyEncryption.rst` records the application-level encryption this replaced and is `Superseded`; the nr-vault integration itself was decided by no ADR, which `Documentation/Adr/Index.rst` states explicitly.
- **No email addresses in public docs** — use the GitHub issues / discussions / security-advisories links only.
<!-- AGENTS-GENERATED:END critical -->

<!-- AGENTS-GENERATED:START heuristics -->
## Heuristics — Quick Decisions

- **Where does the new feature service live?** `Classes/Service/Feature/` (one directory per feature, e.g. `Completion`, `Embedding`, `Translation`). Each feature has a service + DTO + tests.
- **Adding a new LLM provider?** Implement `Classes/Provider/Contract/ProviderInterface`, add `#[AsLlmProvider(priority: ...)]` attribute (auto-registers via `ProviderCompilerPass`), and return the provider identifier from `ProviderInterface::getIdentifier()`. Add the provider icon to `Resources/Public/Icons/provider-<identifier>.svg`.
- **Where does TCA live?** Per-table file under `Configuration/TCA/` for new tables; `Configuration/TCA/Overrides/` to extend existing tables (incl. `pages`, `tt_content`).
- **Stuck on a "this works locally but breaks in CI" issue?** Reproduce inside `Build/Scripts/runTests.sh -s <suite>` first — it uses the same Docker PHP image as CI.
- **Adding a config option?** TCA + `LLL:` translation key in `Resources/Private/Language/locallang*.xlf` for both EN and DE.
- **Touching the public surface?** Add an ADR under `Documentation/Adr/`. Format: `Adr<N>Description.rst`. It lands **before** the implementation PR, not inside it — the decision is what the implementation follows from, and `api-surface.txt` already tells you when you are touching the surface, so there is nothing to wait for. `AdrReferenceIntegrityTest` refuses a reference to a record that does not exist; that the record arrived first is not machine-checked.
- **Changing an `@api` signature?** `Tests/Unit/Api/api-surface.txt` freezes it, constructors included — the class's own, or the one it inherits from a base inside `Netresearch\NrLlm`; one inherited from TYPO3 core or the SPL is left out because it differs across the version matrix. The failure says whether the diff is additive (regenerate + `### Added`) or breaking (decide first). Removals follow `Documentation/Api/Deprecation.rst`, whose inventory is asserted against the `@deprecated` docblocks in both directions.
- **Changing the supported TYPO3 / PHP range?** `VersionConsistencyTest` pins four surfaces against each other — `composer.json`, `ext_emconf.php`, the `ci.yml` matrix and `Documentation/Api/SupportMatrix.rst` — and fails on the first of those you forget. It does **not** see the prose: `README.md` (the two badges and the Requirements list), `Documentation/Installation/Index.rst`, `Documentation/Introduction/Index.rst`, `Documentation/Developer/FeatureServices/Index.rst`, `Documentation/Testing/CiConfiguration.rst` (a hand-copied matrix excerpt) and `Documentation/Developer/IntegrationGuide.rst` (the TER constraint in its `ext_emconf.php` example) repeat the same range with nothing checking them. Update those by hand in the same change — and grep for the old floor before you finish, because that list is what has been found, not a guarantee. `BASELINE.md`'s "Multi-version CI" row is the one prose surface that IS asserted, by `Tests/Unit/BaselineConsistencyTest`.
- **Linking between backend controllers?** Use the full Extbase alias `Backend\<Name>` (e.g. `'controller' => 'Backend\\TaskWizard'`), not the short name — `resolveControllerAliasFromControllerClassName()` keeps the segment after `Controller\`, so a short alias yields an empty URL / `InvalidControllerNameException`. Namespaced backend arguments are OFF here, so use bare `controller`/`action` keys (NOT `tx_nrllm_task[...]`; the bot's suggested namespaced form is wrong for this instance). Introduced by ADR-027's TaskController split.
<!-- AGENTS-GENERATED:END heuristics -->

<!-- AGENTS-GENERATED:START utilities -->
## Shared Utilities — Don't Reinvent

- **Type coercion**: `Classes/Utility/SafeCastTrait` exposes private helpers (`toStr`, `toInt`, `toFloat`) for internal coercion when raw values come from untrusted sources. Use them inside the trait consumer; do not invent `safeIntCast`-style public methods.
- **Error-message sanitizing**: `Classes/Utility/ErrorMessageSanitizerTrait::sanitizeErrorMessage()` strips secret-bearing query parameters (`?key=`, `?token=`, …) before a message is logged or surfaced. Use the trait; do not copy the regex.
- **Provider invocation**: `Classes/Domain/DTO/FallbackChain.php` defines fallback chains; `Classes/Provider/Middleware/FallbackMiddleware.php` enforces them at runtime. Always go through the middleware pipeline rather than calling provider classes directly — it handles retries, fallback ordering, and error mapping.
- **Cost tracking**: `Classes/Provider/Middleware/UsageMiddleware.php` records usage after each successful provider call via `UsageTrackerServiceInterface::trackUsage()`. Don't write to the usage table directly.
- **Cache config**: `Configuration/Caching.php` declares the `nrllm_responses` cache. Add new caches there (no hardcoded backend — let the host instance configure Redis/Valkey/Memcached).
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
| `auto-merge-deps.yml` | Auto-merge dependency PRs |
| `check-template-drift.yml` | Template drift against the org's typo3-extension template |
| `checks.yml` | Security, gitleaks, zizmor, fuzz, licence audit, CodeQL, scorecard, dependency review, PR quality |
| `ci.yml` | Lint, PHPStan, unit/functional tests, Rector, fuzz + weekly mutation, docs |
| `community.yml` | Community health |
| `dco.yml` | DCO sign-off |
| `docs.yml` | Documentation rendering |
| `e2e.yml` | Playwright E2E tests |
| `labeler.yml` | PR auto-labelling |
| `pages.yml` | Landing page (GitHub Pages) |
| `release.yml` | Release with SBOM, Cosign signing, SLSA attestation; publishes TER + Packagist |
| `republish.yml` | Re-publish an existing tag (`workflow_dispatch` fallback) |
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
| TCA form element | `Classes/Form/Element/ModelIdElement.php` |
<!-- AGENTS-GENERATED:END examples -->

<!-- AGENTS-GENERATED:START help -->
## When Stuck
- Run tests: `./Build/Scripts/runTests.sh -s unit` (NEVER phpunit directly)
- Check ADRs in `Documentation/Adr/` for design rationale; `Adr/Index.rst` documents the record lifecycle
- API docs: `Documentation/Api/`
- Issues: https://github.com/netresearch/t3x-nr-llm/issues
- Discussions: https://github.com/netresearch/t3x-nr-llm/discussions
<!-- AGENTS-GENERATED:END help -->

<!-- Hand-maintained; intentionally outside the AGENTS-GENERATED blocks above. -->
## Release & dependency automation (agent notes)

- **Releasing is a tag push, and the tag publishes TER automatically.** Pushing an annotated signed tag `vX.Y.Z` on `main` triggers `release.yml`, which calls the reusable `netresearch/typo3-ci-workflows/.github/workflows/release-typo3-extension.yml` with `TYPO3_TER_ACCESS_TOKEN` wired in: it produces the SBOM / Cosign / SLSA artifacts, creates the GitHub release, **and publishes to TER + Packagist**. `republish.yml` is only a `workflow_dispatch` fallback that re-publishes an existing tag — it is *not* the primary path and does not need to be run for a normal release. Verify a release by checking Packagist (`repo.packagist.org/p2/netresearch/nr-llm.json`, tags are `v`-prefixed), TER (`extensions.typo3.org/api/v1/extension/nr_llm/versions`) and the docs 0.X URL — do not assume TER is a separate manual step.
- **The `chore(release): X.Y.Z` commit bumps four files:** `ext_emconf.php`, `composer.json` (`extra.typo3/cms.version`), `Documentation/guides.xml`, and `CHANGELOG.md` (not `Changelog.rst`). Version bumps belong to this release flow, never to a feature PR.
- **Renovate/Dependabot PRs auto-merge via `auto-merge-deps.yml` — do not hand-merge them by default.** Known gap (2026-07-24): `auto-merge-deps.yml` did **not** auto-merge #511/#509 and they were admin-merged manually to unblock the 0.24.0 release; root cause (merge-queue interaction vs an unmet workflow condition) is **not yet verified**. Investigate the workflow's condition/queue behaviour before relying on it for the next release rather than reflexively hand-merging.

<!-- Hand-maintained; intentionally outside the AGENTS-GENERATED blocks above. -->
## Working in this repo (agent notes)

- **Merge-queue stall nudge**: a CLEAN PR with armed auto-merge that never enters the queue (`isInMergeQueue: false`) usually just needs a re-evaluation — `gh pr merge --disable-auto` followed by `gh pr merge --auto`. Diagnose before escalating; admin-bypassing the queue is not the fix.


- **`CHANGELOG.md`: check whether the section already exists before inserting one.**
  `## [Unreleased]` usually already carries `### Added`, `### Changed`, `### Fixed`
  and `### Removed`. Inserting before the *first* `### ` after `## [Unreleased]`
  produces a duplicate heading — the entry lands in a second `### Changed` while
  the original sits forty lines below. Find the matching heading and append under
  it; only create one when it genuinely is not there. (Three duplicates in one
  session, 2026-07-30.)

  **Merging `main` into a series of sibling branches duplicates the whole
  section.** Any resolution that assumes "ours holds only my additions" is
  right for the first merge of a series and wrong for every later one — by then
  "ours" already carries what the previous merge brought in. No conflict
  markers, plausible diff: on 2026-08-10 ten bullets ended up standing three and
  four times over and two PRs carried it to `main`. Rebuild the section instead
  of merging it — take the incoming version verbatim and re-insert your own
  block under the matching heading. `composer ci:test:changelog` (pre-commit,
  and its own CI job) refuses the repeated result.

- **This repository defines no workflow jobs of its own.** Every workflow calls
  a shared `netresearch/*` reusable workflow, so action pinning, runner
  hardening and security review happen once there — for all thirty callers. The
  single exception is the aggregate `gate` job in `checks.yml`, which evaluates
  the other jobs' results and therefore cannot be a reusable-workflow call.

  A **repo-specific check** goes in the shared workflow's `repo-checks` job, not
  in a job here: set `run-repo-checks: true` in `ci.yml` and add the command to
  the `ci:test:repo` composer script. That script is also what pre-commit runs,
  so the local and CI halves are the same command.

  Why this is a rule and not a preference: a local job carries its own action
  pins, and a wrong pin does not fail like a normal check. Repo and org both set
  `sha_pinning_required`, so a tag ref kills the run at `Set up job` — before any
  step executes, so the log has no step output — and zizmor, Opengrep, CodeQL
  and SonarCloud then each flag the same lines. On 2026-08-11 that was six red
  checks for one mistake, none of them naming the cause.
  `composer ci:test:workflows` (pre-commit) refuses a job with local steps.

- **A fresh worktree needs its own dependency resolution.** `.Build/` is not
  tracked, so a new worktree has none; copying it from `main/.Build` is the usual
  shortcut and avoids the WSL2 segfault on a fresh composer resolve. But that copy
  can be OLDER than `main`'s code — after a dependency bump lands, PHPStan aborts
  with an internal error such as `Interface "Netresearch\NrVault\Crypto\
  ForeignEnvelopeRotatorInterface" not found` while analysing an unrelated test.
  That is a stale `.Build`, not a code defect: run
  `./Build/Scripts/runTests.sh -s composerUpdate -p 8.4` and re-run the gate.

- **Run the Rector gate with `-p 8.2`, never `-p 8.4`.** Rector's PHPUnit rules
  activate from the phpunit version composer *installed*, not from the set named
  in `Build/rector/rector.php`. PHP 8.2 resolves phpunit ^11, 8.4 resolves ^13,
  and the 13-only migrations do not apply to a codebase whose blocking matrix
  caps `phpunit/phpunit:<13`. CI pins the job with `rector-php-version: '8.2'`
  in `ci.yml` and says so in a comment there. Running it on 8.4 reports dozens of
  files CI will never flag — and applying those "fixes" breaks the blocking
  matrix with `Call to an undefined method
  expectExceptionMessageIsOrContains()`. Observed 2026-08-04: a 53-file Rector
  run taken at face value produced a PR that turned 8 PHPStan cells and several
  test cells red; a control on unmodified `main` with an uncapped local resolve
  reproduced the same 53 files, proving the finding was the environment, not the
  code.

  **And in a worktree it may not run at all.** `composer install` writes
  `.Build/vendor/composer/platform_check.php` from the PHP it resolved under,
  and that file FATALS rather than warns on an older runtime — so a `.Build`
  resolved at 8.4 makes the pinned `-p 8.2` die with `Composer detected issues
  in your platform: … require a PHP version ">= 8.4.1". You are running
  8.2.30.` The suite did not fail; it never started. `make gate` names that
  case explicitly. Recognise the message as an environment mismatch, then
  either keep a second `.Build` resolved at 8.2 for this one gate or accept CI
  as the only place it runs — and **say so** when reporting which gates were
  run, rather than listing it as green.

- **A local gate run covers one matrix cell; CI covers eight.** The six pre-push
  suites run against a single PHP version and a single TYPO3 constraint. CI runs
  PHP 8.2–8.5 × TYPO3 13.4 / 14.3. A PHPStan finding can therefore be invisible
  locally and red across all eight legs — a `willReturnCallback` whose closure type
  resolves differently under the PHPUnit version a given cell installs is the
  observed case. Green locally means "no reason to push a known failure", not
  "CI will pass".
