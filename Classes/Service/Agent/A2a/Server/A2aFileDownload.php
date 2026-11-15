<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use DateTimeImmutable;
use Netresearch\NrLlm\Service\Agent\A2a\File\A2aFileCapability;
use Netresearch\NrLlm\Service\Agent\A2a\File\A2aFileStore;
use Netresearch\NrLlm\Service\Agent\A2a\File\A2aInboundFilePolicy;
use Netresearch\NrLlm\Service\Agent\A2a\File\A2aOutboundFileArtifacts;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTimestamp;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;

/**
 * The protected download of the files a task sends (spec 051 A2A-FILE5,
 * ADR-284): `GET <endpoint>/files/<file id>`, and
 * `POST <endpoint>/files/<file id>/capability` for a capability URL.
 *
 * - A download is read with the owning client's bearer token, or with a
 *   capability URL issued to that client for that file
 *   ({@see A2aFileCapability}); a capability is a transferable bearer right
 *   until it expires. A capability is issued only with the bearer token.
 * - A file is served only while the task's artifacts name it under the run's
 *   current answer level, so a privacy tightening that scrubbed the
 *   artifacts withdraws the file too (ADR-270, ADR-282).
 * - An unknown file, another client's, an expired one and one the artifacts
 *   no longer name all answer the same 404 (A2A-SEC2).
 * - Issuing a capability writes nothing: the task's status, artifacts and
 *   events stay as they are.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aFileDownload
{
    public const CAPABILITY_PARAMETER = 'capability';

    private LoggerInterface $logger;

    public function __construct(
        private A2aAuthenticator $authenticator,
        private A2aInboundClientRepository $clients,
        private A2aFileStore $files,
        private A2aFileCapability $capabilities,
        private A2aTaskView $view,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param bool $issue true for the capability path, false for the download
     */
    public function handle(ServerRequestInterface $request, A2aBaseUrl $base, string $fileId, bool $issue, int $now): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $allow  = $issue ? ['POST'] : ['GET', 'HEAD'];
        if (!in_array($method, $allow, true)) {
            return new Response(null, 405, ['Allow' => implode(', ', $allow), 'Cache-Control' => 'no-store']);
        }

        $capability = $issue ? '' : ($request->getQueryParams()[self::CAPABILITY_PARAMETER] ?? '');

        try {
            $client = is_string($capability) && $capability !== ''
                ? $this->byCapability($capability, $fileId, $now)
                : $this->authenticator->authenticate($request, $now);
        } catch (A2aRpcError $error) {
            return A2aRpcReply::error(null, $error)->toResponse();
        }

        $row = $this->files->outboundFile($fileId, $client->uid, $now);
        if ($row === null || !$this->stillNamed($client->uid, is_numeric($row['task_uid'] ?? null) ? (int) $row['task_uid'] : 0, $fileId)) {
            return $this->notFound();
        }

        if ($issue) {
            $granted = $this->capabilities->issue($fileId, $client->uid, $client->tokenHash, $now);

            return new JsonResponse(
                [
                    'url'       => rtrim($base->endpoint(), '/') . A2aOutboundFileArtifacts::DOWNLOAD_PATH . $fileId . '?' . self::CAPABILITY_PARAMETER . '=' . $granted['token'],
                    'expiresAt' => A2aTimestamp::format(new DateTimeImmutable('@' . $granted['expires'])),
                ],
                200,
                ['Cache-Control' => 'no-store'],
                JSON_UNESCAPED_SLASHES,
            );
        }

        $bytes  = $this->files->content(is_numeric($row['uid'] ?? null) ? (int) $row['uid'] : 0, $client->uid);
        $sha256 = is_string($row['sha256'] ?? null) ? $row['sha256'] : '';
        if (!hash_equals($sha256, hash('sha256', $bytes))) {
            $this->logger->error('An A2A file does not match its digest; it is not sent', ['file' => $fileId]);

            return A2aRpcReply::error(null, A2aRpcError::internal())->toResponse();
        }

        $response = new Response('php://temp', 200, [
            'Content-Type'           => is_string($row['media_type'] ?? null) ? $row['media_type'] : 'application/octet-stream',
            'Content-Length'         => (string) strlen($bytes),
            'Content-Disposition'    => $this->disposition(is_string($row['filename'] ?? null) ? $row['filename'] : ''),
            'ETag'                   => '"' . $sha256 . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'no-store',
        ]);
        if ($method === 'GET') {
            $response->getBody()->write($bytes);
        }

        return $response;
    }

    /**
     * The client a capability was issued to, while it still grants this file
     * and the client would still be authenticated.
     *
     * @throws A2aRpcError UNAUTHENTICATED
     */
    private function byCapability(string $token, string $fileId, int $now): A2aInboundClient
    {
        $client = $this->clients->findByUid($this->capabilities->claimedClient($token));
        if (!$client instanceof A2aInboundClient
            || !$this->capabilities->grants($token, $fileId, $client->tokenHash, $now)
            || !$this->authenticator->stillValid($client, $now)) {
            throw A2aRpcError::unauthenticated();
        }

        return $client;
    }

    /**
     * Whether the task's artifacts, read under the run's current answer
     * level, still carry the file.
     */
    private function stillNamed(int $ownerClientUid, int $taskUid, string $fileId): bool
    {
        $row = $this->view->findByUid($ownerClientUid, $taskUid);
        if ($row === null) {
            return false;
        }

        $artifacts = $this->view->render($row, 0)['artifacts'] ?? null;
        foreach (is_array($artifacts) ? $artifacts : [] as $artifact) {
            $parts = is_array($artifact) ? ($artifact['parts'] ?? null) : null;
            foreach (is_array($parts) ? $parts : [] as $part) {
                $metadata  = is_array($part) ? ($part['metadata'] ?? null) : null;
                $reference = is_array($metadata) ? ($metadata[A2aInboundFilePolicy::FILE_EXTENSION] ?? null) : null;
                if (is_array($reference) && ($reference['fileId'] ?? null) === $fileId) {
                    return true;
                }
            }
        }

        return false;
    }

    private function notFound(): ResponseInterface
    {
        $response = new Response('php://temp', 404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        $response->getBody()->write('Not found.');

        return $response;
    }

    private function disposition(string $filename): string
    {
        if ($filename === '') {
            return 'attachment';
        }

        if (preg_match('/^[\x20-\x7E]+$/', $filename) === 1) {
            return 'attachment; filename="' . $filename . '"';
        }

        return "attachment; filename=\"file\"; filename*=UTF-8''" . rawurlencode($filename);
    }
}
