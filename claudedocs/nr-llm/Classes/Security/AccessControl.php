<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Security;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Access control for LLM features with TYPO3 permission integration.
 *
 * Permission Levels:
 * - use_llm: Basic usage (generate content, send prompts)
 * - configure_prompts: Configure system prompts and templates
 * - manage_keys: Manage API keys
 * - view_reports: View usage reports and analytics
 * - admin_all: Full administrative access
 *
 * Access Control Dimensions:
 * - User permissions (BE user/group)
 * - Site-based access (multi-site isolation)
 * - Feature-based permissions (granular capabilities)
 * - Quota enforcement (rate limiting per user/site)
 */
class AccessControl implements SingletonInterface
{
    // Permission constants
    public const PERMISSION_USE_LLM = 'use_llm';
    public const PERMISSION_CONFIGURE_PROMPTS = 'configure_prompts';
    public const PERMISSION_MANAGE_KEYS = 'manage_keys';
    public const PERMISSION_VIEW_REPORTS = 'view_reports';
    public const PERMISSION_ADMIN_ALL = 'admin_all';

    // Module names for permission checks
    private const MODULE_LLM = 'nrllm';
    private const MODULE_LLM_KEYS = 'nrllm_keys';
    private const MODULE_LLM_REPORTS = 'nrllm_reports';

    private Context $context;
    private AuditLogger $auditLogger;

    public function __construct(
        ?Context $context = null,
        ?AuditLogger $auditLogger = null,
    ) {
        $this->context = $context ?? GeneralUtility::makeInstance(Context::class);
        $this->auditLogger = $auditLogger ?? GeneralUtility::makeInstance(AuditLogger::class);
    }

    /**
     * Check if current user has specific permission.
     *
     * @param string    $permission Permission constant
     * @param Site|null $site       Optional site context
     *
     * @return bool True if user has permission
     */
    public function hasPermission(string $permission, ?Site $site = null): bool
    {
        $user = $this->getBackendUser();

        if (!$user) {
            $this->auditLogger->logAccessDenied($permission, null, 'No backend user');
            return false;
        }

        // Admin users have all permissions
        if ($user->isAdmin()) {
            return true;
        }

        // Check if user has admin_all permission (grants everything)
        if ($this->checkUserPermission($user, self::PERMISSION_ADMIN_ALL)) {
            return true;
        }

        // Check specific permission
        $hasPermission = $this->checkUserPermission($user, $permission);

        // If site context is provided, verify site access
        if ($hasPermission && $site !== null) {
            $hasPermission = $this->checkSiteAccess($user, $site);
        }

        if (!$hasPermission) {
            $this->auditLogger->logAccessDenied(
                $permission,
                $user->user['uid'],
                $site ? "Site: {$site->getIdentifier()}" : 'Global',
            );
        }

        return $hasPermission;
    }

    /**
     * Check if user can use LLM features.
     *
     * @param Site|null $site Site context
     *
     * @return bool True if user can use LLM
     */
    public function canUseLlm(?Site $site = null): bool
    {
        return $this->hasPermission(self::PERMISSION_USE_LLM, $site);
    }

    /**
     * Check if user can configure prompts.
     *
     * @param Site|null $site Site context
     *
     * @return bool True if user can configure prompts
     */
    public function canConfigurePrompts(?Site $site = null): bool
    {
        return $this->hasPermission(self::PERMISSION_CONFIGURE_PROMPTS, $site);
    }

    /**
     * Check if user can manage API keys.
     *
     * @param Site|null $site Site context
     *
     * @return bool True if user can manage keys
     */
    public function canManageKeys(?Site $site = null): bool
    {
        return $this->hasPermission(self::PERMISSION_MANAGE_KEYS, $site);
    }

    /**
     * Check if user can view reports.
     *
     * @param Site|null $site Site context
     *
     * @return bool True if user can view reports
     */
    public function canViewReports(?Site $site = null): bool
    {
        return $this->hasPermission(self::PERMISSION_VIEW_REPORTS, $site);
    }

    /**
     * Enforce permission check (throws exception if denied).
     *
     * @param string    $permission Permission to check
     * @param Site|null $site       Site context
     *
     * @throws AccessDeniedException
     */
    public function requirePermission(string $permission, ?Site $site = null): void
    {
        if (!$this->hasPermission($permission, $site)) {
            $user = $this->getBackendUser();
            $userId = $user ? $user->user['uid'] : 'anonymous';

            throw new AccessDeniedException(
                "Access denied: User {$userId} lacks permission '{$permission}'",
                1703002000,
            );
        }
    }

    /**
     * Check if user can access a specific site.
     *
     * @param Site $site Site to check
     *
     * @return bool True if user has access to site
     */
    public function canAccessSite(Site $site): bool
    {
        $user = $this->getBackendUser();

        if (!$user || !$user->isInWebMount($site->getRootPageId())) {
            return false;
        }

        return true;
    }

    /**
     * Get list of sites user can access.
     *
     * @return array<Site> Array of accessible sites
     */
    public function getAccessibleSites(): array
    {
        $user = $this->getBackendUser();

        if (!$user) {
            return [];
        }

        // Admin can access all sites
        if ($user->isAdmin()) {
            $siteFinder = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Site\SiteFinder::class);
            return $siteFinder->getAllSites();
        }

