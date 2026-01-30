# AGENTS.md

> AI agent guide for the `nr_llm` TYPO3 extension

## Project Overview

This is a TYPO3 v14 extension providing a unified LLM (Large Language Model) provider abstraction layer. It supports multiple AI providers (OpenAI, Claude, Gemini, etc.) through a standardized interface.

**Key characteristics:**
- PHP 8.5+ / TYPO3 v14
- PHPStan level 10 strict typing
- Three-tier architecture: Providers → Models → Configurations
- Encrypted API key storage (sodium_crypto_secretbox)

## Directory Structure

```
nr_llm/
├── Classes/                    # PHP source code
│   ├── Controller/Backend/     # Backend module controllers
│   ├── Domain/Model/           # Domain entities (Provider, Model, Configuration)
│   ├── Domain/Repository/      # Extbase repositories
│   ├── Provider/               # LLM provider adapters (OpenAI, Claude, Gemini...)
│   ├── Service/                # Business logic services
│   │   ├── Feature/            # High-level feature services (Vision, Translation...)
│   │   ├── Option/             # Request option DTOs
│   │   └── SetupWizard/        # Setup wizard services
│   └── Specialized/            # Specialized services (DeepL, Speech...)
├── Configuration/              # TYPO3 configuration
│   ├── Backend/                # Backend routes and modules
│   ├── TCA/                    # Table Configuration Array
│   └── TypoScript/             # TypoScript setup
├── Documentation/              # RST documentation (TYPO3 docs standard)
│   └── Adr/                    # Architecture Decision Records
├── Tests/
│   ├── Unit/                   # Unit tests (PHPUnit 12)
│   ├── Integration/            # Integration tests
│   ├── Functional/             # TYPO3 functional tests
│   ├── Fuzzy/                  # Property-based tests (Eris)
│   ├── E2E/                    # Playwright E2E tests
│   └── Architecture/           # PHPat architecture tests
├── Build/                      # Build configuration
│   ├── phpstan/                # PHPStan config
│   ├── rector/                 # Rector config
│   └── fractor/                # Fractor config
└── Resources/                  # Assets and templates
```

## Coding Standards

### PHP Style
- **PSR-12** with TYPO3 conventions via PHP-CS-Fixer
- **Strict types**: All files must have `declare(strict_types=1);`
- **Typed properties**: All class properties must be typed
- **Return types**: All methods must have return type declarations

### Architecture Rules (enforced by PHPat)
- Controllers must not depend on Repositories directly
- DTOs must be readonly and contain only typed properties
- Domain models in `Domain/Model/` are excluded from mutation testing

### Commit Messages
Conventional commits format enforced by GrumPHP:
```
feat|fix|docs|style|refactor|perf|test|build|ci|chore|revert|security(scope)?: message
```

## Testing Requirements

| Test Type | Location | Command | When Required |
|-----------|----------|---------|---------------|
| Unit | `Tests/Unit/` | `composer test:unit` | All new code |
| Integration | `Tests/Integration/` | `composer test:integration` | API interactions |
| Functional | `Tests/Functional/` | `composer test:functional` | TYPO3 integration |
| Fuzzy | `Tests/Fuzzy/` | `composer test:fuzzy` | Input validation |
| Mutation | - | `composer test:mutation` | Critical paths |
| E2E | `Tests/E2E/` | `npm run test:e2e` | UI workflows |

**Coverage requirements:**
- Minimum MSI: 70%
- Minimum Covered MSI: 74%

## Key Files

| File | Purpose |
|------|---------|
| `ext_emconf.php` | Extension metadata (auto-maintained by Rector) |
| `ext_localconf.php` | Extension bootstrap |
| `composer.json` | Dependencies (note: composer.lock NOT committed) |
| `phpunit.xml` | PHPUnit configuration |
| `infection.json.dist` | Mutation testing config |
| `grumphp.yml` | Pre-commit hooks |

## Provider Implementation

To add a new LLM provider:

1. Create class in `Classes/Provider/` extending `AbstractProvider`
2. Implement required interfaces (`VisionCapableInterface`, `StreamingCapableInterface`, etc.)
3. Add adapter type to `AdapterType` enum
4. Register via dependency injection
5. Add unit tests in `Tests/Unit/Provider/`
6. Add integration tests in `Tests/Integration/Provider/`

## Running Quality Checks

```bash
# All CI checks
composer ci

# Individual checks
composer lint          # PHP-CS-Fixer (dry-run)
composer phpstan       # Static analysis
composer test:unit     # Unit tests
composer rector:dry    # Rector (dry-run)

# Fix formatting
composer lint:fix
composer rector
```

## Security Considerations

- **API keys** are encrypted using `sodium_crypto_secretbox` with domain-separated key derivation
- **Never** log or expose API keys in error messages
- **Always** sanitize user input before sending to LLM providers
- **Treat** LLM responses as untrusted content

## CI/CD

Workflows in `.github/workflows/`:
- `ci.yml` - Main CI (lint, PHPStan, tests, Rector)
- `security.yml` - Security scanning (Gitleaks, dependency review, composer audit)
- `release.yml` - Release with SBOM, Cosign signing, SLSA attestation
- `scorecard.yml` - OpenSSF Scorecard
- `e2e.yml` - Playwright E2E tests
- `docs.yml` - Documentation rendering

## Useful Commands

```bash
# Development
ddev start                     # Start DDEV environment
composer install               # Install dependencies
composer ci                    # Run all CI checks

# Testing
composer test:unit             # Fast unit tests
composer test:mutation         # Mutation testing
npm run test:e2e               # Playwright E2E

# Maintenance
composer rector                # Apply Rector fixes
composer lint:fix              # Fix code style
.Build/bin/grumphp git:init    # Install git hooks
```

## Contact

- **Issues**: https://github.com/netresearch/t3x-nr-llm/issues
- **Security**: security@netresearch.de
- **Maintainer**: Netresearch DTT GmbH (typo3@netresearch.de)
