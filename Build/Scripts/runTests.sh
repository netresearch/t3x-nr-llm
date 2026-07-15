#!/usr/bin/env bash

#
# TYPO3 Extension Test Runner - nr_llm
# Docker/podman-based test orchestration following TYPO3 core conventions.
#
# Reference: https://github.com/TYPO3BestPractices/tea
# Template: https://github.com/netresearch/typo3-testing-skill
#

trap 'clean_up;exit 2' SIGINT

wait_for() {
    local host=${1}
    local port=${2}
    local test_command="
        COUNT=0;
        while ! nc -z ${host} ${port}; do
            if [ \"\${COUNT}\" -gt 10 ]; then
              echo \"Can not connect to ${host} port ${port}. Aborting.\";
              exit 1;
            fi;
            sleep 1;
            COUNT=\$((COUNT + 1));
        done;
    "
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name wait-for-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${IMAGE_ALPINE} /bin/sh -c "${test_command}"
    if [[ $? -gt 0 ]]; then
        kill -SIGINT -$$
    fi
    return 0
}

wait_for_http() {
    local url=${1}
    local max_attempts=${2:-30}
    local test_command="
        COUNT=0;
        while ! wget -q --spider ${url} 2>/dev/null; do
            if [ \"\${COUNT}\" -gt ${max_attempts} ]; then
              echo \"HTTP endpoint ${url} not available after ${max_attempts} attempts. Aborting.\";
              exit 1;
            fi;
            sleep 1;
            COUNT=\$((COUNT + 1));
        done;
        echo \"HTTP endpoint ${url} is ready.\";
    "
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name wait-for-http-${SUFFIX} ${IMAGE_ALPINE} /bin/sh -c "${test_command}"
    if [[ $? -gt 0 ]]; then
        kill -SIGINT -$$
    fi
    return 0
}

clean_up() {
    if [[ -n "${NETWORK:-}" ]] && [[ -n "${CONTAINER_BIN:-}" ]]; then
        ATTACHED_CONTAINERS=$(${CONTAINER_BIN} ps --filter network=${NETWORK} --format='{{.Names}}' 2>/dev/null)
        for ATTACHED_CONTAINER in ${ATTACHED_CONTAINERS}; do
            ${CONTAINER_BIN} rm -f ${ATTACHED_CONTAINER} >/dev/null 2>&1
        done
        ${CONTAINER_BIN} network rm ${NETWORK} >/dev/null 2>&1
    fi
    return 0
}

clean_cache_files() {
    echo -n "Clean caches ... "
    rm -rf \
        .Build/.cache \
        .Build/cache \
        .php-cs-fixer.cache
    echo "done"
    return 0
}

handle_dbms_options() {
    case ${DBMS} in
        mariadb)
            [[ -z "${DATABASE_DRIVER}" ]] && DATABASE_DRIVER="${MYSQL_DRIVER}"
            if [[ "${DATABASE_DRIVER}" != "${MYSQL_DRIVER}" ]] && [[ "${DATABASE_DRIVER}" != "pdo_mysql" ]]; then
                echo "Invalid combination -d ${DBMS} -a ${DATABASE_DRIVER}" >&2
                exit 1
            fi
            [[ -z "${DBMS_VERSION}" ]] && DBMS_VERSION="11.4"
            if ! [[ ${DBMS_VERSION} =~ ^(10.5|10.6|10.11|11.0|11.4)$ ]]; then
                echo "Invalid combination -d ${DBMS} -i ${DBMS_VERSION}" >&2
                exit 1
            fi
            ;;
        mysql)
            [[ -z "${DATABASE_DRIVER}" ]] && DATABASE_DRIVER="${MYSQL_DRIVER}"
            if [[ "${DATABASE_DRIVER}" != "${MYSQL_DRIVER}" ]] && [[ "${DATABASE_DRIVER}" != "pdo_mysql" ]]; then
                echo "Invalid combination -d ${DBMS} -a ${DATABASE_DRIVER}" >&2
                exit 1
            fi
            [[ -z "${DBMS_VERSION}" ]] && DBMS_VERSION="8.4"
            if ! [[ ${DBMS_VERSION} =~ ^(8.0|8.4|9.0)$ ]]; then
                echo "Invalid combination -d ${DBMS} -i ${DBMS_VERSION}" >&2
                exit 1
            fi
            ;;
        postgres)
            if [[ -n "${DATABASE_DRIVER}" ]]; then
                echo "Invalid combination -d ${DBMS} -a ${DATABASE_DRIVER}" >&2
                exit 1
            fi
            [[ -z "${DBMS_VERSION}" ]] && DBMS_VERSION="16"
            if ! [[ ${DBMS_VERSION} =~ ^(12|13|14|15|16|17)$ ]]; then
                echo "Invalid combination -d ${DBMS} -i ${DBMS_VERSION}" >&2
                exit 1
            fi
            ;;
        sqlite)
            if [[ -n "${DATABASE_DRIVER}" ]]; then
                echo "Invalid combination -d ${DBMS} -a ${DATABASE_DRIVER}" >&2
                exit 1
            fi
            ;;
        *)
            echo "Invalid option -d ${DBMS}" >&2
            exit 1
            ;;
    esac
    return 0
}

