<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-08-19 -->

# AGENTS.md — Tests

<!-- AGENTS-GENERATED:START overview -->
## Overview
Comprehensive test suite: PHPUnit 11/12/13 (cross-compatible), TYPO3 Testing Framework, PHPat architecture tests, Eris property tests, Infection mutation tests, Playwright E2E. All run via Docker-based `runTests.sh`.
<!-- AGENTS-GENERATED:END overview -->

<!-- AGENTS-GENERATED:START setup -->
## Setup
```bash
# ALWAYS use runTests.sh — NEVER run phpunit directly
./Build/Scripts/runTests.sh -s unit              # Unit tests
./Build/Scripts/runTests.sh -s integration       # Integration tests
./Build/Scripts/runTests.sh -s functional        # Functional tests
./Build/Scripts/runTests.sh -s functional -d mariadb  # With MariaDB
./Build/Scripts/runTests.sh -s fuzzy             # Property-based tests
./Build/Scripts/runTests.sh -s mutation          # Mutation testing
./Build/Scripts/runTests.sh -s architecture      # PHPat layer tests
ddev e2e                                         # Playwright E2E (supplies TYPO3_BASE_URL)
./Build/Scripts/runTests.sh -s unitCoverage      # Unit with coverage
./Build/Scripts/runTests.sh -p 8.3               # Pin a PHP version. Omit it unless you have a
                                                 # named reason: the default is derived from
                                                 # composer.json and is the highest supported
                                                 # version the constraint allows (8.5 here).
```
<!-- AGENTS-GENERATED:END setup -->

<!-- AGENTS-GENERATED:START filemap -->
## Build/Tests

| Directory | Framework | Purpose |
|-----------|-----------|---------|
| `Unit/` | PHPUnit 11/12/13 | Fast isolated unit tests |
| `Integration/` | PHPUnit + PSR-18 mocking | API client tests |
| `Functional/` | TYPO3 Testing Framework | Database, repositories, controllers |
| `Architecture/` | PHPat | Layer boundary enforcement |
| `Fuzzy/` | Eris | Property-based/fuzz testing |
| `E2E/Backend/` | PHPUnit | Backend E2E tests |
| `E2E/TCA/` | PHPUnit | TCA field tests |
| `E2E/Playwright/` | Playwright (TS) | Browser-based UI tests |

### Functional suite runtime

The full `./Build/Scripts/runTests.sh -s functional` run includes ~34 provider-connection smoke tests that make REAL outbound HTTPS calls to unreachable providers (deliberate 502 mapping) — the full run takes ~35 min locally while per-class runs stay fast. Scope local runs to the touched test classes; leave the full matrix to CI.

### Runner environment traps (worktrees, Rector, matrix)

