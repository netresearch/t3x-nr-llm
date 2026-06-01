<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * DEV-ONLY historic usage generator for the LLM Analytics dashboard.
 * NOT part of the shipped extension. Run via `ddev seed-usage`.
 *
 * Usage: php seed-usage.php [database=v14] [days=90]
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

// 1) Demo model catalog. The analytics breakdowns read the DENORMALIZED
//    service_provider / model_id columns on the usage table, so we do NOT
//    create tx_nrllm_provider / tx_nrllm_model rows (those would only clutter
//    the Providers/Models backend lists for zero dashboard benefit). model_uid
//    is a stable synthetic id (high range — purely an aggregation-key
//    discriminator). cost_* are cents per 1M tokens.
$models = [
    ['uid' => 901, 'modelId' => 'gpt-4o',            'provider' => 'openai', 'costIn' => 250,  'costOut' => 1000],
    ['uid' => 902, 'modelId' => 'gpt-4o-mini',       'provider' => 'openai', 'costIn' => 15,   'costOut' => 60],
    ['uid' => 903, 'modelId' => 'claude-3-7-sonnet', 'provider' => 'claude', 'costIn' => 300,  'costOut' => 1500],
    ['uid' => 904, 'modelId' => 'gemini-2.5-flash',  'provider' => 'gemini', 'costIn' => 30,   'costOut' => 120],
    ['uid' => 905, 'modelId' => 'qwen3:0.6b',        'provider' => 'ollama', 'costIn' => 0,    'costOut' => 0],
];

// 2) Demo backend users + monthly cost budgets.
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

// 3) Clear prior usage rows (dev DB only) so re-running does not double-count.
$pdo->prepare('DELETE FROM tx_nrllm_service_usage')->execute();

// 4) Generate ~$days of daily rows. Service mix + per-service token profile.
$services = [
    'chat'        => 0.50,
    'complete'    => 0.15,
    'embed'       => 0.15,
    'vision'      => 0.10,
    'translation' => 0.10,
];

$insert = $pdo->prepare(
    'INSERT INTO tx_nrllm_service_usage
        (pid, service_type, service_provider, configuration_uid, model_uid, model_id, be_user,
         request_count, tokens_used, prompt_tokens, completion_tokens, characters_used,
         audio_seconds_used, images_generated, estimated_cost, request_date, tstamp, crdate)
     VALUES
        (0, :service_type, :service_provider, 0, :model_uid, :model_id, :be_user,
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
        foreach ($models as $m) {
            foreach ($services as $service => $weight) {
                $requests = (int)round(mt_rand(2, 12) * $weight * $weekend * $trend * $spike);
                if ($requests <= 0) {
                    continue;
                }
                $promptTokens = $requests * mt_rand(300, 1500);
                $completionTokens = $service === 'embed' ? 0 : $requests * mt_rand(100, 800);
                $tokens = $promptTokens + $completionTokens;
                $cost = ($promptTokens / 1_000_000) * ($m['costIn'] / 100)
                    + ($completionTokens / 1_000_000) * ($m['costOut'] / 100);

                $insert->execute([
                    'service_type' => $service,
                    'service_provider' => $m['provider'],
                    'model_uid' => $m['uid'],
                    'model_id' => $m['modelId'],
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
}

echo sprintf("Seeded %d usage rows across %d days into '%s'.\n", $rowCount, $days, $dbName);
echo "Demo users: admin (#$adminUid), demo_anna, demo_bob (monthly cost budgets set).\n";