load_help() {
    read -r -d '' HELP <<EOF
nr_llm - TYPO3 Extension Test Runner
Execute tests in Docker containers using TYPO3 core-testing images.

Usage: $0 [options] [file]

Options:
    -s <...>
        Specifies which test suite to run
            - cgl: PHP CS Fixer check/fix
            - clean: Clean temporary files
            - composer: Run composer commands
            - composerUpdate: Clean install dependencies (removes vendor/)
            - e2e: Playwright E2E tests (requires running TYPO3)
            - functional: PHP functional tests
            - functionalParallel: Parallel functional tests (faster)
            - functionalCoverage: Functional tests with coverage
            - integration: Integration tests
            - lint: PHP linting
            - phpstan: PHPStan static analysis
            - rector: Rector code upgrades
            - unit: PHP unit tests (default)
            - unitCoverage: Unit tests with coverage
            - fuzzy: Property-based (fuzzy) tests
            - mutation: Mutation testing
            - architecture: Architecture tests (PHPat via PHPStan)

    -d <sqlite|mariadb|mysql|postgres>
        Database for functional tests (default: sqlite)

    -i version
        Database version (mariadb: 11.4, mysql: 8.4, postgres: 16)

    -p <8.2|8.3|8.4|8.5>
        PHP version (default: 8.5)

    -t <13|13.4|14|^13.4|...>
        TYPO3 core version to require before running the suite.
        Runs `composer require typo3/cms-core:<constraint>` + update in a
        short-lived container. Bare numerics become `^<n>` (`-t 14` →
        `^14`). Skip to use whatever composer.lock resolves to.
        Matches the matrix cell in .github/workflows/ci.yml so local
        runs are reproducible against CI combinations.

    -x
        Enable Xdebug for debugging

    -n
        Dry-run mode (for cgl, rector)

    -h
        Show this help

Examples:
    # Run unit tests
    ./Build/Scripts/runTests.sh -s unit

    # Run functional tests with MariaDB
    ./Build/Scripts/runTests.sh -s functional -d mariadb

    # Run PHPStan analysis
    ./Build/Scripts/runTests.sh -s phpstan

    # Run E2E tests (requires ddev or TYPO3_BASE_URL)
    ddev start && ./Build/Scripts/runTests.sh -s e2e

E2E Tests:
    E2E tests require a running TYPO3 instance.
    Options:
        1. Start ddev: ddev start && ./Build/Scripts/runTests.sh -s e2e
        2. Set URL: TYPO3_BASE_URL=https://your-typo3.local ./Build/Scripts/runTests.sh -s e2e
EOF
    return 0
}

# Literal constants reused across the script
readonly DOCKER_BIN="docker"
readonly MYSQL_DRIVER="mysqli"

# Check container runtime
if ! type "${DOCKER_BIN}" >/dev/null 2>&1 && ! type "podman" >/dev/null 2>&1; then
    echo "This script requires docker or podman." >&2
    exit 1
