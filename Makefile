# Makefile for nr_llm TYPO3 extension development
# Generated according to TYPO3 DDEV Skill best practices

.PHONY: help up down restart install test test-unit test-functional lint lint-fix phpstan rector docs clean ci

# Default target
help:
	@echo "nr_llm - TYPO3 LLM Extension Development"
	@echo ""
	@echo "Usage: make [target]"
	@echo ""
	@echo "Environment:"
	@echo "  up          Start DDEV environment"
	@echo "  down        Stop DDEV environment"
	@echo "  restart     Restart DDEV environment"
	@echo "  install     Install TYPO3 v14 with extension"
	@echo ""
	@echo "Testing:"
	@echo "  test        Run all tests"
	@echo "  test-unit   Run unit tests only"
	@echo "  test-func   Run functional tests only"
	@echo "  coverage    Run tests with coverage"
	@echo "  mutation    Run mutation testing"
	@echo ""
	@echo "Quality:"
	@echo "  lint        Check code style (dry-run)"
	@echo "  lint-fix    Fix code style issues"
	@echo "  phpstan     Run static analysis"
	@echo "  rector      Run Rector refactoring (dry-run)"
	@echo "  rector-fix  Apply Rector refactoring"
	@echo "  ci          Run all CI checks"
	@echo ""
	@echo "Documentation:"
	@echo "  docs        Render RST documentation"
	@echo ""
	@echo "Maintenance:"
	@echo "  clean       Remove generated files"

# Environment targets
up:
	ddev start

down:
	ddev stop

restart:
	ddev restart

install:
	ddev install-v14

# Testing targets
test:
	ddev test

test-unit:
	ddev exec "cd /var/www/html/v14 && vendor/bin/phpunit -c /var/www/nr_llm/phpunit.xml --testsuite unit"

test-func:
	ddev exec "cd /var/www/html/v14 && vendor/bin/phpunit -c /var/www/nr_llm/phpunit.xml --testsuite functional"

coverage:
	ddev exec "cd /var/www/html/v14 && XDEBUG_MODE=coverage vendor/bin/phpunit -c /var/www/nr_llm/phpunit.xml --coverage-html /var/www/nr_llm/.Build/coverage"
	@echo "Coverage report: .Build/coverage/index.html"

mutation:
	ddev exec "cd /var/www/nr_llm && .Build/bin/infection --min-msi=80 --min-covered-msi=90"

# Quality targets
lint:
	ddev lint

lint-fix:
	ddev exec "cd /var/www/nr_llm && .Build/bin/php-cs-fixer fix"

phpstan:
	ddev phpstan

rector:
	ddev exec "cd /var/www/nr_llm && .Build/bin/rector process --config Build/rector/rector.php --dry-run"

rector-fix:
	ddev exec "cd /var/www/nr_llm && .Build/bin/rector process --config Build/rector/rector.php"

ci: lint phpstan test
	@echo "✅ All CI checks passed"

# Documentation targets
docs:
	ddev docs

# Maintenance targets
clean:
	rm -rf .Build/coverage
	rm -rf Documentation-GENERATED-temp
	rm -rf var/cache