- **A fresh worktree needs its own dependency resolution.** `.Build/` is not tracked; copying it from `main/.Build` is the usual shortcut (avoids the WSL2 segfault on a fresh composer resolve), but the copy can be OLDER than `main`'s code — after a dependency bump, PHPStan aborts with an internal error like `Interface "…ForeignEnvelopeRotatorInterface" not found` while analysing an unrelated test. That is a stale `.Build`, not a code defect: run `./Build/Scripts/runTests.sh -s composerUpdate` and re-run the gate. **Without `-p`** — the runner derives the default from `composer.json` and picks the highest supported version the constraint allows, which is 8.5 here. Pinning a lower one for no reason is what creates the `platform_check` collision described two entries below.
- **What decides the Rector findings is the INSTALLED phpunit, not `-p`.** `-p` only chooses which phpunit `composer` resolves; the rule set follows what is in `vendor/`. `PHPUnitSetList::COMPOSER_BASED` binds the rules to whatever sits in `vendor/`. Measured on ONE tree in ONE container (PHP 8.5), swapping only the installed phpunit: **13.3.1 reports 59 files** — `RenameMethodRector` (54), `AllowMockObjectsForDataProviderRector` (11) — and **11.5.56 reports 13 files**, all `IfToNullCoalescingAssignRector`, a plain PHP rule. Under phpunit 11 not one phpunit-bound rule fires. Re-running that same 11.5.56 build in the 8.2 container gives the identical 13, so the container's PHP is not the variable. So `-p 8.2` on a tree whose `vendor/` was resolved at the default buys nothing: either `platform_check` aborts it, or it runs the phpunit-13 rules anyway. You need both — resolve under 8.2 (`-s composerUpdate -p 8.2`) AND run under it.
- **A default-PHP Rector count is unclassified, not clean, and not noise.** The obvious next thought once the 59 above are explained is "the gate is noise, skip it". It is not. The correctly resolved run in that same experiment was not empty either — 13 findings from a PHP rule the phpunit-13 run never reported, so the two sets do not even overlap and neither is a subset of the other; why the PHP rule is invisible under the default resolution is not established here. On #906 the same shape left exactly one finding under a correctly resolved 8.2 run, and that one was genuine and had to be fixed. Read the rule names, not the count.
- **Run the Rector gate in the LOWEST matrix cell — `-p 8.2` — and nowhere else.** This is not a rule about old PHP, and reading it that way sends you to 8.5. `Build/rector/rector.php` loads `PHPUnitSetList::COMPOSER_BASED`, whose rules bind to the phpunit version composer *installed*, so the PHP version only decides which phpunit that is: 8.2 resolves phpunit 11, 8.3 resolves 12, and **8.4 and 8.5 both resolve 13**. The blocking matrix still runs an 8.2 leg on phpunit 11, so Rector has to reason under 11 or it proposes API that leg cannot compile — `expectExceptionMessage` → `expectExceptionMessageIsOrContains` is bound to `phpunit/phpunit >=13.2`, and every `expectExceptionMessage()` call in the suite would be rewritten to a method phpunit 11 does not have. CI pins the job with `rector-php-version: '8.2'` in `ci.yml`. Running it on 8.4 reports dozens of files CI will never flag — applying those "fixes" breaks the blocking matrix with `Call to an undefined method expectExceptionMessageIsOrContains()` (observed 2026-08-04; a control run on unmodified `main` reproduced the identical finding list, proving the finding was the environment, not the code).
- **And in a worktree it may not run at all.** `composer install` writes `.Build/vendor/composer/platform_check.php` from the PHP it resolved under, and that file FATALS on an older runtime — a `.Build` resolved at the default (8.5, or anything above 8.2) makes the pinned `-p 8.2` die with `Composer detected issues in your platform: … require a PHP version ">= 8.4.1"`. The suite did not fail; it never started. `make gate` names that case explicitly. Either keep a second `.Build` resolved at 8.2 for this one gate or accept CI as the only place it runs — and **say so** when reporting which gates were run. The floor is set by `phpunit/phpunit` 13.3.1 and six `symfony/*` v8.1 components (`cache`, `clock`, `event-dispatcher`, `stopwatch`, `type-info`, `var-exporter`), which all declare `php >=8.4.1`; `doctrine/instantiator` 2.1.0 looks like the culprit and is not — its `^8.4` would give 8.4.0. Naming them saves the next reader from hunting a tool bug: it is an ordinary dependency floor, and `-s composerUpdate -p 8.2` in a second worktree resolves phpunit 11.5.56 instead.
- **A local gate run covers one matrix cell; CI covers eight** (PHP 8.2–8.5 × TYPO3 13.4/14.3). A PHPStan finding can be invisible locally and red across all eight legs — e.g. a `willReturnCallback` closure type that resolves differently under another PHPUnit version. Green locally means "no reason to push a known failure", not "CI will pass".
- **"Works locally but breaks in CI"?** Reproduce inside `./Build/Scripts/runTests.sh -s <suite>` first — it uses the same Docker PHP image as CI.
<!-- AGENTS-GENERATED:END filemap -->

<!-- AGENTS-GENERATED:START code-style -->
## Code style
- PHPUnit attributes: `#[Test]`, `#[CoversClass(...)]`, `#[DataProvider(...)]`
- PHPUnit 11/12/13 cross-compatibility: use `#[CoversNothing]` for enums/exceptions
- `failOnWarning=true` in phpunit.xml
- One resource per test: never share fixtures between tests
- CI is authoritative: local DDEV for debugging only
<!-- AGENTS-GENERATED:END code-style -->

<!-- AGENTS-GENERATED:START coverage -->
## Coverage Requirements
- MSI target: 70% — a goal, not a gate. Mutation runs weekly and report-only (`run-mutation-tests` is gated on `schedule` in `ci.yml`, and the Infection step is `continue-on-error`).
- Covered MSI target: 74%
- `Domain\Model` excluded from mutation testing
- Use `assert(isset($result['key']))` for PHPStan array narrowing (not `assertArrayHasKey`)
<!-- AGENTS-GENERATED:END coverage -->

<!-- AGENTS-GENERATED:START security -->
## Security
- Never use real API keys in tests
- Mock HTTP clients for integration tests
- Functional test fixtures use CSV datasets
<!-- AGENTS-GENERATED:END security -->

<!-- AGENTS-GENERATED:START checklist -->
## PR Checklist
- [ ] New code has unit tests
- [ ] API interactions have integration tests
- [ ] TYPO3 features have functional tests
- [ ] Architecture rules pass
- [ ] Mutation testing MSI >= 70%
- [ ] Tests run via `runTests.sh`, not phpunit directly
<!-- AGENTS-GENERATED:END checklist -->

<!-- AGENTS-GENERATED:START examples -->
## Examples
> **Look at real tests:**
> - Unit: `Unit/Service/Feature/CompletionServiceTest.php`
> - Functional: `Functional/Repository/` (CSV fixtures in `Functional/Fixtures/`)
> - Architecture: `Architecture/ControllerLayerTest.php`
> - E2E (Playwright): `E2E/Playwright/wizard.spec.ts`
> - E2E (PHP): `E2E/Backend/SetupWizardE2ETest.php`
<!-- AGENTS-GENERATED:END examples -->

<!-- AGENTS-GENERATED:START help -->
## When Stuck
- Test docs: `Documentation/Testing/` — UnitTesting, FunctionalTesting, EndToEndTesting, CiConfiguration
- PHPUnit 11/12/13 compat notes: `Documentation/Testing/CiConfiguration.rst`
- Run with `-v` for verbose: `./Build/Scripts/runTests.sh -s unit -v`
<!-- AGENTS-GENERATED:END help -->