        // Filter sites by webmount access
        $siteFinder = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Site\SiteFinder::class);
        $allSites = $siteFinder->getAllSites();
        $accessibleSites = [];

        foreach ($allSites as $site) {
            if ($this->canAccessSite($site)) {
                $accessibleSites[] = $site;
            }
        }

        return $accessibleSites;
    }

    /**
     * Check quota for user (rate limiting).
     *
     * @param string $quotaType Type of quota (e.g., 'requests_per_hour')
     * @param int    $limit     Limit value
     *
     * @return bool True if within quota
     */
    public function checkQuota(string $quotaType, int $limit): bool
    {
        $user = $this->getBackendUser();

        if (!$user) {
            return false;
        }

        // Admin users bypass quota checks
        if ($user->isAdmin()) {
            return true;
        }

        // Implement quota checking logic
        $usage = $this->getQuotaUsage($user->user['uid'], $quotaType);

        if ($usage >= $limit) {
            $this->auditLogger->logQuotaExceeded(
                $user->user['uid'],
                $quotaType,
                $usage,
                $limit,
            );
            return false;
        }

        return true;
    }

    /**
     * Record quota usage for a user.
     *
     * @param string $quotaType Type of quota
     * @param int    $amount    Amount to record (default 1)
     */
    public function recordQuotaUsage(string $quotaType, int $amount = 1): void
    {
        $user = $this->getBackendUser();

        if (!$user) {
            return;
        }

        // Store quota usage in cache or database
        $cacheIdentifier = $this->getQuotaCacheIdentifier($user->user['uid'], $quotaType);
        $cache = $this->getQuotaCache();

        $currentUsage = $cache->get($cacheIdentifier) ?? 0;
        $newUsage = $currentUsage + $amount;

        // Cache for appropriate duration based on quota type
        $lifetime = $this->getQuotaLifetime($quotaType);
        $cache->set($cacheIdentifier, $newUsage, [], $lifetime);
    }

    /**
     * Get current quota usage for a user.
     *
     * @param int    $userId    User ID
     * @param string $quotaType Type of quota
     *
     * @return int Current usage
     */
    private function getQuotaUsage(int $userId, string $quotaType): int
    {
        $cacheIdentifier = $this->getQuotaCacheIdentifier($userId, $quotaType);
        $cache = $this->getQuotaCache();

        return (int)($cache->get($cacheIdentifier) ?? 0);
    }

    /**
     * Check user-specific permission flag.
     *
     * @param BackendUserAuthentication $user       Backend user
     * @param string                    $permission Permission name
     *
     * @return bool True if user has permission
     */
    private function checkUserPermission(BackendUserAuthentication $user, string $permission): bool
    {
        // Check TSconfig for permission
        $tsConfig = $user->getTSConfig();
        $permissions = $tsConfig['tx_nrllm.']['permissions.'] ?? [];

        // Permission explicitly granted
        if (isset($permissions[$permission]) && (int)$permissions[$permission] === 1) {
            return true;
        }

        // Check user groups
        return $this->checkGroupPermission($user, $permission);
    }

    /**
     * Check if any of user's groups have permission.
     *
     * @param BackendUserAuthentication $user       Backend user
     * @param string                    $permission Permission name
     *
     * @return bool True if any group has permission
     */
    private function checkGroupPermission(BackendUserAuthentication $user, string $permission): bool
    {
        $groups = $user->userGroups;

        if (empty($groups)) {
            return false;
        }

        foreach ($groups as $group) {
            $groupTsConfig = BackendUtility::getPagesTSconfig($group['uid']);
            $permissions = $groupTsConfig['tx_nrllm.']['permissions.'] ?? [];

            if (isset($permissions[$permission]) && (int)$permissions[$permission] === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has access to specific site.
     *
     * @param BackendUserAuthentication $user Backend user
     * @param Site                      $site Site to check
     *
     * @return bool True if user has site access
     */
    private function checkSiteAccess(BackendUserAuthentication $user, Site $site): bool
    {
        // Admin has access to all sites
        if ($user->isAdmin()) {
            return true;
        }

        // Check if user's webmount includes site root page
        $rootPageId = $site->getRootPageId();

        return $user->isInWebMount($rootPageId);
    }

    /**
     * Get cache identifier for quota tracking.
     *
     * @param int    $userId    User ID
     * @param string $quotaType Quota type
     *
     * @return string Cache identifier
     */
    private function getQuotaCacheIdentifier(int $userId, string $quotaType): string
    {
        return "nrllm_quota_{$userId}_{$quotaType}_" . date('YmdH');
    }

    /**
     * Get quota lifetime in seconds based on type.
     *
     * @param string $quotaType Quota type
     *
     * @return int Lifetime in seconds
     */
    private function getQuotaLifetime(string $quotaType): int
    {
        $lifetimes = [
            'requests_per_hour' => 3600,
            'requests_per_day' => 86400,
            'tokens_per_hour' => 3600,
            'tokens_per_day' => 86400,
        ];

        return $lifetimes[$quotaType] ?? 3600;
    }

    /**
     * Get quota cache instance.
     */
    private function getQuotaCache(): \TYPO3\CMS\Core\Cache\Frontend\FrontendInterface
    {
        return GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)
            ->getCache('nrllm_quota');
    }

    /**
     * Get current backend user.
     */
    private function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
