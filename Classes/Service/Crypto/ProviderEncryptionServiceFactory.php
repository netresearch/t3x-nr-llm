<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Crypto;

/**
 * Factory for creating ProviderEncryptionService instances.
 *
 * This factory injects the TYPO3 encryption key from configuration
 * into the ProviderEncryptionService via dependency injection.
 */
final class ProviderEncryptionServiceFactory
{
    public static function create(): ProviderEncryptionService
    {
        $encryptionKey = self::getEncryptionKey();

        return new ProviderEncryptionService($encryptionKey);
    }

    private static function getEncryptionKey(): string
    {
        /** @var array{SYS?: array{encryptionKey?: string}} $confVars */
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        $sysConf = $confVars['SYS'] ?? [];

        return $sysConf['encryptionKey'] ?? '';
    }
}
