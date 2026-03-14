<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-03-14 -->

# AGENTS.md — Tests

<!-- AGENTS-GENERATED:START overview -->
## Overview
Comprehensive test suite: PHPUnit 11/12 (cross-compatible), TYPO3 Testing Framework, PHPat architecture tests, Eris property tests, Infection mutation tests, Playwright E2E. All run via Docker-based `runTests.sh`.
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
./Build/Scripts/runTests.sh -s e2e               # Playwright E2E
./Build/Scripts/runTests.sh -s unitCoverage      # Unit with coverage
./Build/Scripts/runTests.sh -p 8.3               # Specify PHP version
```
<!-- AGENTS-GENERATED:END setup -->

<!-- AGENTS-GENERATED:START filemap -->
## Test Structure

| Directory | Framework | Purpose |
|-----------|-----------|---------|
| `Unit/` | PHPUnit 11/12 | Fast isolated unit tests |
| `Integration/` | PHPUnit + PSR-18 mocking | API client tests |
| `Functional/` | TYPO3 Testing Framework | Database, repositories, controllers |
| `Architecture/` | PHPat | Layer boundary enforcement (3 test files) |
| `Fuzzy/` | Eris | Property-based/fuzz testing |
| `E2E/Backend/` | PHPUnit | Backend E2E tests (9 test files) |
| `E2E/TCA/` | PHPUnit | TCA field tests |
| `E2E/Playwright/` | Playwright (TS) | Browser-based UI tests (10 spec files) |
<!-- AGENTS-GENERATED:END filemap -->

<!-- AGENTS-GENERATED:START code-style -->
## Test Conventions
- PHPUnit attributes: `#[Test]`, `#[CoversClass(...)]`, `#[DataProvider(...)]`
- PHPUnit 11/12 cross-compatibility: use `#[CoversNothing]` for enums/exceptions
- `failOnWarning=true` in phpunit.xml
- One resource per test: never share fixtures between tests
- CI is authoritative: local DDEV for debugging only
<!-- AGENTS-GENERATED:END code-style -->

<!-- AGENTS-GENERATED:START coverage -->
## Coverage Requirements
- Minimum MSI: 70%
- Minimum Covered MSI: 74%
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
- Test docs: `Documentation/Testing/` (4 pages: Unit, Functional, E2E, CI)
- PHPUnit 11/12 compat: see `MEMORY.md` PHPUnit section
- Run with `-v` for verbose: `./Build/Scripts/runTests.sh -s unit -v`
<!-- AGENTS-GENERATED:END help -->
