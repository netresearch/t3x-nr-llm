<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * DEV-ONLY historic usage generator for the LLM Analytics dashboard.
 * NOT part of the shipped extension. Run via `ddev seed-usage`.
 *
 * Usage: php seed-usage.php [database=v14] [days=90]
 *
 * Creates a small graph of REAL records — paid providers, models,
 * configurations and tasks — then writes ~`days` of daily usage rows that
 * reference their real uids (plus the denormalized provider/model strings and
 * task_uid). This way the Analytics dashboard shows non-zero cost AND the
 * per-row usage columns on the Providers / Models / Configurations / Tasks
 * list views all light up. One free Ollama stream reuses whatever
 * `ddev seed-ollama` already created (so its records show usage too).
 */

declare(strict_types=1);

$dbName = $argv[1] ?? 'v14';
$days   = max(1, (int)($argv[2] ?? 90));

$host = getenv('TYPO3_DB_HOST') ?: 'db';
$user = getenv('TYPO3_DB_USERNAME') ?: 'root';
$pass = getenv('TYPO3_DB_PASSWORD') ?: 'root';

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $dbName),
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

mt_srand(20260601); // deterministic output

$now = time();
$today = strtotime('today', $now);

/** Find-or-create a row in $table matching $match; returns its uid. */
$ensure = static function (PDO $pdo, string $table, array $match, array $insert): int {
    $where = implode(' AND ', array_map(static fn(string $c): string => "$c = :$c", array_keys($match)));
    $sel = $pdo->prepare("SELECT uid FROM $table WHERE $where LIMIT 1");
    $sel->execute($match);
    $uid = $sel->fetchColumn();
    if ($uid !== false) {
        return (int)$uid;
    }
    $cols = array_keys($insert);
    $stmt = $pdo->prepare(
        "INSERT INTO $table (" . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ')'
    );
    $stmt->execute($insert);
    return (int)$pdo->lastInsertId();
};

// ---------------------------------------------------------------------------
// 1) Real record graph. Each "stream" is a coherent (provider, model, config,
//    task) chain so every list view shows usage on real rows. cost_* are cents
//    per 1M tokens. The last stream reuses the free Ollama records that
//    `ddev seed-ollama` created (found by identifier) so they show usage too.
// ---------------------------------------------------------------------------

$ensureProvider = static function (PDO $pdo, callable $ensure, string $identifier, string $name, string $adapter) use ($now): int {
    return $ensure($pdo, 'tx_nrllm_provider', ['identifier' => $identifier], [
        'pid' => 0, 'identifier' => $identifier, 'name' => $name, 'description' => 'Demo seed provider (analytics).',
        'adapter_type' => $adapter, 'endpoint_url' => '', 'api_key' => '', 'organization_id' => '',
        'is_active' => 1, 'priority' => 50, 'sorting' => 50, 'tstamp' => $now, 'crdate' => $now,
        'deleted' => 0, 'hidden' => 0,
    ]);
};
$ensureModel = static function (PDO $pdo, callable $ensure, string $identifier, string $name, int $providerUid, string $modelId, int $costIn, int $costOut) use ($now): int {
    return $ensure($pdo, 'tx_nrllm_model', ['identifier' => $identifier], [
        'pid' => 0, 'identifier' => $identifier, 'name' => $name, 'description' => 'Demo seed model (analytics).',
        'provider_uid' => $providerUid, 'model_id' => $modelId, 'capabilities' => 'chat,completion',
        'cost_input' => $costIn, 'cost_output' => $costOut, 'is_active' => 1, 'is_default' => 0,
        'sorting' => 50, 'tstamp' => $now, 'crdate' => $now, 'deleted' => 0, 'hidden' => 0,
    ]);
};
$ensureConfig = static function (PDO $pdo, callable $ensure, string $identifier, string $name, int $modelUid) use ($now): int {
    return $ensure($pdo, 'tx_nrllm_configuration', ['identifier' => $identifier], [
        'pid' => 0, 'identifier' => $identifier, 'name' => $name, 'description' => 'Demo seed configuration (analytics).',
        'model_uid' => $modelUid, 'system_prompt' => 'You are a helpful assistant.',
        'temperature' => 0.7, 'max_tokens' => 1024, 'top_p' => 0.9, 'is_active' => 1, 'is_default' => 0,
        'sorting' => 50, 'tstamp' => $now, 'crdate' => $now, 'deleted' => 0, 'hidden' => 0,
    ]);
};
$ensureTask = static function (PDO $pdo, callable $ensure, string $identifier, string $name, string $category, int $configUid) use ($now): int {
    return $ensure($pdo, 'tx_nrllm_task', ['identifier' => $identifier], [
        'pid' => 0, 'identifier' => $identifier, 'name' => $name, 'description' => 'Demo seed task (analytics).',
        'category' => $category, 'configuration_uid' => $configUid,
        'prompt_template' => 'Process the following input:' . "\n\n{{input}}",
        'input_type' => 'manual', 'output_format' => 'markdown', 'is_active' => 1, 'is_system' => 0,
        'sorting' => 50, 'tstamp' => $now, 'crdate' => $now, 'deleted' => 0, 'hidden' => 0,
    ]);
};

