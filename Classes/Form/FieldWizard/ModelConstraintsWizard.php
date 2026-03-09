<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Form\FieldWizard;

use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;

/**
 * Field wizard for model_uid that loads parameter constraint JS.
 *
 * Injects the ConfigurationConstraints JS module and provides
 * the AJAX URL for fetching model-specific parameter constraints.
 */
final class ModelConstraintsWizard extends AbstractNode
{
    public function __construct(private readonly BackendUriBuilder $uriBuilder) {}
    /**
     * @return array<string, mixed>
     */
    public function render(): array
    {
        $result = $this->initializeResultArray();

        $constraintsUrl = (string)$this->uriBuilder->buildUriFromRoute('ajax_nrllm_config_model_constraints');

        $result['html'] = sprintf(
            '<div class="js-model-constraints-config" data-constraints-url="%s" style="display:none;"></div>',
            htmlspecialchars($constraintsUrl, ENT_QUOTES, 'UTF-8'),
        );

        /** @var list<JavaScriptModuleInstruction> $jsModules */
        $jsModules = $result['javaScriptModules'] ?? [];
        $jsModules[] = JavaScriptModuleInstruction::create(
            '@netresearch/nr-llm/Backend/ConfigurationConstraints.js',
        );
        $result['javaScriptModules'] = $jsModules;

        /** @var array<string, mixed> $result */
        return $result;
    }
}
