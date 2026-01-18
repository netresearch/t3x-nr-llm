#!/usr/bin/env bash

#
# TYPO3 Extension Test Runner - nr_llm
# Based on TYPO3 Best Practices: https://github.com/TYPO3BestPractices/tea
#
# This script provides a unified interface for running various test suites
# and quality tools for the nr_llm extension.
#
# Usage: ./Build/Scripts/runTests.sh [options]
#

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Extension root directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

# Composer binary
COMPOSER_BIN="${ROOT_DIR}/.Build/bin"
VENDOR_BIN="${ROOT_DIR}/.Build/bin"

# Default values
PHP_VERSION="${PHP_VERSION:-8.5}"
DBMS="${DBMS:-sqlite}"
EXTRA_TEST_OPTIONS=""

#
# Print usage information
#
usage() {
    cat << EOF
TYPO3 Extension Test Runner - nr_llm

Usage: $(basename "$0") [OPTIONS] <COMMAND>

Commands:
    unit              Run unit tests
    functional        Run functional tests
    integration       Run integration tests
    fuzzy             Run property-based (fuzzy) tests
    e2e               Run E2E tests (PHP-based)
    playwright        Run Playwright E2E tests
    accessibility     Run accessibility tests with axe-core
    mutation          Run mutation tests with Infection
    phpstan           Run PHPStan static analysis
    lint              Run PHP-CS-Fixer in dry-run mode
    lint:fix          Run PHP-CS-Fixer and apply fixes
    rector            Run Rector in dry-run mode
    rector:fix        Run Rector and apply changes
    ci                Run full CI suite (lint, phpstan, unit, integration, fuzzy)
    ci:full           Run full CI suite including functional tests
    all               Run all tests and quality checks

Options:
    -h, --help        Show this help message
    -v, --verbose     Verbose output
    -p, --php         PHP version (default: ${PHP_VERSION})
    -d, --dbms        Database system for functional tests (default: ${DBMS})
                      Options: sqlite, mysql, mariadb, postgres
    -x                Extra options to pass to PHPUnit

Environment:
    TYPO3_BASE_URL    Base URL for Playwright tests (default: https://v14.nr-llm.ddev.site)

Examples:
    $(basename "$0") unit
    $(basename "$0") -p 8.5 functional
    $(basename "$0") -x "--filter=testSpecificTest" unit
    $(basename "$0") ci
    $(basename "$0") playwright                                        # Uses DDEV (default)
    TYPO3_BASE_URL=http://localhost:8080 $(basename "$0") playwright   # Custom URL

EOF
}

#
# Print colored message
#
info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

#
# Check if composer dependencies are installed
#
check_dependencies() {
    if [[ ! -d "${ROOT_DIR}/.Build/vendor" ]]; then
        error "Dependencies not installed. Run 'composer install' first."
        exit 1
    fi
}

#
# Check if node dependencies are installed (for Playwright)
#
check_node_dependencies() {
    if [[ ! -d "${ROOT_DIR}/node_modules" ]]; then
        warning "Node dependencies not installed. Running 'npm install'..."
        cd "${ROOT_DIR}" && npm install
    fi
}

#
# Run unit tests
#
run_unit_tests() {
    info "Running unit tests..."
    check_dependencies
    "${VENDOR_BIN}/phpunit" -c "${ROOT_DIR}/phpunit.xml" --testsuite unit ${EXTRA_TEST_OPTIONS}
    success "Unit tests completed"
}

#
# Run functional tests
#
run_functional_tests() {
    info "Running functional tests with DBMS=${DBMS}..."
    check_dependencies
    "${VENDOR_BIN}/phpunit" -c "${ROOT_DIR}/Build/FunctionalTests.xml" ${EXTRA_TEST_OPTIONS}
    success "Functional tests completed"
}

#
# Run integration tests
#
run_integration_tests() {
    info "Running integration tests..."
    check_dependencies
    "${VENDOR_BIN}/phpunit" -c "${ROOT_DIR}/phpunit.xml" --testsuite integration ${EXTRA_TEST_OPTIONS}
    success "Integration tests completed"
}

#
# Run fuzzy (property-based) tests
#
run_fuzzy_tests() {
    info "Running fuzzy (property-based) tests..."
    check_dependencies
    "${VENDOR_BIN}/phpunit" -c "${ROOT_DIR}/phpunit.xml" --testsuite fuzzy ${EXTRA_TEST_OPTIONS}
    success "Fuzzy tests completed"
}

#
# Run E2E tests (PHP-based)
#
run_e2e_tests() {
    info "Running E2E tests (PHP-based)..."
    check_dependencies
    "${VENDOR_BIN}/phpunit" -c "${ROOT_DIR}/phpunit.xml" --testsuite e2e ${EXTRA_TEST_OPTIONS}
    success "E2E tests completed"
}

#
# Run Playwright E2E tests
#
# Requires a running TYPO3 instance. Use one of:
# - DDEV locally: TYPO3_BASE_URL=https://v14.nr-llm.ddev.site ./Build/Scripts/runTests.sh playwright
# - CI: The GitHub Actions workflow handles TYPO3 setup automatically
#
run_playwright_tests() {
    local typo3_base_url="${TYPO3_BASE_URL:-https://v14.nr-llm.ddev.site}"

    info "Running Playwright E2E tests..."
    check_node_dependencies

    # Check if TYPO3 is accessible
    info "Checking TYPO3 at ${typo3_base_url}..."
    if ! curl -sk "${typo3_base_url}/typo3/" > /dev/null 2>&1; then
        warning "TYPO3 not responding at ${typo3_base_url}"
        warning "Make sure TYPO3 is running (e.g., 'ddev start' for local development)"
        warning "Or set TYPO3_BASE_URL environment variable to the correct URL"
    fi

    # Set the base URL for Playwright
    export TYPO3_BASE_URL="${typo3_base_url}"

    # Run Playwright tests
    info "Executing Playwright tests against ${TYPO3_BASE_URL}..."
    cd "${ROOT_DIR}"

    npm run test:e2e
    success "Playwright tests completed"
}

#
# Run accessibility tests
#
run_accessibility_tests() {
    info "Running accessibility tests with axe-core..."
    check_node_dependencies
    cd "${ROOT_DIR}" && npx playwright test --grep "@accessibility"
    success "Accessibility tests completed"
}

#
# Run mutation tests
#
run_mutation_tests() {
    info "Running mutation tests with Infection..."
    check_dependencies
    "${VENDOR_BIN}/infection" --configuration="${ROOT_DIR}/infection.json.dist" --threads=4 -s --no-progress
    success "Mutation tests completed"
}

#
# Run PHPStan
#
run_phpstan() {
    info "Running PHPStan static analysis..."
    check_dependencies
    "${VENDOR_BIN}/phpstan" analyse -c "${ROOT_DIR}/Build/phpstan/phpstan.neon"
    success "PHPStan analysis completed"
}

#
# Run PHP-CS-Fixer (dry-run)
#
run_lint() {
    info "Running PHP-CS-Fixer (dry-run)..."
    check_dependencies
    "${VENDOR_BIN}/php-cs-fixer" fix --dry-run --diff
    success "Lint check completed"
}

#
# Run PHP-CS-Fixer (fix)
#
run_lint_fix() {
    info "Running PHP-CS-Fixer (applying fixes)..."
    check_dependencies
    "${VENDOR_BIN}/php-cs-fixer" fix
    success "Lint fixes applied"
}

#
# Run Rector (dry-run)
#
run_rector() {
    info "Running Rector (dry-run)..."
    check_dependencies
    "${VENDOR_BIN}/rector" process --config "${ROOT_DIR}/Build/rector/rector.php" --dry-run
    success "Rector analysis completed"
}

#
# Run Rector (fix)
#
run_rector_fix() {
    info "Running Rector (applying changes)..."
    check_dependencies
    "${VENDOR_BIN}/rector" process --config "${ROOT_DIR}/Build/rector/rector.php"
    success "Rector changes applied"
}

#
# Run CI suite
#
run_ci() {
    info "Running CI suite..."
    run_lint
    run_phpstan
    run_unit_tests
    run_integration_tests
    run_fuzzy_tests
    success "CI suite completed"
}

#
# Run full CI suite
#
run_ci_full() {
    info "Running full CI suite..."
    run_ci
    run_functional_tests
    success "Full CI suite completed"
}

#
# Run all tests and checks
#
run_all() {
    info "Running all tests and quality checks..."
    run_lint
    run_phpstan
    run_rector
    run_unit_tests
    run_integration_tests
    run_fuzzy_tests
    run_functional_tests
    run_mutation_tests
    success "All tests and checks completed"
}

#
# Parse command line arguments
#
parse_args() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            -h|--help)
                usage
                exit 0
                ;;
            -v|--verbose)
                set -x
                shift
                ;;
            -p|--php)
                PHP_VERSION="$2"
                shift 2
                ;;
            -d|--dbms)
                DBMS="$2"
                shift 2
                ;;
            -x)
                EXTRA_TEST_OPTIONS="$2"
                shift 2
                ;;
            unit)
                run_unit_tests
                exit 0
                ;;
            functional)
                run_functional_tests
                exit 0
                ;;
            integration)
                run_integration_tests
                exit 0
                ;;
            fuzzy)
                run_fuzzy_tests
                exit 0
                ;;
            e2e)
                run_e2e_tests
                exit 0
                ;;
            playwright)
                run_playwright_tests
                exit 0
                ;;
            accessibility)
                run_accessibility_tests
                exit 0
                ;;
            mutation)
                run_mutation_tests
                exit 0
                ;;
            phpstan)
                run_phpstan
                exit 0
                ;;
            lint)
                run_lint
                exit 0
                ;;
            lint:fix)
                run_lint_fix
                exit 0
                ;;
            rector)
                run_rector
                exit 0
                ;;
            rector:fix)
                run_rector_fix
                exit 0
                ;;
            ci)
                run_ci
                exit 0
                ;;
            ci:full)
                run_ci_full
                exit 0
                ;;
            all)
                run_all
                exit 0
                ;;
            *)
                error "Unknown option or command: $1"
                usage
                exit 1
                ;;
        esac
    done

    # No command provided
    usage
    exit 1
}

#
# Main entry point
#
main() {
    cd "${ROOT_DIR}"

    if [[ $# -eq 0 ]]; then
        usage
        exit 1
    fi

    parse_args "$@"
}

main "$@"