// Paid providers (adapter string == usage.service_provider, which the Providers
// list aggregates on via Provider::getAdapterType()).
$openai = $ensureProvider($pdo, $ensure, 'demo-openai', 'Demo OpenAI', 'openai');
$claude = $ensureProvider($pdo, $ensure, 'demo-anthropic', 'Demo Anthropic', 'claude');
$google = $ensureProvider($pdo, $ensure, 'demo-google', 'Demo Google', 'gemini');

// Free Ollama stream — reuse whatever seed-ollama created (fallback: skip).
$ollamaModelUid = (int)($pdo->query("SELECT uid FROM tx_nrllm_model WHERE model_id = 'qwen3:4b' AND deleted = 0 ORDER BY uid ASC LIMIT 1")->fetchColumn() ?: 0);
$ollamaConfigUid = (int)($pdo->query("SELECT uid FROM tx_nrllm_configuration WHERE identifier = 'local-general' AND deleted = 0 ORDER BY uid ASC LIMIT 1")->fetchColumn() ?: 0);

/** @var list<array{adapter:string,modelUid:int,modelId:string,costIn:int,costOut:int,configUid:int,taskUid:int,service:string}> $streams */
$streams = [];
$build = static function (string $adapter, int $modelUid, string $modelId, int $costIn, int $costOut, int $configUid, int $taskUid, string $service): array {
    return ['adapter' => $adapter, 'modelUid' => $modelUid, 'modelId' => $modelId, 'costIn' => $costIn, 'costOut' => $costOut, 'configUid' => $configUid, 'taskUid' => $taskUid, 'service' => $service];
};

// Stream 1 — OpenAI GPT-4o, chat
$m = $ensureModel($pdo, $ensure, 'demo-gpt-4o', 'GPT-4o', $openai, 'gpt-4o', 250, 1000);
$c = $ensureConfig($pdo, $ensure, 'demo-cfg-gpt4o', 'Demo GPT-4o Chat', $m);
$t = $ensureTask($pdo, $ensure, 'demo-extract-entities', 'Extract Entities', 'content_ops', $c);
$streams[] = $build('openai', $m, 'gpt-4o', 250, 1000, $c, $t, 'chat');

// Stream 2 — OpenAI GPT-4o-mini, completion
$m = $ensureModel($pdo, $ensure, 'demo-gpt-4o-mini', 'GPT-4o mini', $openai, 'gpt-4o-mini', 15, 60);
$c = $ensureConfig($pdo, $ensure, 'demo-cfg-gpt4o-mini', 'Demo GPT-4o mini', $m);
$t = $ensureTask($pdo, $ensure, 'demo-alt-text', 'Generate Alt Text', 'content_ops', $c);
$streams[] = $build('openai', $m, 'gpt-4o-mini', 15, 60, $c, $t, 'complete');

// Stream 3 — Anthropic Claude, vision
$m = $ensureModel($pdo, $ensure, 'demo-claude-3-7-sonnet', 'Claude 3.7 Sonnet', $claude, 'claude-3-7-sonnet', 300, 1500);
$c = $ensureConfig($pdo, $ensure, 'demo-cfg-claude', 'Demo Claude Sonnet', $m);
$t = $ensureTask($pdo, $ensure, 'demo-describe-image', 'Describe Image', 'content_ops', $c);
$streams[] = $build('claude', $m, 'claude-3-7-sonnet', 300, 1500, $c, $t, 'vision');

// Stream 4 — Google Gemini Flash, translation
$m = $ensureModel($pdo, $ensure, 'demo-gemini-2-5-flash', 'Gemini 2.5 Flash', $google, 'gemini-2.5-flash', 30, 120);
$c = $ensureConfig($pdo, $ensure, 'demo-cfg-gemini', 'Demo Gemini Flash', $m);
$t = $ensureTask($pdo, $ensure, 'demo-translate', 'Translate Content', 'content_ops', $c);
$streams[] = $build('gemini', $m, 'gemini-2.5-flash', 30, 120, $c, $t, 'translation');

// Stream 5 — free local Ollama, embeddings (reuse real seed-ollama records).
if ($ollamaModelUid > 0 && $ollamaConfigUid > 0) {
    $t = $ensureTask($pdo, $ensure, 'demo-analyze-syslog', 'Analyze Syslog', 'log_analysis', $ollamaConfigUid);
    $streams[] = $build('ollama', $ollamaModelUid, 'qwen3:4b', 0, 0, $ollamaConfigUid, $t, 'embed');
}

