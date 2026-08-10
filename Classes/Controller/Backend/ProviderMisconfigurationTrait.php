<?php

declare(strict_types=1);

/*
 * This file is part of the package netresearch/nr-llm.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Psr\Log\LoggerInterface;

/**
 * Turns a provider misconfiguration into a message the administrator can act on.
 *
 * REC #8b keeps raw provider error bodies out of the backend UI, because they
 * carry endpoints, payloads and model internals. A ProviderConfigurationException
 * is not one of those: every message of that type is authored in this extension
 * and names the setting at fault — a missing API key identifier, an endpoint host
 * rejected by the SSRF filter, a model without a provider. Withholding it buys no
 * safety and costs the one person who can fix it a trip through the system log.
 *
 * Sanitizing here rather than at each call site is deliberate. Only some of the
 * consumers reach the client through ErrorResponse, which redacts
 * credential-bearing URL parameters at the response boundary; flash messages and
 * hand-built JsonResponse payloads do not. Routing every site through this method
 * means the redaction cannot be forgotten at the next one.
 */
trait ProviderMisconfigurationTrait
{
    use ErrorMessageSanitizerTrait;

    /**
     * Log the misconfiguration and return the message to show the administrator.
     *
     * @param string               $operation  what was attempted, for the log entry
     * @param array<string, mixed> $logContext additional log context
     */
    protected function describeMisconfiguration(
        ProviderConfigurationException $e,
        LoggerInterface $logger,
        string $operation,
        array $logContext = [],
    ): string {
        $logger->error($operation . ': provider is misconfigured', ['exception' => $e] + $logContext);

        return $this->sanitizeErrorMessage($e->getMessage());
    }
}
