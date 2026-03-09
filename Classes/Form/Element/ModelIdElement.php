<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Form\Element;

use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Custom TCA form element for model_id field.
 *
 * Renders an input field with a "Fetch Models" button and a dropdown
 * that populates from the selected provider's API. When a model is
 * selected, capabilities, context_length, and pricing are auto-filled.
 */
final class ModelIdElement extends AbstractFormElement
{
    public function __construct(private readonly BackendUriBuilder $uriBuilder) {}
    /**
     * @return array<string, mixed>
     */
    public function render(): array
    {
        $result = $this->initializeResultArray();

        /** @var array<string, mixed> $data */
        $data = $this->data;
        $parameterArray = is_array($data['parameterArray'] ?? null) ? $data['parameterArray'] : [];
        $itemFormElValue = is_string($parameterArray['itemFormElValue'] ?? null) ? $parameterArray['itemFormElValue'] : '';
        $itemFormElName = is_string($parameterArray['itemFormElName'] ?? null) ? $parameterArray['itemFormElName'] : '';
        $fieldId = StringUtility::getUniqueId(self::class . '-');
        $fieldConf = is_array($parameterArray['fieldConf'] ?? null) ? $parameterArray['fieldConf'] : [];
        $config = is_array($fieldConf['config'] ?? null) ? $fieldConf['config'] : [];

        $placeholder = is_string($config['placeholder'] ?? null) ? $config['placeholder'] : 'e.g., gpt-5.3-chat-latest, claude-sonnet-4-6';
        $maxLength = is_int($config['max'] ?? null) ? $config['max'] : 150;

        // Build AJAX URL for fetching models
        $fetchUrl = (string)$this->uriBuilder->buildUriFromRoute('ajax_nrllm_model_fetch_available');

        // The provider_uid field name follows TYPO3's naming convention
        $tableName = is_string($data['tableName'] ?? null) ? $data['tableName'] : 'tx_nrllm_model';
        $databaseRow = is_array($data['databaseRow'] ?? null) ? $data['databaseRow'] : [];
        $currentProviderUid = 0;
        if (isset($databaseRow['provider_uid'])) {
            $providerUidValue = $databaseRow['provider_uid'];
            if (is_array($providerUidValue)) {
                $first = $providerUidValue[0] ?? 0;
                $currentProviderUid = is_numeric($first) ? (int)$first : 0;
            } elseif (is_numeric($providerUidValue)) {
                $currentProviderUid = (int)$providerUidValue;
            }
        }

        $escapedValue = htmlspecialchars($itemFormElValue, ENT_QUOTES, 'UTF-8');
        $escapedName = htmlspecialchars($itemFormElName, ENT_QUOTES, 'UTF-8');
        $escapedId = htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8');
        $escapedPlaceholder = htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8');
        $escapedFetchUrl = htmlspecialchars($fetchUrl, ENT_QUOTES, 'UTF-8');
        $html = [];
        $html[] = '<div class="formengine-field-item t3js-formengine-field-item">';
        $html[] = '  <div class="form-control-wrap">';
        $html[] = '    <div class="input-group">';
        $html[] = sprintf(
            '      <input type="text" id="%s" name="%s" value="%s" '
            . 'class="form-control" maxlength="%d" placeholder="%s" '
            . 'autocomplete="off" '
            . 'data-formengine-input-name="%s" />',
            $escapedId,
            $escapedName,
            $escapedValue,
            $maxLength,
            $escapedPlaceholder,
            $escapedName,
        );
        $html[] = sprintf(
            '      <button type="button" class="btn btn-default js-fetch-models" '
            . 'data-fetch-url="%s" '
            . 'data-input-id="%s" '
            . 'data-provider-uid="%d" '
            . 'data-table="%s" '
            . 'title="Fetch available models from provider API">'
            . '<span class="icon icon-size-small"><span class="icon-markup">'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="16" height="16">'
            . '<path fill="currentColor" d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1zm0 12.5a5.5 5.5 0 1 1 0-11 5.5 5.5 0 0 1 0 11z"/>'
            . '<path fill="currentColor" d="M10.5 7.25H8.75V5.5a.75.75 0 0 0-1.5 0v1.75H5.5a.75.75 0 0 0 0 1.5h1.75v1.75a.75.75 0 0 0 1.5 0V8.75h1.75a.75.75 0 0 0 0-1.5z"/>'
            . '</svg></span></span> Fetch Models</button>',
            $escapedFetchUrl,
            $escapedId,
            $currentProviderUid,
            htmlspecialchars($tableName, ENT_QUOTES, 'UTF-8'),
        );
        $html[] = '    </div>';
        $html[] = '    <div class="js-model-status mt-1" style="display:none;"></div>';
        $html[] = '  </div>';
        $html[] = '</div>';

        $result['html'] = implode("\n", $html);

        // Add inline JS to handle fetch + auto-fill
        /** @var list<JavaScriptModuleInstruction> $jsModules */
        $jsModules = $result['javaScriptModules'] ?? [];
        $jsModules[] = JavaScriptModuleInstruction::create('@netresearch/nr-llm/Backend/ModelIdField.js');
        $result['javaScriptModules'] = $jsModules;

        /** @var array<string, mixed> $result */
        return $result;
    }
}
