<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# A site picks its LLM configuration and its DeepL key

A site can name the configuration its AI calls use and the DeepL key its translations use; a site that names none uses the installation's (ADR-244).

## Required behavior

1. The site set `netresearch/nr-llm` defines two site settings: `nrllm.configuration`, the identifier of an LLM configuration, and `nrllm.deepl.apiKeyIdentifier`, the identifier of an nr-vault secret. Both are empty by default. The settings hold identifiers, never a key.
2. A caller states its site with `withSite()` on any option object, or with the metadata key `ProviderCallContext::METADATA_SITE`. Both end in the same metadata key of the call, which the term list check (ADR-238) reads. Only `withSite()` also selects the site's configuration (3). `TranslationService` carries the site onto every chat call it makes, the revision (ADR-233) included, and into the translation cache key's configuration fingerprint (ADR-209).
3. `chat()`, `complete()` and `streamChat()` without a pinned provider use the configuration the call's site names instead of the installation default. A site that names none, an unknown site and a call without a site use the installation default as before.
4. A site that names a configuration that does not exist, is inactive or is restricted to backend groups fails the call. It does not fall back to the installation default.
5. `ConfigurationResolver::resolveForSite()` returns the configuration a site names, or null when it names none, so an extension with its own fallback can put the site between its own choice and the installation default.
6. A DeepL translation, a DeepL glossary create and a DeepL glossary delete for a site that names a key use that key; without one, the key of the extension configuration. Free or Pro is decided per key, once per key and process.
7. A site glossary whose DeepL glossary was created under another key is created again under the site's key on its first translation (the stale-glossary path of ADR-208).

## Not done

`embed()`, `vision()`, the conversation service and the specialized image and speech services do not read the site's configuration. A queued agent run does not store the site, so its resumed turns run without it. No per-site base URL or timeout for DeepL. No backend form beyond the TYPO3 site settings editor. Which site a call belongs to is the caller's to state; nr-llm does not guess it from the request.

## Boundaries

Only administrators can change site settings: the settings editor is admin-only in TYPO3 13.4 and 14.3. The settings editor shows the two settings only on a site that depends on the set; a site without the dependency can still set them in `settings.yaml`.

## Tests

- Unit `SiteLlmSettingsTest`, requirement 1 and the unknown-site case of 3.
- Unit `ConfigurationResolverSiteTest`, requirements 3, 4 and 5.
- Unit `CallMetadataFactorySiteTest`, `LlmServiceManagerSiteTest` (including the completion service) and `LlmTranslatorTest`, requirements 2 and 3.
- Functional `SiteSettingsWiringTest`: the container wires the settings reader, and core accepts the set.
- Unit `DeepLTranslatorSiteKeyTest`, requirements 6 and 7 (the glossary sync under the site key; the recreate path itself is pinned in `DeepLGlossarySyncTest`).
