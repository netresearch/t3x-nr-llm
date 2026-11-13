#!/usr/bin/env bash
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
#
# nr-llm's A2A server role against independent clients (spec 051 stage 3):
#
#   Build/a2a-server/run.sh tck       # the A2A TCK, JSON-RPC (A2A-SV11)
#   Build/a2a-server/run.sh interop   # a2a-python 1.3.0 as client
#
# One Docker network holds MariaDB (<P>-db), a TYPO3 installation of this
# checkout served by `php -S` in the Testing context (<P>-sut, port 8080), a
# forwarding proxy (<P>-proxy, port 8080) and the client; <P> is the prefix
# and the run's pid, so runs on one Docker host do not meet. The site's A2A base URL names the
# proxy, http://<P>-proxy:8080/a2a, so the Host header
# the server sees is the one it is configured for. The proxy exists because
# the TCK sends no Authorization header (see auth_proxy.py); in the TCK run it
# adds the client's token, in the interop run it adds nothing.
#
# The profile is the TCK fixture (Testing context only), so no model is
# called. A sweep loop of `nrllm:agent:work` provides the worker heartbeat
# the server requires before it accepts a task.
#
# Requires .Build/ to be installed (composer install). Writes config/ and
# var/ of this checkout (both ignored by git) and removes the containers, the
# network and config/system/settings.php, additional.php and config/sites/a2a
# on exit, only when this run wrote them: a checkout that already has one of
# them is refused, so an installation of the developer's is never touched. The
# TCK reports are copied to .Build/a2a-tck-reports/; the TCK mode fails when
# the TCK produced no report, and reports the score without gating on it.

set -euo pipefail

MODE="${1:-tck}"
cd "$(dirname "$0")/../.."
ROOT="$(pwd)"
PHP_VERSION="${A2A_SERVER_PHP:-8.5}"
# Container, network and image names start with this prefix; container and
# network names add the run's pid.
PREFIX="${A2A_CONTAINER_PREFIX:-a2a-s3}"
P="${PREFIX}-$$"
IMAGE_PHP="ghcr.io/typo3/core-testing-php${PHP_VERSION//./}:latest"
IMAGE_DB="ghcr.io/netresearch/ci-mirror/mariadb:12.3"
NETWORK="${P}-net"
BASE="http://${P}-proxy:8080/a2a"
UIDGID="$(id -u):$(id -g)"
WROTE_CONFIG=0

for owned in config/system/settings.php config/system/additional.php config/sites/a2a; do
    if [[ -e "${ROOT}/${owned}" ]]; then
        echo "${ROOT}/${owned} exists: this script sets up an installation of its own and removes it on exit. Run it in a checkout without one." >&2
        exit 1
    fi
done

clean_up() {
    docker rm -f "${P}-sut" "${P}-proxy" "${P}-db" "${P}-tck" >/dev/null 2>&1 || true
    docker network rm "${NETWORK}" >/dev/null 2>&1 || true
    if [[ "${WROTE_CONFIG}" == 1 ]]; then
        rm -f "${ROOT}/config/system/settings.php" "${ROOT}/config/system/additional.php"
        rm -rf "${ROOT}/config/sites/a2a" "${ROOT}/var/${P}-router.php"
    fi
}
trap clean_up EXIT

php_run() {
    docker run --rm --network "${NETWORK}" -v "${ROOT}:${ROOT}" -w "${ROOT}" -u "${UIDGID}" \
        -e TYPO3_CONTEXT=Testing "${IMAGE_PHP}" "$@"
}

sql() {
    docker exec "${P}-db" mariadb -uroot -proot typo3 -e "$1"
}

docker network create "${NETWORK}" >/dev/null
docker run -d --name "${P}-db" --network "${NETWORK}" -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=typo3 "${IMAGE_DB}" >/dev/null
for _ in $(seq 1 60); do
    # Over TCP: during its initialisation the server answers on the socket only.
    docker exec "${P}-db" mariadb-admin ping -h 127.0.0.1 --protocol=tcp -uroot -proot --silent >/dev/null 2>&1 && break
    sleep 1
done

# The installation (as the shared e2e workflow sets one up).
WROTE_CONFIG=1
mkdir -p config/system config/sites/a2a
cat > config/system/settings.php <<PHP
<?php
return ['DB' => ['Connections' => ['Default' => ['driver' => 'mysqli', 'host' => '${P}-db', 'port' => 3306, 'dbname' => 'typo3', 'user' => 'root', 'password' => 'root', 'charset' => 'utf8mb4']]], 'SYS' => ['encryptionKey' => 'a2a-placeholder-overwritten-by-typo3-setup']];
PHP
php_run .Build/bin/typo3 setup --driver=mysqli --host="${P}-db" --port=3306 --dbname=typo3 --username=root --password=root \
    --admin-username=admin --admin-user-password='Joh316!!' --admin-email=admin@example.com --project-name='A2A server' \
    --server-type=other --no-interaction --force
php_run .Build/bin/typo3 extension:setup --no-interaction
cat > config/system/additional.php <<'PHP'
<?php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = '.*';
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm']['a2a']['server'] = [
    'enabled' => '1', 'blockingWaitSeconds' => '20', 'maxWaitingRequests' => '8', 'maxRequestBytes' => '1048576',
];
PHP
cat > config/sites/a2a/config.yaml <<'YAML'
rootPageId: 1
base: /
languages:
  - languageId: 0
    title: English
    locale: en_US.UTF-8
    base: /
YAML
printf "nrllm:\n  a2a:\n    baseUrl: '%s'\n" "${BASE}" > config/sites/a2a/settings.yaml

