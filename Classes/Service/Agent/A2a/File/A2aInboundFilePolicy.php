<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\File;

use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aErrorCode;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aMessage;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aPart;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aPartKind;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aRpcError;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSkillProfile;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aUrlGuardFactory;
use Symfony\Component\Uid\Uuid;

/**
 * Which parts a client message may carry, checked at the request before
 * anything is stored (spec 051 A2A-FILE1, A2A-FILE2).
 *
 * With `a2a.files.enabled` off, every part is `text/plain` text, as before
 * stage 5c; anything else is `-32005`. With it on, a `raw` part is accepted
 * when it names one of the five media types of {@see A2aFileValidator}, its
 * bytes pass that type's check, and the profile lists the type among its
 * input modes; otherwise `-32005`. Sizes are checked before content:
 *
 * 1. the base64 text of each raw part, and of all raw parts together, before
 *    it is decoded ({@see self::checkEncoded()}, on the request's JSON);
 * 2. the decoded bytes of each part and of all parts together.
 *
 * Both limits derive from `a2a.maxRawPartBytes`; larger is `-32602`.
 *
 * A `url` part needs the same declared type and input mode, and an https URL
 * that passes the address guard here, at creation, with the file hosts of
 * `a2a.files` (A2A-FILE3, A2A-SEC5): otherwise `-32602`. While no file host
 * is listed, every `url` part is `-32005`. The worker fetches it later and
 * checks it again. A `data` part is refused with `-32005`.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aInboundFilePolicy
{
    /** Metadata key of the reference a task's history keeps in place of a file's bytes. */
    public const FILE_EXTENSION = 'https://nr-llm.netresearch.de/a2a/ext/file/v1';

    private const PLAIN_TEXT = ['', 'text/plain'];

    private const MAX_FILENAME_LENGTH = 255;

    private const MAX_URL_LENGTH = 2048;

    public function __construct(
        private A2aFileSettings $settings,
        private A2aFileValidator $validator,
        private A2aUrlGuardFactory $guardFactory,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->enabled();
    }

    /**
     * Limit 1, on the message as JSON, before the parser decodes any base64.
     *
     * @throws A2aRpcError
     */
    public function checkEncoded(mixed $message): void
    {
        if (!$this->settings->enabled() || !is_array($message) || !is_array($message['parts'] ?? null)) {
            return;
        }

        $limit = $this->settings->maxBase64Length();
        $total = 0;
        foreach ($message['parts'] as $index => $part) {
            $raw = is_array($part) ? ($part['raw'] ?? null) : null;
            if (!is_string($raw)) {
                continue;
            }

            if (strlen($raw) > $limit) {
                throw A2aRpcError::invalidParams(sprintf('message.parts[%s].raw', $index), sprintf('the base64 text is longer than %d characters before decoding', $limit));
            }

            $total += strlen($raw);
            if ($total > $limit) {
                throw A2aRpcError::invalidParams('message.parts', sprintf('the base64 text of all files is longer than %d characters before decoding', $limit));
            }
        }
    }

    /**
     * The files of a parsed message, checked; text parts are not files.
     *
     * @return list<A2aInboundFile>
     *
     * @throws A2aRpcError
     */
    public function files(A2aMessage $message): array
    {
        if (!$this->settings->enabled()) {
            foreach ($message->parts as $part) {
                if (!$this->isPlainText($part)) {
                    throw A2aRpcError::a2a(A2aErrorCode::CONTENT_TYPE_NOT_SUPPORTED, 'Only text/plain parts are accepted.');
                }
            }

            return [];
        }

        $limit = $this->settings->maxRawPartBytes();
        $total = 0;
        $files = [];
        foreach ($message->parts as $index => $part) {
            if ($this->isPlainText($part)) {
                continue;
            }

            if ($part->kind === A2aPartKind::URL) {
                $mediaType = $this->checkedType($part, $index);
                $files[]   = A2aInboundFile::url(Uuid::v4()->toRfc4122(), $index, $mediaType, $this->filename($part, $index), $this->checkedUrl($part->url, $index));

                continue;
            }

            if ($part->kind !== A2aPartKind::RAW) {
                throw A2aRpcError::a2a(A2aErrorCode::CONTENT_TYPE_NOT_SUPPORTED, 'Only text/plain text and raw or url file parts are accepted.');
            }

            if (strlen($part->raw) > $limit) {
                throw A2aRpcError::invalidParams(sprintf('message.parts[%d].raw', $index), sprintf('the file holds more than %d bytes', $limit));
            }

            $total += strlen($part->raw);
            if ($total > $limit) {
                throw A2aRpcError::invalidParams('message.parts', sprintf('the files of one message hold more than %d bytes', $limit));
            }

            $files[] = A2aInboundFile::raw(Uuid::v4()->toRfc4122(), $index, $this->checkedType($part, $index), $this->filename($part, $index), $part->raw);
        }

        return $files;
    }

    /**
     * Every file's type must be one the profile lists among its input modes.
     *
     * @param list<A2aInboundFile> $files
     *
     * @throws A2aRpcError
     */
    public function checkInputModes(array $files, A2aSkillProfile $profile): void
    {
        $modes = array_map(A2aFileValidator::normalise(...), $profile->inputModes);
        foreach ($files as $file) {
            if (!in_array($file->mediaType, $modes, true)) {
                throw A2aRpcError::a2a(A2aErrorCode::CONTENT_TYPE_NOT_SUPPORTED, sprintf('This skill does not take %s files.', $file->mediaType));
            }
        }
    }

    /**
     * The message as the task's history keeps it: a raw part without its
     * bytes, with the stored file's id, digest and size in its metadata.
     *
     * @param array<string, mixed> $entry the message's ProtoJSON form
     * @param list<A2aInboundFile> $files
     *
     * @return array<string, mixed>
     */
    public function historyEntry(array $entry, array $files): array
    {
        if ($files === [] || !is_array($entry['parts'] ?? null)) {
            return $entry;
        }

        $parts = $entry['parts'];
        foreach ($files as $file) {
            $part = $parts[$file->partIndex] ?? null;
            if (!is_array($part)) {
                continue;
            }

            $metadata = is_array($part['metadata'] ?? null) ? $part['metadata'] : [];

            $metadata[self::FILE_EXTENSION] = ['fileId' => $file->fileId, 'sha256' => $file->sha256(), 'size' => strlen($file->bytes)];
            if ($file->isRaw()) {
                $part['raw'] = '';
            }

            $part['metadata']        = $metadata;
            $parts[$file->partIndex] = $part;
        }

        $entry['parts'] = $parts;

        return $entry;
    }

    /**
     * @throws A2aRpcError
     */
    private function checkedType(A2aPart $part, int $index): string
    {
        $mediaType = A2aFileValidator::normalise($part->mediaType);
        if ($mediaType === '') {
            throw A2aRpcError::a2a(A2aErrorCode::CONTENT_TYPE_NOT_SUPPORTED, sprintf('The file part %d names no media type.', $index));
        }

        if (!A2aFileValidator::accepts($mediaType)) {
            throw A2aRpcError::a2a(A2aErrorCode::CONTENT_TYPE_NOT_SUPPORTED, sprintf('Files of type %s are not accepted.', $mediaType));
        }

        // A url part's content is checked by the worker once it is fetched.
        $refusal = $part->kind === A2aPartKind::RAW ? $this->validator->refusal($mediaType, $part->raw) : null;
        if ($refusal !== null) {
            throw A2aRpcError::a2a(A2aErrorCode::CONTENT_TYPE_NOT_SUPPORTED, sprintf('The file part %d is not %s: %s.', $index, $mediaType, $refusal));
        }

        return $mediaType;
    }

    /**
     * The guard at creation (A2A-SEC5); the worker asks it again at use.
     *
     * @throws A2aRpcError
     */
    private function checkedUrl(string $url, int $index): string
    {
        if (!$this->guardFactory->fileHostsConfigured()) {
            throw A2aRpcError::a2a(A2aErrorCode::CONTENT_TYPE_NOT_SUPPORTED, 'This installation fetches no file URLs.');
        }

        $field = sprintf('message.parts[%d].url', $index);
        if (strlen($url) > self::MAX_URL_LENGTH) {
            throw A2aRpcError::invalidParams($field, sprintf('at most %d characters', self::MAX_URL_LENGTH));
        }

        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw A2aRpcError::invalidParams($field, 'a file URL must be https');
        }

        $target = $this->guardFactory->createForFiles()->check(A2aUrlGuardFactory::GROUP_EXTERNAL, $url);
        if (!$target->allowed) {
            throw A2aRpcError::invalidParams($field, 'the URL is not one this server fetches: ' . $target->reason);
        }

        return $url;
    }

    /**
     * @throws A2aRpcError
     */
    private function filename(A2aPart $part, int $index): string
    {
        if (mb_strlen($part->filename) > self::MAX_FILENAME_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $part->filename) === 1) {
            throw A2aRpcError::invalidParams(sprintf('message.parts[%d].filename', $index), sprintf('at most %d characters, no control characters', self::MAX_FILENAME_LENGTH));
        }

        return $part->filename;
    }

    private function isPlainText(A2aPart $part): bool
    {
        return $part->kind === A2aPartKind::TEXT && in_array(A2aFileValidator::normalise($part->mediaType), self::PLAIN_TEXT, true);
    }
}
