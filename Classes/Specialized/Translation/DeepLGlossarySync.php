<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Translation;

use Netresearch\NrLlm\Service\Glossary\GlossaryResolverInterface;
use Netresearch\NrLlm\Service\Glossary\ResolvedGlossary;
use Netresearch\NrLlm\Specialized\Exception\SpecializedServiceException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps one DeepL glossary per site glossary record in step with its terms
 * (ADR-208).
 *
 * DeepL glossaries are immutable, so "in step" means: the record stores the id
 * of the glossary created for its current terms together with a hash of those
 * terms and the language pair. An unchanged record reuses the id without a
 * request; a changed one gets a new glossary, the record is repointed, and the
 * superseded glossary is deleted — best effort, because a failed cleanup costs
 * one orphaned glossary in the DeepL account and must not fail the translation
 * that triggered it.
 *
 * The id lives on the record rather than in a cache: a flushed cache would
 * recreate every glossary against DeepL's per-account glossary limit, and the
 * old id — the only handle for deleting the superseded glossary — would be
 * gone with it.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class DeepLGlossarySync implements DeepLGlossarySyncInterface
{
    public function __construct(
        private DeepLTranslator $translator,
        private GlossaryResolverInterface $glossaryResolver,
        private LoggerInterface $logger,
    ) {}

    public function glossaryIdFor(ResolvedGlossary $glossary): ?string
    {
        if (!$this->translator->supportsGlossaryLanguagePair($glossary->sourceLanguage, $glossary->targetLanguage)) {
            $this->logger->info('DeepL holds no glossary for this language pair; translating without the site glossary', [
                'glossary_uid' => $glossary->uid,
                'source_language' => $glossary->sourceLanguage,
                'target_language' => $glossary->targetLanguage,
            ]);

            return null;
        }

        $hash = $glossary->entriesHash();
        if ($glossary->deeplGlossaryId !== '' && $glossary->deeplEntriesHash === $hash) {
            return $glossary->deeplGlossaryId;
        }

        $glossaryId = $this->translator->createGlossary(
            sprintf('nr_llm glossary %d (%s-%s)', $glossary->uid, $glossary->sourceLanguage, $glossary->targetLanguage),
            $glossary->sourceLanguage,
            $glossary->targetLanguage,
            $glossary->terms->toTsv(),
        );
        $this->glossaryResolver->storeDeepLGlossary($glossary->uid, $glossaryId, $hash);

        $superseded = $glossary->deeplGlossaryId;
        if ($superseded !== '' && $superseded !== $glossaryId
            && !$this->glossaryResolver->isDeepLGlossaryReferenced($superseded, $glossary->uid)
        ) {
            try {
                $this->translator->deleteGlossary($superseded);
            } catch (Throwable $e) {
                $this->logger->warning('Could not delete the superseded DeepL glossary', [
                    'glossary_uid' => $glossary->uid,
                    'deepl_glossary_id' => $superseded,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $glossaryId;
    }

    /**
     * DeepL documents no dedicated status for an unknown `glossary_id` on the
     * translate endpoint (https://developers.deepl.com/api-reference/translate
     * lists 400 "Bad request" and 404 "The requested resource could not be
     * found"), so a 400 or 404 whose message names the glossary is taken as
     * one. Anything else — quota, rate limit, authentication, a 400 about
     * another parameter — is not.
     */
    public function isStaleGlossaryError(Throwable $e): bool
    {
        return $e instanceof SpecializedServiceException
            && in_array($e->getStatusCode(), [400, 404], true)
            && stripos($e->getMessage(), 'glossary') !== false;
    }

    public function recreate(ResolvedGlossary $glossary): ?string
    {
        $this->logger->warning('DeepL did not accept the stored glossary; creating it again', [
            'glossary_uid' => $glossary->uid,
        ]);

        // Cleared first: should the create fail, the record no longer points
        // at an id DeepL has already refused.
        $this->glossaryResolver->storeDeepLGlossary($glossary->uid, '', '');

        return $this->glossaryIdFor(new ResolvedGlossary(
            $glossary->uid,
            $glossary->sourceLanguage,
            $glossary->targetLanguage,
            $glossary->terms,
        ));
    }
}
