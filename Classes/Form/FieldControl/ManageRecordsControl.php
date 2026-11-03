<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Form\FieldControl;

use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Opens the backend module that lists and manages the records a select
 * chooses from (ADR-255) - in place of core's listModule control, which
 * opens the List module on the edited record's page, where the nr-llm
 * records usually are not.
 *
 * Registered as renderType `nrllmManageRecords`; option `module` names the
 * module identifier, option `title` the button title. Not rendered when the
 * module is unknown or the user has no access to it. Uses core's
 * list-module.js, which asks to save unsaved changes before leaving.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class ManageRecordsControl extends AbstractNode
{
    private const DEFAULT_TITLE = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:choiceHelp.manage';

    public function __construct(
        private readonly UriBuilder $uriBuilder,
        private readonly RecordControlAccess $access,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function render(): array
    {
        $options = ForeignTable::options($this->data, 'fieldControlOptions');
        $module  = is_string($options['module'] ?? null) ? $options['module'] : '';
        if ($module === '' || !$this->access->mayManage($module)) {
            return [];
        }

        $id = StringUtility::getUniqueId('t3js-formengine-fieldcontrol-');

        /** @var array<string, mixed> $result */
        $result = AccessibleName::add([
            'iconIdentifier' => 'actions-list-alternative',
            'title'          => is_string($options['title'] ?? null) && $options['title'] !== '' ? $options['title'] : self::DEFAULT_TITLE,
            'linkAttributes' => [
                'id'   => $id,
                'href' => (string) $this->uriBuilder->buildUriFromRoute($module),
            ],
            'javaScriptModules' => [
                JavaScriptModuleInstruction::create('@typo3/backend/form-engine/field-control/list-module.js')->instance('#' . $id),
            ],
        ]);

        return $result;
    }
}
