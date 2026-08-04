<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp;

use JsonException;
use Netresearch\NrLlm\Domain\ValueObject\McpImportReport;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Context\Context;

/**
 * Imports one MCP server's advertised catalogue into `tx_nrllm_mcp_tool` (ADR-116).
 *
 * The only writer of that table, and the only place a remote catalogue is
 * validated. It runs on an explicit administrator action, never on a request
 * path and never on a run: discovery talks to a third party over the network,
 * and that must be something an operator chose to do.
 *
 * Everything a server sends is treated as a proposal. A tool is imported only
 * when it can be named locally, its schema can be normalised, and its name
 * collides with nothing already registered. Rejections are counted and reported
 * with a reason, because a tool that is simply absent from the catalogue looks
 * exactly like a tool the server never offered.
 */
final readonly class McpImportService
{
    /**
     * A catalogue larger than this is refused rather than truncated.
     *
     * Every imported tool costs context in every run that offers its group, so
     * a server advertising thousands of tools is either misconfigured or
     * hostile. Truncating would import an arbitrary subset and report success.
     */
    private const MAX_TOOLS = 500;

    public function __construct(
        private McpClient $client,
        private McpServerRepository $servers,
        private McpToolRepository $catalogue,
        private McpToolNameMapper $names,
        private McpSchemaNormalizer $schemas,
        private ToolRegistry $registry,
        private LoggerInterface $logger,
        private Context $context,
    ) {}

    public function import(McpServerRecord $server): McpImportReport
    {
        $now = $this->now();

        if ($server->dataClassEnum() === null) {
            return $this->refuse($server, $now, 'The server has no declared data class, so its tools cannot be classified.');
        }

        if ($server->url === '') {
            return $this->refuse($server, $now, 'The server has no URL.');
        }

        // Two enabled servers sharing an identifier would generate the same
        // local tool names, and the catalogue's unique index would reject the
        // second one's rows halfway through. Refused up front so the operator
        // is told which setting is wrong, rather than shown a database error.
        // Not a database constraint: the table soft-deletes, so a unique index
        // would keep a discarded server's identifier reserved for ever.
        $twin = $this->identifierTwin($server);
        if ($twin !== null) {
            return $this->refuse($server, $now, sprintf(
                'The identifier "%s" is also used by server "%s"; identifiers must be unique because they name the imported tools.',
                $server->identifier,
                $twin,
            ));
        }

        try {
            $advertised = $this->client->listTools($server);
        } catch (McpTransportException $e) {
            $this->logger->warning('An MCP catalogue import failed', [
                'server'    => $server->identifier,
                'exception' => $e,
            ]);

            return $this->refuse($server, $now, $e->getMessage());
        }

        if (count($advertised) > self::MAX_TOOLS) {
            return $this->refuse($server, $now, sprintf(
                'The server advertises %d tools, more than the %d this extension will import.',
                count($advertised),
                self::MAX_TOOLS,
            ));
        }

        $accepted    = [];
        $skipReasons = [];
        $localNames  = [];

        foreach ($advertised as $tool) {
            $outcome = $this->accept($server, $tool, $localNames);

            if (is_string($outcome)) {
                $skipReasons[] = $outcome;

                continue;
            }

            $localNames[$outcome['toolName']] = true;
            $accepted[]                       = $outcome;
        }

        $orphaned = $this->catalogue->reconcile($server->uid, $server->pid, $accepted, $now);

        $this->servers->recordImportOutcome(
            $server->uid,
            $skipReasons === [] ? 'ok' : 'partial',
            $skipReasons === [] ? '' : implode("\n", $skipReasons),
            count($accepted),
            $now,
        );

        return new McpImportReport(
            serverUid: $server->uid,
            imported: count($accepted),
            skipped: count($skipReasons),
            orphaned: $orphaned,
            skipReasons: $skipReasons,
        );
    }

    /**
     * Validate one advertised tool.
     *
     * @param array<string, mixed> $tool
     * @param array<string, true>  $localNames names already accepted in this run
     *
     * @return array{toolName: string, remoteName: string, description: string, inputSchema: string, remoteAnnotations: string}|string
     *                                                                                                                                 the catalogue row, or the reason it was rejected
     */
    private function accept(McpServerRecord $server, array $tool, array $localNames): array|string
    {
        $remoteName = $tool['name'] ?? null;
        if (!is_string($remoteName) || $remoteName === '') {
            return 'An advertised tool has no name and was skipped.';
        }

        $localName = $this->names->localName($server->identifier, $remoteName);
        if ($localName === null) {
            return sprintf('"%s" cannot be given a valid local tool name and was skipped.', $remoteName);
        }

        if (isset($localNames[$localName])) {
            return sprintf('"%s" maps to a name this server already used and was skipped.', $remoteName);
        }

        // Against the compile-time builtins only. A name already held by
        // another server's imported tool is caught by the catalogue's unique
        // index and, before that, by the identifier check in import(); using
        // the full registry here would consult the providers and make the
        // import depend on the very catalogue it is writing.
        if (in_array($localName, $this->registry->builtinNames(), true)) {
            return sprintf('"%s" would collide with a builtin tool of the same name and was skipped.', $remoteName);
        }

        $schema = $this->schemas->normalise($tool['inputSchema'] ?? null);
        if ($schema === null) {
            return sprintf('"%s" has no usable parameter schema and was skipped.', $remoteName);
        }

        $encodedSchema = $this->encode($schema);
        if ($encodedSchema === null) {
            return sprintf('"%s" has a parameter schema that could not be stored and was skipped.', $remoteName);
        }

        $description = $tool['description'] ?? '';
        $annotations = $tool['annotations'] ?? null;

        return [
            'toolName'          => $localName,
            'remoteName'        => $remoteName,
            'description'       => is_string($description) ? $description : '',
            'inputSchema'       => $encodedSchema,
            // Kept verbatim for display. Nothing reads it to make a decision:
            // a remote server must not be able to influence authorisation by
            // what it writes here.
            'remoteAnnotations' => is_array($annotations) ? ($this->encode($annotations) ?? '') : '',
        ];
    }

    /**
     * The name of another enabled server claiming the same identifier, if any.
     */
    private function identifierTwin(McpServerRecord $server): ?string
    {
        foreach ($this->servers->findEnabled() as $other) {
            if ($other->uid !== $server->uid && $other->identifier === $server->identifier) {
                return $other->name;
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function encode(array $value): ?string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return null;
        }
    }

    private function refuse(McpServerRecord $server, int $now, string $reason): McpImportReport
    {
        // The catalogue is left exactly as it was. A server we could not reach
        // has not told us its tools are gone, and orphaning them on a network
        // failure would disable an operator's working tools by accident.
        $this->servers->recordImportOutcome($server->uid, 'error', $reason, $server->toolCount, $now);

        return McpImportReport::refused($server->uid, $reason);
    }

    /**
     * The request's pinned timestamp, so every row one import writes carries the
     * same one. Falls back to wall time if the aspect is unavailable, which is
     * the only case where two rows of one import could disagree by a second.
     */
    private function now(): int
    {
        $timestamp = $this->context->getPropertyFromAspect('date', 'timestamp');

        return is_int($timestamp) && $timestamp > 0 ? $timestamp : time();
    }
}
