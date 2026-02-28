# Classes Directory

> Source code patterns for nr_llm TYPO3 extension

## Overview

PHP 8.2+ source code with strict typing, PSR-12 compliance, and PHPStan level 10.

## Architecture

```
Classes/
├── Controller/Backend/     # Backend module controllers + DTOs + Response objects
├── Domain/                 # Entities, repositories, enums, DTOs, value objects
├── Provider/               # LLM provider adapters (OpenAI, Claude, Gemini...)
├── Service/                # Business logic, feature services, setup wizard
├── Specialized/            # DeepL, speech, image generation services
├── DependencyInjection/    # Compiler passes
└── Exception/              # Custom exceptions
```

## Code Style

- `declare(strict_types=1);` required in ALL files
- All properties MUST be typed
- All methods MUST have return type declarations
- PSR-12 via PHP-CS-Fixer
- No `$GLOBALS` access allowed

## Patterns

### Provider Implementation

```php
// Extend AbstractProvider, implement capability interfaces
final class MyProvider extends AbstractProvider implements VisionCapableInterface
{
    public function chatCompletion(array $messages, ChatOptions $options): CompletionResponse
    {
        // Implementation
    }
}
```

### DTOs (Request/Response)

```php
// Readonly, typed properties only
final readonly class MyRequest
{
    public function __construct(
        public string $identifier,
        public int $limit = 10,
    ) {}
}
```

### Domain Models

- Extend `AbstractEntity` for Extbase persistence
- Domain models excluded from mutation testing
- No repository dependencies allowed (architecture rule)

## Architecture Rules (PHPat enforced)

1. Controllers must NOT depend on Repositories directly
2. Domain\Model must NOT depend on Domain\Repository
3. Domain\Model must NOT depend on Controller
4. DTOs must be readonly with typed properties only

## Security

- API keys encrypted via sodium_crypto_secretbox (Provider model)
- Input validation via typed DTOs
- Output treated as untrusted content

## Commands

```bash
composer ci:test:php:phpstan   # Static analysis (level 10)
composer ci:test:php:cgl       # PHP-CS-Fixer dry-run
composer ci:cgl                # Apply fixes
composer ci:test:php:rector    # Check for modernization opportunities
```