// ---------------------------------------------------------------------------
// 2) Demo backend users + monthly cost budgets.
// ---------------------------------------------------------------------------
$adminUid = (int)($pdo->query("SELECT uid FROM be_users WHERE username = 'admin' AND deleted = 0 ORDER BY uid ASC LIMIT 1")->fetchColumn() ?: 1);

$demoUsers = [
    ['demo_anna', 25.00],
    ['demo_bob',  30.00],
];
$userUids = [$adminUid];
foreach ($demoUsers as [$username, $monthlyCostLimit]) {
    $uid = $ensure($pdo, 'be_users', ['username' => $username], [
        'pid' => 0, 'tstamp' => $now, 'crdate' => $now, 'username' => $username,
        'password' => '', 'admin' => 0, 'disable' => 1, 'deleted' => 0,
    ]);
    $userUids[] = $uid;
    $ensure($pdo, 'tx_nrllm_user_budget', ['be_user' => $uid], [
        'pid' => 0, 'be_user' => $uid,
        'max_requests_per_day' => 0, 'max_tokens_per_day' => 0, 'max_cost_per_day' => 0.0,
        'max_requests_per_month' => 0, 'max_tokens_per_month' => 0, 'max_cost_per_month' => $monthlyCostLimit,
        'is_active' => 1, 'tstamp' => $now, 'crdate' => $now, 'deleted' => 0, 'hidden' => 0,
    ]);
}

// ---------------------------------------------------------------------------
// 3) Clear prior usage rows (dev DB only) so re-running does not double-count.
// ---------------------------------------------------------------------------
$pdo->prepare('DELETE FROM tx_nrllm_service_usage')->execute();

// ---------------------------------------------------------------------------
// 4) Generate ~`days` of daily rows, one per (day × user × stream), each row
//    carrying the stream's real provider/model/config/task ids.
// ---------------------------------------------------------------------------
$insert = $pdo->prepare(
    'INSERT INTO tx_nrllm_service_usage
        (pid, service_type, service_provider, configuration_uid, model_uid, model_id, task_uid, be_user,
         request_count, tokens_used, prompt_tokens, completion_tokens, characters_used,
         audio_seconds_used, images_generated, estimated_cost, request_date, tstamp, crdate)
     VALUES
        (0, :service_type, :service_provider, :configuration_uid, :model_uid, :model_id, :task_uid, :be_user,
         :request_count, :tokens_used, :prompt_tokens, :completion_tokens, 0,
         0, 0, :estimated_cost, :request_date, :tstamp, :crdate)'
);

$rowCount = 0;
for ($d = $days - 1; $d >= 0; $d--) {
    $date = strtotime("-{$d} days", $today);
    $dow = (int)date('N', $date);                       // 1..7 (6,7 = weekend)
    $weekend = $dow >= 6 ? 0.45 : 1.0;
    $trend = 0.6 + 0.4 * (($days - $d) / $days);         // ramps 0.6 -> 1.0
    $spike = mt_rand(1, 100) <= 6 ? mt_rand(2, 4) : 1;   // ~6% spike days

    foreach ($userUids as $beUser) {
        foreach ($streams as $s) {
            $requests = (int)round(mt_rand(2, 10) * $weekend * $trend * $spike);
            if ($requests <= 0) {
                continue;
            }
            $promptTokens = $requests * mt_rand(300, 1500);
            $completionTokens = $s['service'] === 'embed' ? 0 : $requests * mt_rand(100, 800);
            $tokens = $promptTokens + $completionTokens;
            $cost = ($promptTokens / 1_000_000) * ($s['costIn'] / 100)
                + ($completionTokens / 1_000_000) * ($s['costOut'] / 100);

            $insert->execute([
                'service_type' => $s['service'],
                'service_provider' => $s['adapter'],
                'configuration_uid' => $s['configUid'],
                'model_uid' => $s['modelUid'],
                'model_id' => $s['modelId'],
                'task_uid' => $s['taskUid'],
                'be_user' => $beUser,
                'request_count' => $requests,
                'tokens_used' => $tokens,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'estimated_cost' => round($cost, 6),
                'request_date' => $date,
                'tstamp' => $now,
                'crdate' => $now,
            ]);
            $rowCount++;
        }
    }
}

echo sprintf("Seeded %d usage rows across %d days into '%s'.\n", $rowCount, $days, $dbName);
echo sprintf("Streams: %d (real providers/models/configs/tasks created/reused).\n", count($streams));
echo "Demo users: admin (#$adminUid), demo_anna, demo_bob (monthly cost budgets set).\n";