fi

# Option defaults
TEST_SUITE="unit"
DATABASE_DRIVER=""
DBMS="sqlite"
DBMS_VERSION=""
PHP_VERSION="8.5"
# TYPO3 core version constraint. Empty = use what's already installed
# (whatever composer.json / composer.lock resolves to). Set via -t to
# require a specific major, e.g. `-t 13.4` runs the suite against
# typo3/cms-core:^13.4. Matches the CI matrix in .github/workflows/ci.yml.
TYPO3_VERSION=""
PHP_XDEBUG_ON=0
PHP_XDEBUG_PORT=9003
CGLCHECK_DRY_RUN=0
CI_PARAMS="${CI_PARAMS:-}"
CONTAINER_BIN=""
CONTAINER_HOST="host.docker.internal"
SUITE_EXIT_CODE=0

# Parse options
OPTIND=1
while getopts "a:b:d:i:s:p:t:xy:nhu" OPT; do
    case ${OPT} in
        a) DATABASE_DRIVER=${OPTARG} ;;
        s) TEST_SUITE=${OPTARG} ;;
        b) CONTAINER_BIN=${OPTARG} ;;
        d) DBMS=${OPTARG} ;;
        i) DBMS_VERSION=${OPTARG} ;;
        p) PHP_VERSION=${OPTARG} ;;
        t) TYPO3_VERSION=${OPTARG} ;;
        x) PHP_XDEBUG_ON=1 ;;
        y) PHP_XDEBUG_PORT=${OPTARG} ;;
        n) CGLCHECK_DRY_RUN=1 ;;
        h) load_help; echo "${HELP}"; exit 0 ;;
        u) TEST_SUITE=update ;;
        \?) exit 1 ;;
        *) exit 1 ;;
    esac
done

handle_dbms_options

# Extension version for Composer
COMPOSER_ROOT_VERSION="0.1.x-dev"

HOST_UID=$(id -u)
USERSET=""
if [[ $(uname) != "Darwin" ]]; then
    USERSET="--user $HOST_UID"
fi

# Navigate to project root
THIS_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null && pwd)"
cd "$THIS_SCRIPT_DIR" || exit 1
cd ../../ || exit 1
ROOT_DIR="${PWD}"

# Create cache directories
mkdir -p .Build/.cache
mkdir -p .Build/Web/typo3temp/var/tests

IMAGE_PREFIX="docker.io/"
TYPO3_IMAGE_PREFIX="ghcr.io/typo3/"
CONTAINER_INTERACTIVE="-it --init"

IS_CORE_CI=0
if [[ "${CI}" == "true" ]] || ! [[ -t 0 ]]; then
    IS_CORE_CI=1
    IMAGE_PREFIX=""
    CONTAINER_INTERACTIVE=""
fi

# Determine container binary
if [[ -z "${CONTAINER_BIN}" ]]; then
    if type "podman" >/dev/null 2>&1; then
        CONTAINER_BIN="podman"
    elif type "${DOCKER_BIN}" >/dev/null 2>&1; then
        CONTAINER_BIN="${DOCKER_BIN}"
    fi
fi

# Container images
IMAGE_PHP="${TYPO3_IMAGE_PREFIX}core-testing-$(echo "php${PHP_VERSION}" | sed -e 's/\.//'):latest"
IMAGE_ALPINE="${IMAGE_PREFIX}alpine:3.20"
IMAGE_MARIADB="docker.io/mariadb:${DBMS_VERSION}"
IMAGE_MYSQL="docker.io/mysql:${DBMS_VERSION}"
IMAGE_POSTGRES="docker.io/postgres:${DBMS_VERSION}-alpine"
IMAGE_PLAYWRIGHT="mcr.microsoft.com/playwright:v1.60.0-noble"

shift $((OPTIND - 1))

