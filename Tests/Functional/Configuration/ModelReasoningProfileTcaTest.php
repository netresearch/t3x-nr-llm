<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Configuration;

use Netresearch\NrLlm\Domain\Enum\ReasoningEffort;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * The model record editor's reasoning profile fields (ADR-275, R8): the
 * operator fields are editable and default to empty, the discovered fields
 * are read-only, and every field is on the form. Read from the loaded TCA,
 * not from the PHP file, so overrides and v13/v14 differences count.
 */
#[CoversNothing]
final class ModelReasoningProfileTcaTest extends AbstractFunctionalTestCase
{
    private const OPERATOR_FIELDS = ['reasoning_override', 'reasoning_efforts_override', 'tool_route_override'];

    private const DISCOVERED_FIELDS = ['reasoning_discovered', 'reasoning_efforts_discovered', 'reasoning_source', 'reasoning_confirmed_at'];

    #[Test]
    public function theOperatorFieldsAreEditableAndDefaultToEmpty(): void
    {
        foreach (self::OPERATOR_FIELDS as $field) {
            $config = $this->config($field);

            self::assertArrayNotHasKey('readOnly', $config, $field);
            self::assertSame('', $config['default'] ?? null, $field);
        }
    }

    #[Test]
    public function theDiscoveredFieldsAreReadOnly(): void
    {
        foreach (self::DISCOVERED_FIELDS as $field) {
            self::assertTrue($this->config($field)['readOnly'] ?? false, $field);
        }
    }

    #[Test]
    public function theEffortChoicesAreTheReasoningEffortValues(): void
    {
        $items = $this->config('reasoning_efforts_override')['items'] ?? [];
        self::assertIsArray($items);

        $values = array_map(static fn (array $item): mixed => $item['value'] ?? null, $items);

        self::assertSame(array_map(static fn (ReasoningEffort $e): string => $e->value, ReasoningEffort::cases()), $values);
    }

    #[Test]
    public function theSelectChoicesOfferExactlyWhatTheEntityStores(): void
    {
        self::assertSame(['', 'yes', 'no'], $this->values('reasoning_override'));
        self::assertSame(['', 'chat_completions', 'responses'], $this->values('tool_route_override'));
    }

    #[Test]
    public function everyFieldIsOnTheFormWithATranslatedLabel(): void
    {
        $types = $this->section('types');
        $type  = $types['0'] ?? null;
        self::assertIsArray($type);
        $showitem = $type['showitem'] ?? null;
        self::assertIsString($showitem);

        $palettes = '';
        foreach ($this->section('palettes') as $palette) {
            $palettes .= is_array($palette) && is_string($palette['showitem'] ?? null) ? $palette['showitem'] . ',' : '';
        }

        $language = $this->get(LanguageServiceFactory::class)->create('default');

        self::assertStringContainsString('--palette--;;reasoning_profile', $showitem);
        self::assertStringContainsString('--palette--;;reasoning_provenance', $showitem);

        $columns = $this->section('columns');
        foreach ([...self::OPERATOR_FIELDS, ...self::DISCOVERED_FIELDS] as $field) {
            self::assertStringContainsString($field, $palettes, $field);

            $column = $columns[$field] ?? null;
            self::assertIsArray($column);
            $label = $column['label'] ?? null;
            self::assertIsString($label);
            self::assertNotSame('', $language->sL($label), $label);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function section(string $name): array
    {
        $tca = $GLOBALS['TCA'] ?? null;
        self::assertIsArray($tca);
        $table = $tca['tx_nrllm_model'] ?? null;
        self::assertIsArray($table);
        $section = $table[$name] ?? null;
        self::assertIsArray($section);

        return $section;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function config(string $field): array
    {
        $column = $this->section('columns')[$field] ?? null;
        self::assertIsArray($column, $field . ' is missing from the TCA');
        $config = $column['config'] ?? null;
        self::assertIsArray($config, $field);

        return $config;
    }

    /**
     * @return list<mixed>
     */
    private function values(string $field): array
    {
        $items = $this->config($field)['items'] ?? [];
        self::assertIsArray($items);

        return array_values(array_map(static fn (array $item): mixed => $item['value'] ?? null, $items));
    }
}
