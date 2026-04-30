<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\DTO\FetchRecordsRequest;
use Netresearch\NrLlm\Controller\Backend\DTO\LoadRecordDataRequest;
use Netresearch\NrLlm\Controller\Backend\Response\ErrorResponse;
use Netresearch\NrLlm\Controller\Backend\Response\RecordDataResponse;
use Netresearch\NrLlm\Controller\Backend\Response\RecordListResponse;
use Netresearch\NrLlm\Controller\Backend\Response\TableListResponse;
use Netresearch\NrLlm\Service\Task\RecordTableReaderInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
        } catch (Throwable $e) {
            return new JsonResponse((new ErrorResponse($e->getMessage()))->jsonSerialize(), 500);
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
        } catch (Throwable $e) {
            return new JsonResponse((new ErrorResponse($e->getMessage()))->jsonSerialize(), 500);
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
        } catch (Throwable $e) {
            return new JsonResponse((new ErrorResponse($e->getMessage()))->jsonSerialize(), 500);
        }
    }
}
