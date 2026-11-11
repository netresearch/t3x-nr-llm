<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Agent;

use GuzzleHttp\Psr7\Uri;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Throwable;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

/**
 * A remote A2A agent the operator registered (`tx_nrllm_a2a_agent`, spec 051
 * A2A-C1).
 *
 * The operator decides its trust: `data_class` has no default and a record
 * without one does nothing, `enabled` starts at 0 and `requires_approval` at 1.
 * The card import fills the card fields and never these (A2A-C1, A2A-C2).
 *
 * The class sits under `Service\Agent\A2a` rather than `Domain\Model`, because
 * every A2A class lives there (ADR-279).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
class A2aRemoteAgent extends AbstractEntity
{
    protected string $identifier = '';

    protected string $title = '';

    protected string $description = '';

    protected string $cardUrl = '';

    protected string $cardJson = '';

    protected ?int $cardFetched = null;

    protected string $cardEtag = '';

    protected string $interfaceUrl = '';

    protected string $interfaceTenant = '';

    protected string $cardCapabilities = '';

    protected bool $cardChanged = false;

    protected string $confirmedInterface = '';

    protected string $importError = '';

    protected string $credential = '';

    protected ?ToolDataClass $dataClass = null;

    protected bool $enabled = false;

    protected bool $requiresApproval = true;

    protected int $maxWaitSeconds = 3600;

    protected int $minPollIntervalSeconds = 5;

    protected int $maxPollIntervalSeconds = 300;

    protected int $maxResponseBytes = 1048576;

    protected int $maxDelegationsPerRun = 3;

    protected int $connectTimeoutSeconds = 5;

    protected int $readTimeoutSeconds = 30;

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getCardUrl(): string
    {
        return $this->cardUrl;
    }

    public function setCardUrl(string $cardUrl): void
    {
        $this->cardUrl = $cardUrl;
    }

    public function getCardJson(): string
    {
        return $this->cardJson;
    }

    /**
     * When the card was last fetched; null when it never was.
     */
    public function getCardFetched(): ?int
    {
        return $this->cardFetched;
    }

    public function getCardEtag(): string
    {
        return $this->cardEtag;
    }

    /**
     * The selected JSONRPC 1.0 interface of the imported card; '' before an import.
     */
    public function getInterfaceUrl(): string
    {
        return $this->interfaceUrl;
    }

    /**
     * The interface's tenant; '' when the card names none (A2A-C2).
     */
    public function getInterfaceTenant(): string
    {
        return $this->interfaceTenant;
    }

    public function getCardCapabilities(): string
    {
        return $this->cardCapabilities;
    }

    public function isCardChanged(): bool
    {
        return $this->cardChanged;
    }

    public function getConfirmedInterface(): string
    {
        return $this->confirmedInterface;
    }

    /**
     * What an administrator confirms: the interface URL, its tenant and the
     * capabilities, as one sha256.
     */
    public static function cardDigest(string $interfaceUrl, string $tenant, string $capabilities): string
    {
        return hash('sha256', $interfaceUrl . "\n" . $tenant . "\n" . $capabilities);
    }

    /**
     * Scheme, lower-cased host and effective port of a URL: where a request,
     * and the credential with it, goes. Null for a URL whose origin is not
     * certain, which never counts as equal to anything.
     *
     * The URL is read by PHP's parser and by the PSR-7 `Uri` the transport
     * sends with, and both must agree. Refused: a scheme other than http and
     * https, userinfo, whitespace, control characters or a backslash anywhere,
     * an empty host, and a host that is neither an ASCII host name nor an IP
     * literal ({@see \Netresearch\NrLlm\Service\Tool\Web\ExternalUrlGuard}
     * applies the same host rule before anything is sent).
     */
    public static function originOf(string $url): ?string
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        try {
            $uri = new Uri($url);
        } catch (Throwable) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host   = rtrim(strtolower($parts['host'] ?? ''), '.');
        if (!in_array($scheme, ['http', 'https'], true) || $host === ''
            || strtolower($uri->getScheme()) !== $scheme || rtrim(strtolower($uri->getHost()), '.') !== $host) {
            return null;
        }

        $bare = str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;
        if (filter_var($bare, FILTER_VALIDATE_IP) === false && preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host) !== 1) {
            return null;
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($uri->getPort() !== null && $uri->getPort() !== $port) {
            return null;
        }

        return sprintf('%s://%s:%d', $scheme, $host, $port);
    }

    /**
     * Whether two URLs certainly have the same origin; an uncertain origin on
     * either side is not the same.
     */
    public static function sameOrigin(string $first, string $second): bool
    {
        $origin = self::originOf($first);

        return $origin !== null && $origin === self::originOf($second);
    }

    public function getImportError(): string
    {
        return $this->importError;
    }

    /**
     * The nr-vault identifier of the credential; '' for an agent without one.
     */
    public function getCredential(): string
    {
        return $this->credential;
    }

    public function setCredential(string $credential): void
    {
        $this->credential = $credential;
    }

    /**
     * The declared data class, or null when none is declared.
     */
    public function getDataClass(): ?ToolDataClass
    {
        return $this->dataClass;
    }

    public function setDataClass(?ToolDataClass $dataClass): void
    {
        $this->dataClass = $dataClass;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function requiresApproval(): bool
    {
        return $this->requiresApproval;
    }

    public function getMaxWaitSeconds(): int
    {
        return $this->maxWaitSeconds;
    }

    public function getMinPollIntervalSeconds(): int
    {
        return $this->minPollIntervalSeconds;
    }

    public function getMaxPollIntervalSeconds(): int
    {
        return $this->maxPollIntervalSeconds;
    }

    public function getMaxResponseBytes(): int
    {
        return $this->maxResponseBytes;
    }

    public function setMaxResponseBytes(int $maxResponseBytes): void
    {
        $this->maxResponseBytes = $maxResponseBytes;
    }

    public function getMaxDelegationsPerRun(): int
    {
        return $this->maxDelegationsPerRun;
    }

    public function getConnectTimeoutSeconds(): int
    {
        return $this->connectTimeoutSeconds;
    }

    public function getReadTimeoutSeconds(): int
    {
        return $this->readTimeoutSeconds;
    }

    /**
     * Why the client may not import a card for this record, or null.
     *
     * A record without a data class does nothing (A2A-C1), the import
     * included; a disabled record may still have its card imported, so the
     * operator can look at it before switching the record on.
     */
    public function importRefusal(): ?string
    {
        if (!$this->getDataClass() instanceof ToolDataClass) {
            return sprintf('the A2A agent "%s" has no data class; declare one first', $this->identifier);
        }

        return null;
    }

    /**
     * Why the client may not send to this record, or null when it may.
     */
    public function useRefusal(): ?string
    {
        $importRefusal = $this->importRefusal();
        if ($importRefusal !== null) {
            return $importRefusal;
        }

        if (!$this->enabled) {
            return sprintf('the A2A agent "%s" is not enabled', $this->identifier);
        }

        if ($this->interfaceUrl === '') {
            return sprintf('the agent card of "%s" has not been imported', $this->identifier);
        }

        if ($this->cardChanged) {
            return sprintf('the agent card of "%s" changed on its last import; an administrator has to tick "Confirm the card" and save the record again', $this->identifier);
        }

        if (self::originOf($this->interfaceUrl) === null) {
            return sprintf('the interface URL of "%s" is not a plain http or https URL; import the card again', $this->identifier);
        }

        if (!self::sameOrigin($this->interfaceUrl, $this->cardUrl) && $this->confirmedInterface !== $this->interfaceUrl) {
            // The credential would go to a host nobody confirmed: the card URL
            // was changed in the database, or the confirmation was for another
            // interface.
            return sprintf('the interface of "%s" is not on the card URL\'s host and was not confirmed; import the card again', $this->identifier);
        }

        return null;
    }
}