SUFFIX="$(date +%s)-${RANDOM}"
NETWORK="nr-llm-${SUFFIX}"
if ! ${CONTAINER_BIN} network create ${NETWORK} >/dev/null 2>&1; then
    echo "Failed to create container network '${NETWORK}'. Ensure ${CONTAINER_BIN} daemon is running." >&2
    exit 1
fi

if [[ ${CONTAINER_BIN} = "${DOCKER_BIN}" ]]; then
    CONTAINER_COMMON_PARAMS="${CONTAINER_INTERACTIVE} --rm --network ${NETWORK} --add-host "${CONTAINER_HOST}:host-gateway" ${USERSET} -v ${ROOT_DIR}:${ROOT_DIR} -w ${ROOT_DIR}"
else
    CONTAINER_HOST="host.containers.internal"
    CONTAINER_COMMON_PARAMS="${CONTAINER_INTERACTIVE} ${CI_PARAMS} --rm --network ${NETWORK} -v ${ROOT_DIR}:${ROOT_DIR} -w ${ROOT_DIR}"
fi

if [[ ${PHP_XDEBUG_ON} -eq 0 ]]; then
    XDEBUG_MODE="-e XDEBUG_MODE=off"
    XDEBUG_CONFIG=" "
else
    XDEBUG_MODE="-e XDEBUG_MODE=debug -e XDEBUG_TRIGGER=foo"
    XDEBUG_CONFIG="client_port=${PHP_XDEBUG_PORT} client_host=${CONTAINER_HOST}"
fi

# If -t <major> was given, require that TYPO3 core version before running
# the suite. Runs inside the same PHP image / user mapping / COMPOSER cache
# as the rest of the script so the resolved composer.lock and .Build/vendor
# match the PHP version of the suite container.
if [[ -n "${TYPO3_VERSION}" ]]; then
    # Normalise bare majors/"major.minor" to caret ranges; keep explicit
    # constraint strings (e.g. "^14.0", ">=13.4 <15") verbatim.
    if [[ "${TYPO3_VERSION}" =~ ^[0-9]+(\.[0-9]+)?$ ]]; then
        TYPO3_CONSTRAINT="^${TYPO3_VERSION}"
    else
        TYPO3_CONSTRAINT="${TYPO3_VERSION}"
    fi
    echo "⟳ Requiring typo3/cms-core:${TYPO3_CONSTRAINT} (matches -t ${TYPO3_VERSION})"
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name typo3-require-${SUFFIX} \
        -e COMPOSER_CACHE_DIR=.Build/.cache/composer \
        -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} \
        -e CAPTAINHOOK_DISABLE=true \
        ${IMAGE_PHP} composer require \
            --no-interaction --no-progress --no-scripts --no-update \
            "typo3/cms-core:${TYPO3_CONSTRAINT}" \
        || { echo "typo3/cms-core require failed for ${TYPO3_CONSTRAINT}"; exit 1; }
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name typo3-update-${SUFFIX} \
        -e COMPOSER_CACHE_DIR=.Build/.cache/composer \
        -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} \
        -e CAPTAINHOOK_DISABLE=true \
        ${IMAGE_PHP} composer update \
            --no-interaction --no-progress --no-scripts --with-all-dependencies \
            typo3/cms-core \
        || { echo "typo3/cms-core update failed for ${TYPO3_CONSTRAINT}"; exit 1; }
fi

# PHP performance options
PHP_OPCACHE_OPTS="-d opcache.enable_cli=1 -d opcache.jit=1255 -d opcache.jit_buffer_size=128M"
# Functional/e2e-backend suites run WITHOUT the JIT: the tracing JIT in the
# container PHP builds (reproduced on 8.3 and 8.5) segfaults silently during
# suite bootstrap for certain — perfectly valid — source shapes (adding a
# plain property+getter+setter to an entity flipped it). Identical runs with
# opcache.jit=off pass; the functionalCoverage path below already ran without
# the JIT. Functional tests are IO-bound, so the JIT buys nothing here anyway.
PHP_FUNCTIONAL_OPTS="-d opcache.enable_cli=1"

