<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use DateTimeInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Service for tracking specialized AI service usage.
 *
 * Tracks usage metrics (requests, tokens, characters, audio, images)
 * with daily aggregation for cost monitoring and quota enforcement.
 */
final class UsageTrackerService implements UsageTrackerServiceInterface, SingletonInterface
{
    private const TABLE = 'tx_nrllm_service_usage';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Track service usage with daily aggregation.
     *
     * @param string $serviceType The service type (translation, speech, image)
     * @param string $provider    The provider name (deepl, whisper, dall-e, etc.)
     * @param array{
     *     tokens?: int,
     *     characters?: int,
     *     audioSeconds?: int,
     *     images?: int,
     *     cost?: float,
     * } $metrics Usage metrics to track
     * @param int|null $configurationUid Optional LlmConfiguration UID
     */
    public function trackUsage(
        string $serviceType,
        string $provider,
        array $metrics = [],
        ?int $configurationUid = null,
    ): void {
        $beUser = $this->getCurrentBackendUserId();
        $today = strtotime('today');
        $now = time();

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $queryBuilder = $connection->createQueryBuilder();

        // Check if record exists for today
        $existingUid = $queryBuilder
            ->select('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('service_type', $queryBuilder->createNamedParameter($serviceType)),
                $queryBuilder->expr()->eq('service_provider', $queryBuilder->createNamedParameter($provider)),
                $queryBuilder->expr()->eq('be_user', $beUser),
                $queryBuilder->expr()->eq('request_date', $today),
            )
            ->executeQuery()
            ->fetchOne();

        if ($existingUid !== false) {
            // Update existing record with incremental values
            $connection->executeStatement(
                'UPDATE ' . self::TABLE . ' SET
                    request_count = request_count + 1,
                    tokens_used = tokens_used + :tokens,
                    characters_used = characters_used + :characters,
                    audio_seconds_used = audio_seconds_used + :audioSeconds,
                    images_generated = images_generated + :images,
                    estimated_cost = estimated_cost + :cost,
                    tstamp = :tstamp
                WHERE uid = :uid',
                [
                    'tokens' => $metrics['tokens'] ?? 0,
                    'characters' => $metrics['characters'] ?? 0,
                    'audioSeconds' => $metrics['audioSeconds'] ?? 0,
                    'images' => $metrics['images'] ?? 0,
                    'cost' => $metrics['cost'] ?? 0.0,
                    'tstamp' => $now,
                    'uid' => $existingUid,
                ],
            );
        } else {
            // Insert new record for today
            $connection->insert(self::TABLE, [
                'pid' => 0,
                'service_type' => $serviceType,
                'service_provider' => $provider,
                'configuration_uid' => $configurationUid ?? 0,
                'be_user' => $beUser,
                'request_count' => 1,
                'tokens_used' => $metrics['tokens'] ?? 0,
                'characters_used' => $metrics['characters'] ?? 0,
                'audio_seconds_used' => $metrics['audioSeconds'] ?? 0,
                'images_generated' => $metrics['images'] ?? 0,
                'estimated_cost' => $metrics['cost'] ?? 0.0,
                'request_date' => $today,
                'tstamp' => $now,
                'crdate' => $now,
            ]);
        }
    }

    /**
     * Get usage report for a service type within a date range.
     *
     * @param string            $serviceType The service type to report on
     * @param DateTimeInterface $from        Start date
     * @param DateTimeInterface $to          End date
     *
     * @return array<int, array{
     *     service_provider: string,
     *     total_requests: int,
     *     total_tokens: int,
     *     total_characters: int,
     *     total_audio_seconds: int,
     *     total_images: int,
     *     total_cost: float,
     * }>
     */
    public function getUsageReport(
        string $serviceType,
        DateTimeInterface $from,
        DateTimeInterface $to,
    ): array {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return $queryBuilder
            ->select('service_provider')
            ->addSelectLiteral('SUM(request_count) as total_requests')
            ->addSelectLiteral('SUM(tokens_used) as total_tokens')
            ->addSelectLiteral('SUM(characters_used) as total_characters')
            ->addSelectLiteral('SUM(audio_seconds_used) as total_audio_seconds')
            ->addSelectLiteral('SUM(images_generated) as total_images')
            ->addSelectLiteral('SUM(estimated_cost) as total_cost')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('service_type', $queryBuilder->createNamedParameter($serviceType)),
                $queryBuilder->expr()->gte('request_date', $from->getTimestamp()),
                $queryBuilder->expr()->lte('request_date', $to->getTimestamp()),
            )
            ->groupBy('service_provider')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Get usage for a specific backend user.
     *
     * @param int               $beUserUid Backend user UID
     * @param DateTimeInterface $from      Start date
     * @param DateTimeInterface $to        End date
     *
     * @return array<int, array{
     *     service_type: string,
     *     service_provider: string,
     *     total_requests: int,
     *     total_cost: float,
     * }>
     */
    public function getUserUsage(
        int $beUserUid,
        DateTimeInterface $from,
        DateTimeInterface $to,
    ): array {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return $queryBuilder
            ->select('service_type', 'service_provider')
            ->addSelectLiteral('SUM(request_count) as total_requests')
            ->addSelectLiteral('SUM(estimated_cost) as total_cost')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('be_user', $beUserUid),
                $queryBuilder->expr()->gte('request_date', $from->getTimestamp()),
                $queryBuilder->expr()->lte('request_date', $to->getTimestamp()),
            )
            ->groupBy('service_type', 'service_provider')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Get today's usage for a specific service and user.
     *
     * @return array{
     *     request_count: int,
     *     tokens_used: int,
     *     characters_used: int,
     *     audio_seconds_used: int,
     *     images_generated: int,
     *     estimated_cost: float,
     * }|null
     */
    public function getTodayUsage(string $serviceType, string $provider): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        $result = $queryBuilder
            ->select(
                'request_count',
                'tokens_used',
                'characters_used',
                'audio_seconds_used',
                'images_generated',
                'estimated_cost',
            )
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('service_type', $queryBuilder->createNamedParameter($serviceType)),
                $queryBuilder->expr()->eq('service_provider', $queryBuilder->createNamedParameter($provider)),
                $queryBuilder->expr()->eq('be_user', $this->getCurrentBackendUserId()),
                $queryBuilder->expr()->eq('request_date', strtotime('today')),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $result !== false ? $result : null;
    }

    /**
     * Get total estimated cost for current month.
     */
    public function getCurrentMonthCost(): float
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $firstDayOfMonth = strtotime('first day of this month midnight');

        $result = $queryBuilder
            ->addSelectLiteral('SUM(estimated_cost) as total_cost')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->gte('request_date', $firstDayOfMonth),
            )
            ->executeQuery()
            ->fetchOne();

        return (float)($result ?? 0);
    }

    /**
     * Get current backend user ID.
     */
    private function getCurrentBackendUserId(): int
    {
        return (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
    }
}
