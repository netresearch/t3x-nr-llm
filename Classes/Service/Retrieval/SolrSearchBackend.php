<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Retrieval;

use Netresearch\NrLlm\Utility\SafeCastTrait;
use Throwable;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Retrieval over an EXT:solr index (ADR-049) — spoken to over Solr's
 * HTTP select API instead of EXT:solr's PHP classes: those are @internal,
 * and for TYPO3 14 only a beta of EXT:solr exists, so the only stable
 * integration surface is the documented site configuration
 * (`solr_{scheme|host|port|path|core}_read` with per-language overrides)
 * plus the Solr server EXT:solr provisioned.
 *
 * The select URL mirrors Solarium 6 endpoint semantics: the configured
 * path must NOT contain `/solr` (a trailing `/solr` is tolerated and
 * stripped, matching EXT:solr's own upgrade handling); the final URL is
 * `{scheme}://{host}:{port}{path}/solr/{core}/select`.
 *
 * Public-only: every query carries `fq={!typo3access}0,-1` — the
 * server-side access parser EXT:solr ships in its configsets — plus a
 * language filter. Any HTTP or parse failure makes the cascade continue
 * with the next backend.
 */
final class SolrSearchBackend implements SearchBackendInterface
{
    use SafeCastTrait;

    public const IDENTIFIER = 'solr';

    private const TIMEOUT_SECONDS = 10;

    private ?bool $configured = null;

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly RequestFactory $requestFactory,
    ) {}

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function getPriority(): int
    {
        return 30;
    }

    public function isAvailable(): bool
    {
        if (!ExtensionManagementUtility::isLoaded('solr')) {
            return false;
        }

        if ($this->configured === null) {
            $this->configured = $this->anySiteConfigured();
        }

        return $this->configured;
    }

    public function search(RetrievalQuery $query, AccessContext $context): EvidenceList
    {
        $sources = [];
        foreach ($this->candidateSites($query->siteIdentifier) as $site) {
            $endpoint = $this->selectUrl($site, $query->languageId);
            if ($endpoint === null) {
                continue;
            }

            $parameters = [
                'q' => $this->escapeTerm($query->query),
                'defType' => 'edismax',
                'qf' => 'title^5.0 content^1.0',
                'fl' => 'type,uid,title,content,url,language,score',
                'rows' => (string)$query->maxSources,
                'wt' => 'json',
            ];
            $filters = [
                '{!typo3access}0,-1',
                'language:' . $query->languageId,
            ];

            foreach ($this->select($endpoint, $parameters, $filters) as $document) {
                $source = $this->toEvidence($document, $site, $query);
                if ($source !== null) {
                    $sources[] = $source;
                }
                if (count($sources) >= $query->maxSources) {
                    return new EvidenceList(self::IDENTIFIER, $sources);
                }
            }
        }

        return new EvidenceList(self::IDENTIFIER, $sources);
    }

    public function fetchSource(SourceReference $reference, AccessContext $context): ?string
    {
        if (count($reference->parts) !== 4) {
            return null;
        }
        [$siteIdentifier, $type, $uid, $languageId] = $reference->parts;
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $type) !== 1) {
            return null;
        }

        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (Throwable) {
            return null;
        }

        $endpoint = $this->selectUrl($site, self::toInt($languageId));
        if ($endpoint === null) {
            return null;
        }

        $documents = $this->select(
            $endpoint,
            [
                'q' => '*:*',
                'fl' => 'title,content',
                'rows' => '1',
                'wt' => 'json',
            ],
            [
                '{!typo3access}0,-1',
                'type:' . $type,
                'uid:' . self::toInt($uid),
                'language:' . self::toInt($languageId),
            ],
        );

        if ($documents === []) {
            return null;
        }

        $document = $documents[0];
        $title = ExcerptBuilder::plain(self::toStr($document['title'] ?? ''));
        $content = ExcerptBuilder::plain(self::toStr($document['content'] ?? ''));

        $parts = ['# ' . $title];
        if ($content !== '') {
            $parts[] = $content;
        }

        return implode("\n\n", $parts);
    }

    /**
     * @return list<Site>
     */
    private function candidateSites(?string $siteIdentifier): array
    {
        try {
            if ($siteIdentifier !== null) {
                return [$this->siteFinder->getSiteByIdentifier($siteIdentifier)];
            }

            return array_values($this->siteFinder->getAllSites());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Run one select request; empty list on any HTTP, JSON or shape
     * problem (the cascade treats persistent failure at search() level).
     *
     * @param array<string, string> $parameters
     * @param list<string>          $filters
     *
     * @return list<array<string, mixed>>
     */
    private function select(string $endpoint, array $parameters, array $filters): array
    {
        $pairs = [];
        foreach ($parameters as $name => $value) {
            $pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
        }
        foreach ($filters as $filter) {
            $pairs[] = 'fq=' . rawurlencode($filter);
        }

        $response = $this->requestFactory->request($endpoint . '?' . implode('&', $pairs), 'GET', [
            'timeout' => self::TIMEOUT_SECONDS,
            'allow_redirects' => false,
        ]);
        if ($response->getStatusCode() !== 200) {
            return [];
        }

        $data = json_decode((string)$response->getBody(), true);
        if (!is_array($data)) {
            return [];
        }
        $responseNode = $data['response'] ?? null;
        if (!is_array($responseNode)) {
            return [];
        }
        $docs = $responseNode['docs'] ?? null;
        if (!is_array($docs)) {
            return [];
        }

        $result = [];
        foreach ($docs as $doc) {
            if (is_array($doc)) {
                /** @var array<string, mixed> $doc */
                $result[] = $doc;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function toEvidence(array $document, Site $site, RetrievalQuery $query): ?EvidenceSource
    {
        $type = self::toStr($document['type'] ?? '');
        $uid = self::toInt($document['uid'] ?? 0);
        $url = self::toStr($document['url'] ?? '');
        if ($type === '' || $uid < 1 || preg_match('/^[A-Za-z0-9_]{1,64}$/', $type) !== 1) {
            return null;
        }
        if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://') && !str_starts_with($url, '/')) {
            return null;
        }

        $languageId = self::toInt($document['language'] ?? $query->languageId);
        $content = ExcerptBuilder::plain(self::toStr($document['content'] ?? ''));

        return new EvidenceSource(
            sourceId: sprintf('%s:%s:%s:%d:%d', self::IDENTIFIER, $site->getIdentifier(), $type, $uid, $languageId),
            title: ExcerptBuilder::plain(self::toStr($document['title'] ?? '')),
            url: $url,
            excerpt: ExcerptBuilder::around($content, $query->query),
            backend: self::IDENTIFIER,
            languageId: $languageId,
            pageUid: $type === 'pages' ? $uid : null,
            score: isset($document['score']) && is_numeric($document['score']) ? (float)$document['score'] : null,
        );
    }

    /**
     * The select URL for the site/language, or null when Solr is not
     * (fully) configured for it.
     */
    private function selectUrl(Site $site, int $languageId): ?string
    {
        $siteConfig = $site->getConfiguration();
        $languageConfig = [];
        foreach ($this->configuredLanguages($siteConfig) as $language) {
            if (self::toInt($language['languageId'] ?? -1) === $languageId) {
                $languageConfig = $language;
                break;
            }
        }

        if (!$this->readBool($languageConfig, $siteConfig, 'solr_enabled_read', true)) {
            return null;
        }

        $core = $this->readString($languageConfig, $siteConfig, 'solr_core_read', '');
        if ($core === '' || preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $core) !== 1) {
            return null;
        }

        $scheme = $this->readString($languageConfig, $siteConfig, 'solr_scheme_read', 'http');
        $host = $this->readString($languageConfig, $siteConfig, 'solr_host_read', 'localhost');
        $port = self::toInt($this->readString($languageConfig, $siteConfig, 'solr_port_read', '8983'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || $port < 1 || $port > 65535) {
            return null;
        }

        $path = trim($this->readString($languageConfig, $siteConfig, 'solr_path_read', ''), '/');
        if ($path === 'solr' || str_ends_with($path, '/solr')) {
            // Solarium 6 semantics: '/solr' lives outside the configured path.
            $path = rtrim(substr($path, 0, -4), '/');
        }

        return sprintf(
            '%s://%s:%d%s/solr/%s/select',
            $scheme,
            $host,
            $port,
            $path === '' ? '' : '/' . $path,
            $core,
        );
    }

    /**
     * @param array<string, mixed> $siteConfig
     *
     * @return list<array<string, mixed>>
     */
    private function configuredLanguages(array $siteConfig): array
    {
        $languages = $siteConfig['languages'] ?? null;
        if (!is_array($languages)) {
            return [];
        }

        $result = [];
        foreach ($languages as $language) {
            if (is_array($language)) {
                /** @var array<string, mixed> $language */
                $result[] = $language;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $languageConfig
     * @param array<string, mixed> $siteConfig
     */
    private function readString(array $languageConfig, array $siteConfig, string $key, string $default): string
    {
        $value = $languageConfig[$key] ?? $siteConfig[$key] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $default;
        }

        return trim(self::toStr($value));
    }

    /**
     * @param array<string, mixed> $languageConfig
     * @param array<string, mixed> $siteConfig
     */
    private function readBool(array $languageConfig, array $siteConfig, string $key, bool $default): bool
    {
        $value = $languageConfig[$key] ?? $siteConfig[$key] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $default;
        }

        return (bool)$value;
    }

    /**
     * Escape Lucene query syntax in the (model-chosen) search term.
     */
    private function escapeTerm(string $term): string
    {
        return trim((string)preg_replace('/([+\-!(){}\[\]^"~*?:\\\\\/]|&&|\|\|)/', '\\\\$1', $term));
    }

    private function anySiteConfigured(): bool
    {
        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                foreach ($site->getLanguages() as $language) {
                    if ($this->selectUrl($site, $language->getLanguageId()) !== null) {
                        return true;
                    }
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
}