# Suite execution
case ${TEST_SUITE} in
    architecture)
        # Architecture tests are run via PHPStan with phpat extension
        COMMAND="php ${PHP_OPCACHE_OPTS} -dxdebug.mode=off .Build/bin/phpstan analyse -c Build/phpstan/phpstan.neon"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name architecture-${SUFFIX} -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} /bin/sh -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    cgl)
        if [[ "${CGLCHECK_DRY_RUN}" -eq 1 ]]; then
            COMMAND="php ${PHP_OPCACHE_OPTS} -dxdebug.mode=off .Build/bin/php-cs-fixer fix -v --dry-run --diff"
        else
            COMMAND="php ${PHP_OPCACHE_OPTS} -dxdebug.mode=off .Build/bin/php-cs-fixer fix -v"
        fi
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name cgl-${SUFFIX} -e COMPOSER_CACHE_DIR=.Build/.cache/composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} /bin/sh -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    clean)
        clean_cache_files
        SUITE_EXIT_CODE=0
        ;;
    composer)
        COMMAND=(composer "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-${SUFFIX} -e COMPOSER_CACHE_DIR=.Build/.cache/composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} -e CAPTAINHOOK_DISABLE=true ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    composerUpdate)
        rm -rf .Build/bin/ .Build/vendor ./composer.lock
        COMMAND=(composer install --no-ansi --no-interaction --no-progress)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-${SUFFIX} -e COMPOSER_CACHE_DIR=.Build/.cache/composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} -e CAPTAINHOOK_DISABLE=true ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    e2e)
        if [[ -n "${TYPO3_BASE_URL:-}" ]]; then
            echo "Using TYPO3_BASE_URL from environment: ${TYPO3_BASE_URL}"
        elif type "ddev" >/dev/null 2>&1 && ddev describe >/dev/null 2>&1; then
            TYPO3_BASE_URL="https://v14.nr-llm.ddev.site"
            echo "Using ddev TYPO3 URL: ${TYPO3_BASE_URL}"
        else
            TYPO3_BASE_URL="https://v14.nr-llm.ddev.site"
            echo "Warning: No TYPO3 instance detected."
            echo "E2E tests require a running TYPO3 instance."
            echo "  1. Start ddev: ddev start"
            echo "  2. Or set: TYPO3_BASE_URL=https://your-typo3.local $0 -s e2e"
        fi

        mkdir -p .Build/.cache/npm
        mkdir -p node_modules

        # Check for permission issues (root-owned files from previous container runs)
        if [[ -d "node_modules" ]] && [[ -n "$(find node_modules -maxdepth 1 -user root 2>/dev/null | head -1)" ]]; then
            echo "Error: node_modules contains root-owned files." >&2
            echo "Please remove and retry: sudo rm -rf node_modules" >&2
            exit 1
        fi

        # Connect to ddev network if available
        DDEV_PARAMS=""
        if type "ddev" >/dev/null 2>&1 && ddev describe >/dev/null 2>&1; then
            ROUTER_IP=$(${CONTAINER_BIN} inspect ddev-router --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' 2>/dev/null)
            if [[ -n "${ROUTER_IP}" ]]; then
                DDEV_PARAMS="--network ddev_default"
                DDEV_PARAMS="${DDEV_PARAMS} --add-host v14.nr-llm.ddev.site:${ROUTER_IP}"
                echo "Connecting to ddev network (router IP: ${ROUTER_IP})"
            fi
        fi

        COMMAND="npm ci && npx playwright test $*"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} ${DDEV_PARAMS} --name e2e-${SUFFIX} \
            -e TYPO3_BASE_URL="${TYPO3_BASE_URL}" \
            -e CI="${CI:-}" \
            -e npm_config_cache="${ROOT_DIR}/.Build/.cache/npm" \
            ${IMAGE_PLAYWRIGHT} /bin/bash -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    functional)
        COMMAND=(php ${PHP_FUNCTIONAL_OPTS} -dxdebug.mode=off .Build/bin/phpunit -c Build/FunctionalTests.xml --exclude-group not-${DBMS} "$@")

        case ${DBMS} in
            mariadb)
                echo "Using driver: ${DATABASE_DRIVER}"
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name mariadb-func-${SUFFIX} --network ${NETWORK} -d -e MYSQL_ROOT_PASSWORD=funcp --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MARIADB} >/dev/null
                wait_for mariadb-func-${SUFFIX} 3306
                CONTAINERPARAMS="-e typo3DatabaseDriver=${DATABASE_DRIVER} -e typo3DatabaseName=func_test -e typo3DatabaseUsername=root -e typo3DatabaseHost=mariadb-func-${SUFFIX} -e typo3DatabasePassword=funcp"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
            mysql)
                echo "Using driver: ${DATABASE_DRIVER}"
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name mysql-func-${SUFFIX} --network ${NETWORK} -d -e MYSQL_ROOT_PASSWORD=funcp --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MYSQL} >/dev/null
                wait_for mysql-func-${SUFFIX} 3306
                CONTAINERPARAMS="-e typo3DatabaseDriver=${DATABASE_DRIVER} -e typo3DatabaseName=func_test -e typo3DatabaseUsername=root -e typo3DatabaseHost=mysql-func-${SUFFIX} -e typo3DatabasePassword=funcp"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
            postgres)
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name postgres-func-${SUFFIX} --network ${NETWORK} -d -e POSTGRES_PASSWORD=funcp -e POSTGRES_USER=funcu --tmpfs /var/lib/postgresql/data:rw,noexec,nosuid ${IMAGE_POSTGRES} >/dev/null
                wait_for postgres-func-${SUFFIX} 5432
                CONTAINERPARAMS="-e typo3DatabaseDriver=pdo_pgsql -e typo3DatabaseName=bamboo -e typo3DatabaseUsername=funcu -e typo3DatabaseHost=postgres-func-${SUFFIX} -e typo3DatabasePassword=funcp"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
            sqlite)
                mkdir -p "${ROOT_DIR}/.Build/Web/typo3temp/var/tests/functional-sqlite-dbs/"
                CONTAINERPARAMS="-e typo3DatabaseDriver=pdo_sqlite --tmpfs ${ROOT_DIR}/.Build/Web/typo3temp/var/tests/functional-sqlite-dbs/:rw,noexec,nosuid,mode=1777"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
            *)
                echo "Unsupported DBMS for functional tests: ${DBMS}" >&2
                exit 1
                ;;
        esac
        ;;
    functionalParallel)
        mkdir -p "${ROOT_DIR}/.Build/Web/typo3temp/var/tests/functional-sqlite-dbs/"

        if [[ "${CI}" == "true" ]]; then
            PARALLEL_JOBS=4
        else
            # Use half of available CPUs for local runs
            PARALLEL_JOBS=$(( ($(nproc) + 1) / 2 ))
        fi

        # Both globs are required: Build/FunctionalTests.xml runs the `functional`
        # AND the `e2e-backend` suites. Globbing only Tests/Functional silently
        # dropped all 8 Tests/E2E/Backend classes here — the same rot as #272,
        # where the suite was skipped wholesale and nobody noticed.
        COMMAND="find Tests/Functional Tests/E2E/Backend -name '*Test.php' | xargs -P${PARALLEL_JOBS} -I{} php ${PHP_FUNCTIONAL_OPTS} -dxdebug.mode=off .Build/bin/phpunit -c Build/FunctionalTests.xml {}"
        CONTAINERPARAMS="-e typo3DatabaseDriver=pdo_sqlite --tmpfs ${ROOT_DIR}/.Build/Web/typo3temp/var/tests/functional-sqlite-dbs/:rw,noexec,nosuid,mode=1777"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-parallel-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} /bin/sh -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    functionalCoverage)
        mkdir -p .Build/coverage
        COMMAND=(php -d opcache.enable_cli=1 .Build/bin/phpunit -c Build/FunctionalTests.xml --coverage-clover=.Build/coverage/functional.xml --coverage-html=.Build/coverage/html-functional --coverage-text "$@")
        mkdir -p "${ROOT_DIR}/.Build/Web/typo3temp/var/tests/functional-sqlite-dbs/"
        CONTAINERPARAMS="-e typo3DatabaseDriver=pdo_sqlite --tmpfs ${ROOT_DIR}/.Build/Web/typo3temp/var/tests/functional-sqlite-dbs/:rw,noexec,nosuid,mode=1777"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-coverage-${SUFFIX} -e XDEBUG_MODE=coverage ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    fuzzy)
        COMMAND=(php ${PHP_OPCACHE_OPTS} -dxdebug.mode=off .Build/bin/phpunit -c Build/phpunit.xml --testsuite fuzzy "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name fuzzy-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    integration)
        COMMAND=(php ${PHP_OPCACHE_OPTS} -dxdebug.mode=off .Build/bin/phpunit -c Build/phpunit.xml --testsuite integration "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name integration-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    lint)
        COMMAND="find Classes Configuration Tests -name \\*.php -print0 | xargs -0 -n1 -P\$(nproc) php -dxdebug.mode=off -l >/dev/null"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name lint-${SUFFIX} ${IMAGE_PHP} /bin/sh -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    mutation)
        COMMAND=(php -d opcache.enable_cli=1 .Build/bin/infection --configuration=infection.json.dist --threads=4 "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name mutation-${SUFFIX} -e XDEBUG_MODE=coverage ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    phpstan)
        COMMAND="php ${PHP_OPCACHE_OPTS} -dxdebug.mode=off .Build/bin/phpstan analyse -c Build/phpstan/phpstan.neon"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name phpstan-${SUFFIX} -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} /bin/sh -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    rector)
        if [[ "${CGLCHECK_DRY_RUN}" -eq 1 ]]; then
            COMMAND="php ${PHP_OPCACHE_OPTS} -dxdebug.mode=off .Build/bin/rector process --config Build/rector/rector.php --dry-run"
        else
            COMMAND="php ${PHP_OPCACHE_OPTS} -dxdebug.mode=off .Build/bin/rector process --config Build/rector/rector.php"
        fi
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name rector-${SUFFIX} -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} /bin/sh -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    unit)
        COMMAND=(php ${PHP_OPCACHE_OPTS} -dxdebug.mode=off .Build/bin/phpunit -c Build/phpunit.xml --testsuite unit "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name unit-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    unitCoverage)
        mkdir -p .Build/coverage
        COMMAND=(php -d opcache.enable_cli=1 .Build/bin/phpunit -c Build/phpunit.xml --testsuite unit --coverage-clover=.Build/coverage/unit.xml --coverage-html=.Build/coverage/html-unit --coverage-text "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name unit-coverage-${SUFFIX} -e XDEBUG_MODE=coverage ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    update)
        echo "> Updating ${TYPO3_IMAGE_PREFIX}core-testing-* images..."
        ${CONTAINER_BIN} images "${TYPO3_IMAGE_PREFIX}core-testing-*" --format "{{.Repository}}:{{.Tag}}" | xargs -I {} ${CONTAINER_BIN} pull {}
        SUITE_EXIT_CODE=$?
        ;;
    *)
        load_help
        echo "Invalid -s option: ${TEST_SUITE}" >&2
        echo "${HELP}" >&2
        exit 1
        ;;
esac

clean_up

# Print summary
echo "" >&2
echo "###########################################################################" >&2
echo "Result of ${TEST_SUITE}" >&2
echo "Container runtime: ${CONTAINER_BIN}" >&2
if [[ ${IS_CORE_CI} -eq 1 ]]; then
    echo "Environment: CI" >&2
else
    echo "Environment: local" >&2
fi
echo "PHP: ${PHP_VERSION}" >&2
if [[ ${TEST_SUITE} =~ ^functional ]]; then
    echo "DBMS: ${DBMS}" >&2
fi
if [[ ${SUITE_EXIT_CODE} -eq 0 ]]; then
    echo "SUCCESS" >&2
else
    echo "FAILURE" >&2
fi
echo "###########################################################################" >&2
echo "" >&2

exit $SUITE_EXIT_CODE
