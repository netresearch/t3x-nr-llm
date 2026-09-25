# The translation draft translates its text (NEXT-166)

`create_translation_draft` runs core's `localize` and hides the result. The new
record carried the source text, so a chat asked for a German version produced a
hidden German record in English. `TranslationService` already translates
through DeepL or the LLM translator and applies the site glossary (ADR-208).
This change connects the two. Decision record: ADR-209.

## What it must do

- After `localize`, machine-translate every text column of the record's type
  that holds text in the source, reading the text from the default-language
  record, and write the translations into the new record through the
  DataHandler as the acting user.
- Send rich text as HTML (`tag_handling = html` on DeepL) and plain text as
  text.
- Pass the site of the record's page, so the site glossary for the pair
  applies.
- Offer `translator` = `deepl` | `llm`, default `llm`; refuse a translator the
  installation has not configured before anything is created.
- Name the translator and the fields on the approval preview.
- On any failure of a translation, the write or its read-back: write nothing,
  keep the copied source text, and say so in the result — which still carries
  the write target of the created record.
- Cache translations, opt-in, for both translators, keyed by translator,
  languages, text, glossary and options; never store a failure.

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
