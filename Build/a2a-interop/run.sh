#!/usr/bin/env bash
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
#
# The A2A interop job (spec 051 A2A-S0-5): nr-llm's A2A client against
# a2a-python 1.3.0, on one Docker network.
#
#   Build/a2a-interop/run.sh            # PHP 8.5 testing image
#   A2A_INTEROP_PHP=8.2 Build/a2a-interop/run.sh
#
# The partner runs as a container named a2a-s1-partner-<pid>. The PHP test runs in
# TYPO3's core-testing image on the same network, so the partner's name
# resolves through Docker's DNS, the resolver the address guard really uses.
# The network's subnet is private; the test names it as the Testing
# context's public test network (A2A-SEC10), and nr-vault gets a literal
# allowed_hosts entry for the partner's name. Nothing else is relaxed.
#
# Requires .Build/ to be installed (composer install). Everything the script
# creates is removed on exit, and nothing else: the container and network names
# carry this run's pid, and the configuration file is removed only when this
# run wrote it. A second run in the same checkout stops while the first one's
# configuration file exists.

set -euo pipefail

cd "$(dirname "$0")/../.."
ROOT="$(pwd)"
PHP_VERSION="${A2A_INTEROP_PHP:-8.5}"
IMAGE_PHP="ghcr.io/typo3/core-testing-php${PHP_VERSION//./}:latest"
NETWORK="a2a-s1-net-$$"
PARTNER="a2a-s1-partner-$$"
CONFIG="${ROOT}/.Build/a2a-interop.json"
SQLITE_DIR="${ROOT}/.Build/Web/typo3temp/var/tests/functional-sqlite-dbs"

WROTE_CONFIG=0

clean_up() {
    docker rm -f "${PARTNER}" >/dev/null 2>&1 || true
    docker network rm "${NETWORK}" >/dev/null 2>&1 || true
    if [ "${WROTE_CONFIG}" = 1 ]; then
        rm -f "${CONFIG}"
    fi
}
trap clean_up EXIT

if [ -e "${CONFIG}" ]; then
    echo "${CONFIG} exists: another interop run uses this checkout, or one was killed. Remove the file once no run is left." >&2
    exit 1
fi

docker build -q -t a2a-s1-partner-image "${ROOT}/Build/a2a-interop" >/dev/null
docker network create "${NETWORK}" >/dev/null
docker run -d --name "${PARTNER}" --network "${NETWORK}" a2a-s1-partner-image \
    --port 18120 --public-url "http://${PARTNER}:18120" >/dev/null

SUBNET="$(docker network inspect "${NETWORK}" --format '{{(index .IPAM.Config 0).Subnet}}')"
WROTE_CONFIG=1
printf '{"cardUrl":"http://%s:18120/.well-known/agent-card.json","host":"%s","port":18120,"networks":["%s"]}\n' \
    "${PARTNER}" "${PARTNER}" "${SUBNET}" > "${CONFIG}"

# Wait for the card, from inside the network.
for _ in $(seq 1 60); do
    if docker run --rm --network "${NETWORK}" "${IMAGE_PHP}" php -r \
        "exit(@file_get_contents('http://${PARTNER}:18120/.well-known/agent-card.json') === false ? 1 : 0);" >/dev/null 2>&1; then
        break
    fi
    sleep 1
done

mkdir -p "${SQLITE_DIR}"
docker run --rm --network "${NETWORK}" \
    -v "${ROOT}:${ROOT}" -w "${ROOT}" \
    -e typo3DatabaseDriver=pdo_sqlite \
    --tmpfs "${SQLITE_DIR}:rw,noexec,nosuid,mode=1777" \
    -u "$(id -u):$(id -g)" \
    "${IMAGE_PHP}" \
    php -d opcache.enable_cli=1 .Build/bin/phpunit -c Build/FunctionalTests.xml \
        --filter 'A2aInteropTest|A2aVaultTransportTest' --fail-on-skipped
