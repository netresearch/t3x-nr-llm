<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Enum\ExceptionHintRefusal;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\ExceptionHint;
use Netresearch\NrLlm\Domain\ValueObject\LogExceptionEntry;
use Netresearch\NrLlm\Service\TestPromptResolverService;
use Netresearch\NrLlm\Service\Tool\ExceptionHintService;
use Netresearch\NrLlm\Service\Tool\LogExceptionReader;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
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
 * Which configuration is asked is each administrator's own choice, kept in
 * their backend user's module data and changed by POST only; without a
 * choice, or when the chosen configuration is gone or deactivated, the
 * default configuration is asked.
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

    /** The module whose data keeps the administrator's choice. */
    public const MODULE = 'nrllm_exceptions';

    /** The key of the chosen configuration's identifier in the module data. */
    public const CHOICE = 'configuration';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly LogExceptionReader $reader,
        private readonly ExceptionHintService $hints,
        private readonly LlmConfigurationRepository $configurations,
        private readonly BackendUriBuilder $backendUriBuilder,
    ) {}

    public function listAction(): ResponseInterface
    {
        return $this->render(null, null);
    }

    /**
     * Keeps the configuration the administrator chose; an empty identifier
     * goes back to the default configuration. Only an active configuration is
     * kept, and only from a POST, so a link cannot change the choice.
     */
    public function chooseAction(string $configuration = ''): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
            return $deny;
        }

        if ($this->request->getMethod() !== 'POST') {
            return $this->render(null, null);
        }

        $identifier = trim($configuration);
        $chosen     = $identifier === '' ? null : $this->configurations->findOneByIdentifier($identifier);
        if ($identifier !== '' && (!$chosen instanceof LlmConfiguration || !$chosen->isActive())) {
            return $this->render(null, null, choiceRefused: true);
        }

        $backendUser = $this->backendUser();
        if ($backendUser instanceof BackendUserAuthentication) {
            $data = $backendUser->getModuleData(self::MODULE);
            $data = is_array($data) ? $data : [];

            $data[self::CHOICE] = $identifier;
            $backendUser->pushModuleData(self::MODULE, $data);
        }

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

        $configuration = $this->chosen()['configuration'];
        $hint          = match (true) {
            $index === null                             => null,
            !$configuration instanceof LlmConfiguration => ExceptionHint::refused(ExceptionHintRefusal::NO_CONFIGURATION),
            default                                     => $this->hints->hint($entries[$index], $configuration, $this->languageName()),
        };

        return $this->render($index, $hint, $entries, $index === null);
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
     * The configuration a hint is asked of: the administrator's choice while
     * it names an active configuration, the default configuration otherwise.
     *
     * @return array{configuration: LlmConfiguration|null, stored: string, dropped: bool}
     */
    private function chosen(): array
    {
        $data   = $this->backendUser()?->getModuleData(self::MODULE);
        $stored = is_array($data) && is_string($data[self::CHOICE] ?? null) ? $data[self::CHOICE] : '';

        if ($stored !== '') {
            $configuration = $this->configurations->findOneByIdentifier($stored);
            if ($configuration instanceof LlmConfiguration && $configuration->isActive()) {
                return ['configuration' => $configuration, 'stored' => $stored, 'dropped' => false];
            }
        }

        return ['configuration' => $this->configurations->findDefault(), 'stored' => '', 'dropped' => $stored !== ''];
    }

    /**
     * The active configurations to choose from, each with what a hint may send it.
     *
     * @return list<array{identifier: string, name: string, admission: string, default: bool}>
     */
    private function choices(): array
    {
        $choices = [];
        foreach ($this->configurations->findActive()->toArray() as $configuration) {
            if (!$configuration instanceof LlmConfiguration) {
                continue;
            }

            $choices[] = [
                'identifier' => $configuration->getIdentifier(),
                'name'       => $configuration->getName() !== '' ? $configuration->getName() : $configuration->getIdentifier(),
                'admission'  => $this->hints->admission($configuration)->value,
                'default'    => $configuration->isDefault(),
            ];
        }

        return $choices;
    }

    /**
     * The default configuration's name for the "default" option; a dash when there is none.
     */
    private function defaultName(): string
    {
        $default = $this->configurations->findDefault();
        if (!$default instanceof LlmConfiguration) {
            return '–';
        }

        return $default->getName() !== '' ? $default->getName() : $default->getIdentifier();
    }

    /**
     * The language the model writes a hint in: the administrator's backend language.
     */
    private function languageName(): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;

        return TestPromptResolverService::languageName($languageService instanceof LanguageService ? $languageService->lang : 'default');
    }

    private function backendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }

    private function moduleUri(string $module): string
    {
        try {
            return (string) $this->backendUriBuilder->buildUriFromRoute($module);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @param list<LogExceptionEntry>|null $entries
     */
    private function render(?int $hintIndex, ?ExceptionHint $hint, ?array $entries = null, bool $entryGone = false, bool $choiceRefused = false): ResponseInterface
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
                'refusal'   => $i === $hintIndex && $hint?->reason instanceof ExceptionHintRefusal ? $hint->reason->value : '',
                'hintFrame' => $i === $hintIndex && $hint?->frame !== null
                    ? sprintf('#%d %s:%d', $hint->frame, $entry->frames[$hint->frame]['file'] ?? '', $entry->frames[$hint->frame]['line'] ?? 0)
                    : '',
            ];
        }

        $chosen        = $this->chosen();
        $configuration = $chosen['configuration'];

        $view = $this->moduleTemplateFactory->create($this->request);
        $this->applyModuleChrome($view, $this->request);
        $view->assignMultiple([
            'rows'          => $rows,
            'entryGone'     => $entryGone,
            'choiceRefused' => $choiceRefused,
            'choiceDropped' => $chosen['dropped'],
            'stored'        => $chosen['stored'],
            'choices'       => $this->choices(),
            'defaultName'   => $this->defaultName(),
            'configuration' => $configuration instanceof LlmConfiguration ? $configuration->getName() : '',
            'admission'     => $configuration instanceof LlmConfiguration ? $this->hints->admission($configuration)->value : '',
            'links'         => [
                'configurations' => $this->moduleUri('nrllm_configurations'),
                'providers'      => $this->moduleUri('nrllm_providers'),
            ],
        ]);

        return $view->renderResponse('Backend/ExceptionHint/List');
    }
}
