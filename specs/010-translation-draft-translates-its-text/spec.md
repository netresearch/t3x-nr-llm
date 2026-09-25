# The translation draft translates its text (NEXT-166)

`create_translation_draft` runs core's `localize` and hides the result. The new
record carried the source text, so a chat asked for a German version produced a
hidden German record in English. `TranslationService` already translates
through DeepL or the LLM translator and applies the site glossary (ADR-208).
This change connects the two. Decision record: ADR-209.

## What it must do

- After `localize`, machine-translate every text column of the record's type
  (its form, not every column of the table) that holds text in the source,
  reading the text from the default-language record, and write the
  translations into the new record through the DataHandler as the acting user.
  Never a person's name (`pages.author`).
- Refuse a target language the record's site does not define before
  `localize` creates anything.
- Cut a value to an `input` column's TCA `max` before writing, say so, and read
  back what was actually written: every result sentence is true of the stored
  record, whether the write succeeded, failed in the DataHandler's log, or
  failed with an exception from a hook.
- Treat a blank or truncated answer as a failure; size the LLM translator's
  output budget to the text.
- Send rich text as HTML (`tag_handling = html` on DeepL) and plain text as
  text.
- Pass the site of the record's page, so the site glossary for the pair
  applies.
- Offer `translator` = `deepl` | `llm`, default `llm`; refuse a translator the
  installation has not configured before anything is created.
- Name the translator, the pair, the fields, the withheld fields and whether a
  site glossary exists for the pair on the approval preview.
- On any failure of a translation, the write or its read-back: write nothing,
  keep the copied source text, and say so in the result — which still carries
  the write target of the created record.
- Cache translations, opt-in, for both translators, keyed by translator,
  languages, text, glossary, options and — for the LLM translator without a
  pinned provider — the resolved default configuration; never store a
  failure, a blank or a truncated answer; flush on a glossary save or delete.

## What it explicitly does not do

- No transparency-notice field on `pages` or `tt_content`, and no listener on
  `AfterAiRecordWrittenEvent`: the extension does not extend foreign tables,
  and ADR-187 ships no listener. ADR-209 records why.
- No change to visibility: the translation stays hidden.
- No caching on the provider pipeline's `CacheMiddleware`: the chat terminal
  returns an object, which that middleware never stores.
- No cache for `translateBatchWithTranslator()` or the LLM-only `translate()`.

## Which suite proves each requirement

| Requirement | Suite and test |
|---|---|
| Text fields translated from the source row | functional `CreateTranslationDraftToolTest::aContentElementTranslationIsCreatedHiddenAndConnected`, `aPageTranslationCarriesTheTranslatedTitle` |
| Rich text as HTML, the named translator only | functional `richTextGoesAsHtmlAndKeepsItsMarkup` |
| Site glossary reaches the translator | functional `theSiteGlossaryReachesTheTranslator` |
| Code-editor column left out | functional `aColumnInACodeEditorIsNotTranslated` |
| Failure said plainly, source text kept, write target kept | functional `aFailedTranslationLeavesTheSourceTextAndSaysSo` |
| Unconfigured translator refused before creating | functional `aTranslatorThatIsNotConfiguredIsRefusedBeforeAnythingIsCreated` |
| Preview names translator and fields | functional `thePreviewNamesTheMachineTranslationAndTheTranslator` |
| Repeated translation from the cache, end to end | functional `aRepeatedTranslationIsAnsweredFromTheCache` |
| Cache: one translation per identical request, new one for changed text, glossary, languages, translator, markup, formality; attribution not in the key; opt-in; failure not stored | unit `TranslationServiceCacheTest` |
| Argument validation, description | unit `CreateTranslationDraftToolTest` |
| Value cut to TCA `max` and reported as translated | functional `aTranslationLongerThanTheColumnIsCutAndSaidSo` |
| Nothing written when the SECOND field fails | functional `aFailedTranslationLeavesTheSourceTextAndSaysSo` |
| Truncated answer not written | functional `aTruncatedTranslationIsNotWritten` |
| Hook exception during the write keeps the created record | functional `aHookThatFailsWhileTheTranslationIsWrittenKeepsTheCreatedRecord` |
| Non-admin without a column grant: not sent, named | functional `aFieldTheEditorMayNotEditIsNotSentAndIsNamed` |
| Only the type's columns | functional `aColumnOutsideTheTypesFormIsNotSent` |
| Undefined language refused before creating | functional `aLanguageTheSiteDoesNotDefineIsRefusedBeforeAnythingIsCreated` |
| Glossary line only with a glossary | functional `thePreviewNamesTheSiteGlossaryOnlyWhenThereIsOne` |
| `l10n_mode`, `defaultAsReadonly`, `readOnly`, author | functional `aColumnATranslationDoesNotOwnIsNotSent` (+ control), `theAuthorOfAPageIsNotTranslated` |
| Blank / truncated not cached; default configuration in the key | unit `TranslationServiceCacheTest` |
| Output budget and truncation metadata | unit `LlmTranslatorTest` |
| Glossary, configuration, model, provider, skill, snippet save/delete flush the cache | functional `TranslationCacheFlushHookTest` |
| The default configuration's stored `tstamp` is hydrated and changes the key | functional `TranslationServiceDefaultConfigurationKeyTest` |
| Pinned model / pinned provider / skills in the key | unit `TranslationServiceCacheTest` |
| `max_tokens` capped at the model's output limit | unit `LlmServiceManagerTest::chatWithConfigurationCapsAnExplicitOverrideAtTheModelLimit` (+ unknown-limit case) |
| Failure after the row is stored is named; a dropped field reads PARTLY | functional `aHookThatFailsAfterTheRowIsStoredIsNamedWithTheSuccess`, `aFieldTheWriteDropsIsReportedAsPartlyTranslated` |
| A page in no site is refused for that reason | functional `aPageInNoSiteIsRefusedForThatReason` |
