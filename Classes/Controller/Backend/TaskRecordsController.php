<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Doctrine\DBAL\Exception as DbalException;
use InvalidArgumentException;
use Netresearch\NrLlm\Controller\Backend\DTO\FetchRecordsRequest;
use Netresearch\NrLlm\Controller\Backend\DTO\LoadRecordDataRequest;
use Netresearch\NrLlm\Controller\Backend\Response\ErrorResponse;
use Netresearch\NrLlm\Controller\Backend\Response\RecordDataResponse;
use Netresearch\NrLlm\Controller\Backend\Response\RecordListResponse;
use Netresearch\NrLlm\Controller\Backend\Response\TableListResponse;
use Netresearch\NrLlm\Service\Task\RecordTableReaderInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Backend controller for the Task record-picker AJAX pathway.
 *
 * Slice 13e of the `TaskController` split (ADR-027). Owns the three
 * AJAX endpoints that drive the table-input source's record picker:
 *
 * - `listTablesAction()` — populate the picker's table-name dropdown
 * - `fetchRecordsAction()` — preview records for a chosen table
 * - `loadRecordDataAction()` — load the full row data for the
 *   records the user has selected
 *
 * Every action returns a typed `Response/*` DTO.
 *
 * Single dependency: `RecordTableReaderInterface`. The controller
 * itself is a thin coordinator — DTO parse, delegate to the reader,
 * wrap in a typed Response.
 */
#[AsController]
final class TaskRecordsController extends ActionController
{
    public function __construct(
        private readonly RecordTableReaderInterface $recordTableReader,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * List available database tables for the picker dropdown.
     */
    public function listTablesAction(): ResponseInterface
    {
        try {
            return new JsonResponse((new TableListResponse(
                tables: $this->recordTableReader->listAllowedTables(),
            ))->jsonSerialize());
        } catch (DbalException $e) {
            // REC #8b: SQL error text leaks schema details — log + generic message.
            $this->logger->error('Task records: listAllowedTables failed', ['exception' => $e]);
            return new JsonResponse((new ErrorResponse('Database error while listing tables.'))->jsonSerialize(), 500);
        } catch (Throwable $e) {
            $this->logger->error('Task records: listAllowedTables failed unexpectedly', ['exception' => $e]);
            return new JsonResponse((new ErrorResponse('Failed to list tables. See system log for details.'))->jsonSerialize(), 500);
        }
    }

    /**
     * Fetch a label-friendly record sample from a chosen table.
     */
    public function fetchRecordsAction(ServerRequestInterface $request): ResponseInterface
    {
        $dto = FetchRecordsRequest::fromRequest($request);

        if (!$dto->isValid()) {
            return new JsonResponse((new ErrorResponse('No table specified'))->jsonSerialize(), 400);
        }

        try {
            // Tables without a uid column (e.g. tx_scheduler_task) cannot
            // back the picker. Short-circuit with an empty payload.
            if (!$this->recordTableReader->tableHasUidColumn($dto->table)) {
                return new JsonResponse((new RecordListResponse(
                    records: [],
                    labelField: '',
                    total: 0,
                ))->jsonSerialize());
            }

            $labelField = $dto->labelField !== ''
                ? $dto->labelField
                : $this->recordTableReader->detectLabelField($dto->table);

            $records = $this->recordTableReader->fetchSampleRecords(
                $dto->table,
                $labelField,
                $dto->limit,
            );

            return new JsonResponse((new RecordListResponse(
                records: $records,
                labelField: $labelField,
                total: count($records),
            ))->jsonSerialize());
        } catch (InvalidArgumentException $e) {
            // RecordTableReader::ensureNotExcluded() rejects forbidden tables.
            // Message is vetted ("Table 'xyz' is excluded ...") and safe to surface.
            return new JsonResponse((new ErrorResponse($e->getMessage()))->jsonSerialize(), 400);
        } catch (DbalException $e) {
            // REC #8b: never surface raw SQL error text — log + generic.
            $this->logger->error('Task records: fetchSampleRecords failed', [
                'exception' => $e,
                'table'     => $dto->table,
            ]);
            return new JsonResponse((new ErrorResponse('Database error while fetching records.'))->jsonSerialize(), 500);
        } catch (Throwable $e) {
            $this->logger->error('Task records: fetchSampleRecords failed unexpectedly', [
                'exception' => $e,
                'table'     => $dto->table,
            ]);
            return new JsonResponse((new ErrorResponse('Failed to fetch records. See system log for details.'))->jsonSerialize(), 500);
        }
    }

    /**
     * Load full row data for selected records.
     */
    public function loadRecordDataAction(ServerRequestInterface $request): ResponseInterface
    {
        $dto = LoadRecordDataRequest::fromRequest($request);

        if (!$dto->isValid()) {
            $error = $dto->table === '' || $dto->uids === ''
                ? 'Table and UIDs required'
                : 'No valid UIDs provided';
            return new JsonResponse((new ErrorResponse($error))->jsonSerialize(), 400);
        }

        try {
            $rows = $this->recordTableReader->loadRecordsByUids($dto->table, $dto->uidList);
            $encoded = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                return new JsonResponse(
                    (new ErrorResponse('Failed to encode record payload: ' . json_last_error_msg()))->jsonSerialize(),
                    500,
                );
            }

            return new JsonResponse((new RecordDataResponse(
                data: $encoded,
                recordCount: count($rows),
            ))->jsonSerialize());
        } catch (InvalidArgumentException $e) {
            return new JsonResponse((new ErrorResponse($e->getMessage()))->jsonSerialize(), 400);
        } catch (DbalException $e) {
            // REC #8b: SQL error text → log + generic.
            $this->logger->error('Task records: loadRecordsByUids failed', [
                'exception' => $e,
                'table'     => $dto->table,
            ]);
            return new JsonResponse((new ErrorResponse('Database error while loading records.'))->jsonSerialize(), 500);
        } catch (Throwable $e) {
            $this->logger->error('Task records: loadRecordsByUids failed unexpectedly', [
                'exception' => $e,
                'table'     => $dto->table,
            ]);
            return new JsonResponse((new ErrorResponse('Failed to load records. See system log for details.'))->jsonSerialize(), 500);
        }
    }
}