# The technical user, an LLM configuration, the fixture profile and a client.
# The fixture's pause names ask_choice, which the technical user has to be
# allowed to answer (spec 051 A2A-MT2), so the tool is enabled.
# workspace_perms 1 is the live access a user created in the backend has
# (the TCA default); a raw INSERT would leave it 0, and EXT:workspaces then
# gives the user no workspace to act in.
sql "INSERT INTO be_users (uid, username, admin, disable, deleted, workspace_perms) VALUES (90, 'a2a-technical', 0, 0, 0, 1);
INSERT INTO tx_nrllm_provider (uid, identifier, name, adapter_type, is_active) VALUES (90, 'a2a-fixture', 'Fixture', 'ollama', 1);
INSERT INTO tx_nrllm_model (uid, identifier, name, provider_uid, model_id, is_active) VALUES (90, 'a2a-fixture', 'Fixture', 90, 'fixture', 1);
INSERT INTO tx_nrllm_configuration (uid, identifier, name, model_uid, is_active) VALUES (90, 'a2a-fixture', 'Fixture', 90, 1);
INSERT INTO tx_nrllm_a2a_profile (uid, skill_id, name, description, tags, handler, configuration_uid, public) VALUES (1, 'tck', 'TCK fixture', 'Answers by messageId prefix.', 'tck', 'nr_llm.tck_fixture', 90, 1);
INSERT INTO tx_nrllm_a2a_client (uid, title, be_user, workspace, profiles, task_limit, enabled) VALUES (1, 'A2A test client', 90, 0, '1', 100, 1);
INSERT INTO tx_nrllm_tool_state (tool_name, enabled) VALUES ('ask_choice', 1);"
TOKEN="$(php_run .Build/bin/typo3 nrllm:a2a:client:token 1 | tail -n 1)"
php_run .Build/bin/typo3 cache:flush >/dev/null

# The server, with a sweep loop as its worker.
cat > var/${P}-router.php <<'PHP'
<?php
$uri = urldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$path = $_SERVER['DOCUMENT_ROOT'] . $uri;
if ($uri !== '/' && is_file($path)) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
PHP
docker run -d --name "${P}-sut" --network "${NETWORK}" -v "${ROOT}:${ROOT}" -w "${ROOT}" -u "${UIDGID}" \
    -e TYPO3_CONTEXT=Testing -e PHP_CLI_SERVER_WORKERS=8 "${IMAGE_PHP}" \
    sh -c 'while true; do .Build/bin/typo3 nrllm:agent:work --limit=5 --time-limit=20 >/dev/null 2>&1; sleep 20; done & exec php -d opcache.jit=disable -S 0.0.0.0:8080 -t .Build/Web var/'"${P}"'-router.php' >/dev/null

PROXY_TOKEN=""
[[ "${MODE}" == "tck" ]] && PROXY_TOKEN="${TOKEN}"
docker build -q -t "${PREFIX}-tck-image" -f "${ROOT}/Build/a2a-server/Dockerfile.tck" "${ROOT}/Build/a2a-server" >/dev/null
docker run -d --name "${P}-proxy" --network "${NETWORK}" -e A2A_UPSTREAM="${P}-sut:8080" -e A2A_TOKEN="${PROXY_TOKEN}" \
    --entrypoint python "${PREFIX}-tck-image" -I /app/auth_proxy.py 8080 >/dev/null

ready=0
for _ in $(seq 1 60); do
    if docker run --rm --network "${NETWORK}" "${IMAGE_PHP}" php -r \
        "exit(@file_get_contents('${BASE}/.well-known/agent-card.json') === false ? 1 : 0);" >/dev/null 2>&1; then
        ready=1
        break
    fi
    sleep 1
done
if [[ "${ready}" != 1 ]]; then
    echo "The agent card at ${BASE}/.well-known/agent-card.json never became ready." >&2
    exit 1
fi

if [[ "${MODE}" == "tck" ]]; then
    rm -rf "${ROOT}/.Build/a2a-tck-reports"
    mkdir -p "${ROOT}/.Build/a2a-tck-reports"
    status=0
    docker run --name "${P}-tck" --network "${NETWORK}" "${PREFIX}-tck-image" \
        python run_tck.py --sut-host "${BASE}" --transport jsonrpc || status=$?
    docker cp "${P}-tck:/tck/reports/." "${ROOT}/.Build/a2a-tck-reports/" >/dev/null 2>&1 || true
    if [[ -f "${ROOT}/.Build/a2a-tck-reports/compatibility.json" ]]; then
        python3 - "${ROOT}/.Build/a2a-tck-reports/compatibility.json" <<'PY' || true
import json, sys
report = json.load(open(sys.argv[1]))
summary = report.get('summary', report)
print('TCK summary: ' + json.dumps(summary, indent=2)[:3000])
PY
    fi
    # The score is reported, not gated: the manual items (TLS, card
    # signing, AUTH-SERVER-001, CARD-CLIENT-001, BIND-CUSTOM-002) stay open,
    # and stages 4 and 5 are not built (A2A-SV11).
    echo "TCK exit status: ${status} (reported, not a gate)"
    if [[ ! -f "${ROOT}/.Build/a2a-tck-reports/compatibility.json" ]]; then
        echo "The TCK produced no report: it did not run to the end." >&2
        exit 1
    fi
    exit 0
fi

docker build -q -t a2a-s1-partner-image "${ROOT}/Build/a2a-interop" >/dev/null
docker run --rm --network "${NETWORK}" -v "${ROOT}/Build/a2a-server:/client:ro" --entrypoint python \
    a2a-s1-partner-image -I /client/interop_client.py "${BASE}" "${TOKEN}"
