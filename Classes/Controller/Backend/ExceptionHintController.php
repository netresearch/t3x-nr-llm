<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\ExceptionHint;
use Netresearch\NrLlm\Domain\ValueObject\LogExceptionEntry;
use Netresearch\NrLlm\Service\Tool\ExceptionHintService;
use Netresearch\NrLlm\Service\Tool\LogExceptionReader;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Administrators' list of the newest logged errors, each with a model's hint
 * for its cause and next step on request (ADR-258).
 *
 * The list shows what `get_last_exception` heads an entry with — time, class,
 * sanitised message, throw site — and nothing the log files do not already
 * hold. A hint is asked for by POST only, one entry at a time, so neither a
 * page view nor a prefetch sends an error to a provider.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AsController]
final class ExceptionHintController extends ActionController
{
    use ErrorMessageSanitizerTrait;
    use ModuleChromeTrait;
    use RequiresBackendAdminTrait;

    private const LIST_SIZE = 10;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly LogExceptionReader $reader,
        private readonly ExceptionHintService $hints,
        private readonly LlmConfigurationRepository $configurations,
    ) {}

    public function listAction(): ResponseInterface
    {
        return $this->render(null, null);
    }

    /**
     * @param string $entry the key of the entry the administrator chose
     *                      ({@see self::keyOf()}); a position would pick
     *                      whichever error was logged in between
     */
    public function hintAction(string $entry = ''): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
            return $deny;
        }

        if ($this->request->getMethod() !== 'POST') {
            return $this->render(null, null);
        }

        $entries = $this->reader->read(self::LIST_SIZE);
        $index   = null;
        foreach ($entries as $i => $candidate) {
            if ($entry !== '' && hash_equals(self::keyOf($candidate), $entry)) {
                $index = $i;
                break;
            }
        }

        $configuration = $this->configurations->findDefault();
        $hint          = match (true) {
            $index === null                             => null,
            !$configuration instanceof LlmConfiguration => ExceptionHint::refused('No default LLM configuration.'),
            default                                     => $this->hints->hint($entries[$index], $configuration),
        };

        return $this->render($index, $hint, $entries);
    }

    /**
     * Names one entry across two reads of the log: what it was logged with,
     * never where it stands in the list.
     */
    public static function keyOf(LogExceptionEntry $entry): string
    {
        $frame = $entry->frames[0] ?? null;

        return hash('sha256', implode("\n", [
            (string) $entry->timestamp,
            $entry->level,
            $entry->component,
            $entry->message,
            $entry->exceptionClass ?? '',
            $frame !== null ? $frame['file'] . ':' . $frame['line'] : '',
        ]));
    }

    /**
     * @param list<LogExceptionEntry>|null $entries
     */
    private function render(?int $hintIndex, ?ExceptionHint $hint, ?array $entries = null): ResponseInterface
    {
        $entries ??= $this->reader->read(self::LIST_SIZE);
        $rows = [];
        foreach ($entries as $i => $entry) {
            $frame  = $entry->frames[0] ?? null;
            $rows[] = [
                'index'     => $i,
                'key'       => self::keyOf($entry),
                'time'      => gmdate('Y-m-d H:i:s', $entry->timestamp) . ' UTC',
                'level'     => $entry->level,
                'class'     => $entry->exceptionClass ?? '',
                'message'   => $this->sanitizeErrorMessage($entry->message),
                'location'  => $frame !== null ? sprintf('%s:%d', $frame['file'], $frame['line']) : '',
                'hint'      => $i === $hintIndex ? $hint : null,
                'hintFrame' => $i === $hintIndex && $hint?->frame !== null
                    ? sprintf('#%d %s:%d', $hint->frame, $entry->frames[$hint->frame]['file'] ?? '', $entry->frames[$hint->frame]['line'] ?? 0)
                    : '',
            ];
        }

        $configuration = $this->configurations->findDefault();

        $view = $this->moduleTemplateFactory->create($this->request);
        $this->applyModuleChrome($view, $this->request);
        $view->assignMultiple([
            'rows'          => $rows,
            'configuration' => $configuration instanceof LlmConfiguration ? $configuration->getName() : '',
        ]);

        return $view->renderResponse('Backend/ExceptionHint/List');
    }
}
