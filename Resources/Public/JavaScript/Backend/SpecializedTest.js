/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Wires the translation and image-generation forms on the test page to their
 * AJAX endpoints and renders the result.
 *
 * Neither result is stored anywhere: a translation is printed, an image is
 * rendered from the URL or data URI the provider returned and is gone on
 * reload. Taking an image further — into FAL, onto a record — is the consuming
 * extension's job.
 *
 * Loaded as an ES module through the import map, so wrap in DOMContentLoaded
 * and bail out when the elements or the AJAX URLs are absent.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import { readAjaxError } from '@netresearch/nr-llm/Backend/AjaxError.js';

document.addEventListener('DOMContentLoaded', function () {
    wireTranslation();
    wireImage();
    loadTranslators();
});

function show(id, visible) {
    const el = document.getElementById(id);
    if (el) {
        el.style.display = visible ? 'block' : 'none';
    }
}

function setText(id, value) {
    const el = document.getElementById(id);
    if (el) {
        el.textContent = value ?? '';
    }
}

/**
 * Populate the translator picker so an operator sees which ones are
 * configured before running anything.
 */
async function loadTranslators() {
    const select = document.getElementById('translationTranslator');
    const url = TYPO3?.settings?.ajaxUrls?.['nrllm_test_translators'];
    if (!select || !url) {
        return;
    }

    try {
        const data = await (await new AjaxRequest(url).post('')).resolve();
        if (!data.success) {
            return;
        }

        for (const translator of data.translators) {
            const option = document.createElement('option');
            option.value = translator.identifier;
            option.textContent = translator.available
                ? translator.name
                : `${translator.name} (not configured)`;
            select.appendChild(option);
        }
    } catch (error) {
        // The picker is a convenience; a failure here must not block the
        // form, which works with the default (LLM) translator.
    }
}

function wireTranslation() {
    const form = document.getElementById('translationForm');
    const url = TYPO3?.settings?.ajaxUrls?.['nrllm_test_translate'];
    if (!form || !url) {
        return;
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        show('translationLoading', true);
        show('translationResult', false);
        show('translationError', false);

        try {
            const data = await (await new AjaxRequest(url).post(
                JSON.stringify({
                    text: document.getElementById('translationText').value,
                    targetLanguage: document.getElementById('translationTarget').value,
                    sourceLanguage: document.getElementById('translationSource').value,
                    translator: document.getElementById('translationTranslator').value,
                }),
                { headers: { 'Content-Type': 'application/json' } },
            )).resolve();

            show('translationLoading', false);

            if (!data.success) {
                show('translationError', true);
                setText('translationErrorMessage', data.error);
                return;
            }

            show('translationResult', true);
            setText('translationOutput', data.translation);
            setText('translationMeta', [
                `${data.sourceLanguage} → ${data.targetLanguage}`,
                `translator: ${data.translator}`,
                data.charactersUsed != null ? `characters: ${data.charactersUsed}` : null,
                data.usage != null ? `tokens: ${data.usage.totalTokens}` : null,
            ].filter(Boolean).join(' · '));
        } catch (error) {
            show('translationLoading', false);
            show('translationError', true);
            setText('translationErrorMessage', await readAjaxError(error));
        }
    });
}

function wireImage() {
    const form = document.getElementById('imageForm');
    const url = TYPO3?.settings?.ajaxUrls?.['nrllm_test_image'];
    if (!form || !url) {
        return;
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        show('imageLoading', true);
        show('imageResult', false);
        show('imageError', false);

        try {
            const data = await (await new AjaxRequest(url).post(
                JSON.stringify({
                    prompt: document.getElementById('imagePrompt').value,
                    service: document.getElementById('imageService').value,
                }),
                { headers: { 'Content-Type': 'application/json' } },
            )).resolve();

            show('imageLoading', false);

            if (!data.success) {
                show('imageError', true);
                setText('imageErrorMessage', data.error);
                return;
            }

            const preview = document.getElementById('imagePreview');
            if (preview) {
                // dataUrl for the OpenAI family, url for FAL — whichever came back.
                preview.src = data.dataUrl ?? data.url ?? '';
                preview.alt = data.revisedPrompt ?? document.getElementById('imagePrompt').value;
            }

            show('imageResult', true);
            setText('imageMeta', [
                `${data.provider} · ${data.model}`,
                data.size,
                data.revisedPrompt ? `revised: ${data.revisedPrompt}` : null,
            ].filter(Boolean).join(' · '));
        } catch (error) {
            show('imageLoading', false);
            show('imageError', true);
            setText('imageErrorMessage', await readAjaxError(error));
        }
    });
}
