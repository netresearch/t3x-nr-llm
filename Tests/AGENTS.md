# Tests Directory

> Testing patterns for nr_llm TYPO3 extension

## Overview

Comprehensive test suite using PHPUnit 12, TYPO3 Testing Framework, PHPat, Eris, and Infection.

## Test Structure

```
Tests/
├── Unit/                   # Fast, isolated unit tests
├── Integration/            # API client tests with mocking
├── Functional/             # TYPO3 functional tests (database)
├── Architecture/           # PHPat layer enforcement tests
├── Fuzzy/                  # Property-based tests (Eris)
└── E2E/                    # Playwright browser tests
```

## Test Types

| Type | Framework | When to Use |
|------|-----------|-------------|
| Unit | PHPUnit 12 | Pure logic, validators, DTOs |
| Integration | PHPUnit + PSR-18 mocking | HTTP clients, API responses |
| Functional | TYPO3 Testing Framework | Database, repositories, controllers |
| Architecture | PHPat | Layer boundaries, dependency rules |
| Fuzzy | Eris | Input validation, edge cases |
| Mutation | Infection | Test quality verification |
| E2E | Playwright | UI workflows, accessibility |

## Running Tests

```bash
composer ci:test:php:unit          # Unit tests only
composer ci:test:php:integration   # Integration tests
composer ci:test:php:functional    # Functional tests (requires DDEV or SQLite)
composer ci:test:php:fuzzy         # Property-based tests
composer ci:test:php:mutation      # Mutation testing (MSI >= 70%)
npm run test:e2e            # Playwright E2E tests
composer ci                 # All CI checks
```

## Coverage Requirements

- Minimum MSI: 70%
- Minimum Covered MSI: 74%
- Domain\Model excluded from mutation testing

## Patterns

### Unit Test

```php
#[Test]
public function completionResponseReturnsContent(): void
{
    $response = new CompletionResponse('Hello', new UsageStatistics(10, 5, 15));

    self::assertSame('Hello', $response->content);
    self::assertSame(15, $response->usage->totalTokens);
}
```

### Functional Test (Database)

```php
protected function setUp(): void
{
    parent::setUp();
    $this->importCSVDataSet(__DIR__ . '/Fixtures/providers.csv');
}

#[Test]
public function findsProviderByIdentifier(): void
{
    $provider = $this->providerRepository->findByIdentifier('openai-prod');
    self::assertNotNull($provider);
}
```

### Architecture Test (PHPat)

```php
public function testDomainModelsDoNotDependOnRepositories(): Rule
{
    return PHPat::rule()
        ->classes(Selector::inNamespace('Netresearch\NrLlm\Domain\Model'))
        ->shouldNotDependOn()
        ->classes(Selector::inNamespace('Netresearch\NrLlm\Domain\Repository'));
}
```

## Critical Rules

1. **One resource per test** - Don't share fixtures between tests
2. **CI is authoritative** - Local DDEV for debugging only
3. **No DDEV in CI** - Use GitHub Services + PHP built-in server
4. **Verify before claiming fixed** - Run tests, show output
