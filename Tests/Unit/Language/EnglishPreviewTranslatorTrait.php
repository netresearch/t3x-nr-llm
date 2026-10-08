<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Language;

use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * An {@see ApprovalPreviewTranslator} for unit tests, without a TYPO3
 * container, that answers in the language of the user it is asked for
 * (ADR-213): the catalogue's German texts for a user whose `lang` is `de`,
 * the English source texts for anyone else and for no user at all. Keyed on
 * the user so that a caller passing the wrong user — the viewer for the
 * acting one, or none — reads in the wrong language and fails its test.
 */
trait EnglishPreviewTranslatorTrait
{
    private function englishTranslator(): ApprovalPreviewTranslator
    {
        $english = self::createStub(LanguageService::class);
        $english->method('sL')->willReturnCallback(static fn(string $key): string => LabelCatalogue::source($key) ?? '');
        $german = self::createStub(LanguageService::class);
        $german->method('sL')->willReturnCallback(static fn(string $key): string => LabelCatalogue::target($key) ?? '');

        $factory = self::createStub(LanguageServiceFactory::class);
        $factory->method('createFromUserPreferences')->willReturnCallback(
            static fn(?AbstractUserAuthentication $user): LanguageService => is_array($user?->user) && ($user->user['lang'] ?? null) === 'de' ? $german : $english,
        );

        return new ApprovalPreviewTranslator($factory);
    }

    /**
     * A backend user whose language is `$language`, for a context or a viewer.
     */
    private function userIn(string $language): BackendUserAuthentication
    {
        $user       = new BackendUserAuthentication();
        $user->user = ['uid' => 1, 'admin' => 1, 'lang' => $language];

        return $user;
    }
}
