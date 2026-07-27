# Contributing to nr_llm

Thank you for your interest in contributing to the TYPO3 LLM extension!

## Development Setup

### Prerequisites

- PHP 8.2+
- [DDEV](https://ddev.readthedocs.io/) for local development
- Composer
- Node.js 20+ (for E2E tests)

### Getting Started

```bash
# Clone the repository
git clone https://github.com/netresearch/t3x-nr-llm.git
cd t3x-nr-llm

# Start DDEV
ddev start

# Install dependencies
ddev composer install

# Run tests
ddev exec ".Build/bin/phpunit -c Build/phpunit.xml"
```

## Code Quality

Before submitting a PR, ensure all checks pass:

```bash
# Code style, PHPStan, unit, integration and fuzzy tests.
# Rector and the functional tests are not part of this script — CI runs those
# too, so run them individually before pushing.
ddev exec "composer ci"

# Or run individually:
ddev exec ".Build/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run"
ddev exec ".Build/bin/phpstan analyse -c Build/phpstan/phpstan.neon"
ddev exec ".Build/bin/phpunit -c Build/phpunit.xml"
ddev exec ".Build/bin/rector process --config=Build/rector/rector.php --dry-run"
```

## Testing Requirements

**All contributions MUST include appropriate tests.**

| Change Type | Required Tests |
|-------------|----------------|
| New feature | Unit tests + Integration tests |
| Bug fix | Regression test (proves the fix) |
| Refactoring | Existing tests must pass |
| New provider | Unit tests + Integration tests |
| API changes | Update affected tests |

### Test Types

```bash
# Unit tests
composer ci:test:php:unit

# Integration tests
composer ci:test:php:integration

# Functional tests (requires DDEV)
ddev exec "composer ci:test:php:functional"

# Fuzzy/Property-based tests
composer ci:test:php:fuzzy

# Mutation tests (code quality)
composer ci:test:php:mutation

# E2E tests (requires DDEV + Playwright)
npm run test:e2e
```

### Coverage Requirements

- New code should have reasonable test coverage
- Critical paths (security, API calls) require high coverage
- Run `composer test -- --coverage-html=coverage` to view coverage report

## Security Guidelines

- **Never commit secrets** (API keys, passwords, tokens)
- **Escape all output** - use `htmlspecialchars()` for HTML, Fluid auto-escaping
- **Validate all input** - use DTOs with type-safe extraction
- **Use parameterized queries** - Extbase Query or QueryBuilder
- **Report vulnerabilities** privately via [GitHub Security Advisories](https://github.com/netresearch/t3x-nr-llm/security/advisories/new)

See [SECURITY.md](SECURITY.md) for vulnerability reporting.

## Pull Request Process

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Make your changes
4. **Write/update tests** (required)
5. Ensure all tests pass
6. Commit using [conventional commits](https://www.conventionalcommits.org/)
7. Push and open a Pull Request

### PR Checklist

- [ ] Tests added/updated
- [ ] All CI checks pass
- [ ] Documentation updated (if applicable)
- [ ] `CHANGELOG.md` entry added under `## [Unreleased]`
- [ ] ADR added under `Documentation/Adr/` if the public surface changed
- [ ] No secrets committed
- [ ] Follows existing code style

### Changelog and ADRs

Every change that a consumer or an integrator can notice gets a `CHANGELOG.md`
entry under `## [Unreleased]`, in the Keep a Changelog section that fits
(`Added` / `Changed` / `Deprecated` / `Removed` / `Fixed` / `Security`).
Version bumps belong to the release commit, never to a feature PR.

A change to the public surface also gets an Architecture Decision Record under
`Documentation/Adr/`, named `Adr<N><Description>.rst` — take the next free
number and follow the format of an existing one. Add the file name to the
`toctree` at the bottom of `Documentation/Adr/Index.rst` as well; that toctree
is explicit, so an unregistered ADR renders as an orphan page nothing links to.
Public surface means anything
a consumer builds against: an interface, a DI-tagged extension point, a database
table, a TCA field, an Extension Configuration key, a console command, or the
behaviour of any of them. Recording *why* matters more than recording what; the
diff already shows what.

## Commit Messages

We use conventional commits:

- `feat:` New features
- `fix:` Bug fixes
- `docs:` Documentation changes
- `refactor:` Code refactoring
- `test:` Test changes
- `chore:` Maintenance tasks
- `security:` Security fixes

## Adding a New Provider

1. Create a new class extending `AbstractProvider`
2. Implement required methods
3. Add unit tests in `Tests/Unit/Provider/`
4. Add integration tests in `Tests/Integration/Provider/`
5. Update documentation in `Documentation/`
6. Add to TCA adapter type options

## Code Review

All PRs require review by a code owner before merging. Reviews focus on:

- Code quality and maintainability
- Test coverage and quality
- Security considerations
- Documentation completeness

## Questions?

Open an issue or start a discussion!
