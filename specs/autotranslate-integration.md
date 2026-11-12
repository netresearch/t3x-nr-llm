<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# EXT:autotranslate translates through nr-llm

Decision record: [ADR-289](../Documentation/Adr/Adr289AutotranslateTranslatesThroughNrLlm.rst).

## Requirements

- R1. When autotranslate's setting `translatorClass` names
  `AutotranslateTranslator`, autotranslate resolves it from the container as a
  `\DeepL\Translator`. Proven by `AutotranslateIntegrationTest` and
  `AutotranslateCompilerPassTest`.
- R2. One `translateText()` call sends its non-blank texts to nr-llm in one
  batch, in order, and answers every text in its own place. A blank text is
  answered with itself and not sent. Fewer answers than texts, a blank answer
  and an answer cut off at the output limit fail the call.
  Proven by `AutotranslateTranslatorTest`.
- R3. DeepL's `tag_handling` `html` or `xml` reaches nr-llm as tag handling;
  a target keeps its region as `en-GB`, a source has none; the nr-llm translator is
  `autotranslate.translator`, or the service's choice when empty. Proven by
  `AutotranslateTranslatorTest`.
- R4. On the command line, with `worker.technicalBeUserUid` set, the provider
  key is read as that backend user; otherwise as the caller. Proven by
  `AutotranslateTranslatorTest`.
- R5. No method of the translator reaches DeepL: quota, glossaries and
  languages are answered locally, and any other method fails at a server
  address under `.invalid`. Proven by `AutotranslateTranslatorTest`.
- R6. While `translatorClass` names nr-llm's translator, a translated page or
  content element whose text columns hold text the translation changed gets
  `tx_nrllm_ai_written = 1`; with AI Label loaded and the table enabled, it
  gets AI Label's AI-modified origin (AI-generated kept) with the review
  cleared. Both are columns of the same autotranslate write. Proven by
  `MarkAutotranslatedRecordTest` and `AutotranslateIntegrationTest`.
- R7. Nothing is registered unless autotranslate is an active extension; the
  AI Label part needs AI Label too. Proven by `AutotranslateCompilerPassTest`.

## Out of scope

- The workflow itself: queue, backend module, context menu, console command,
  per-table settings, retranslation on change. autotranslate owns them.
- Creating only missing translations in a batch; that is an autotranslate mode.
- nr-llm's site glossary, because autotranslate does not pass the page.
- Marking translations autotranslate makes with DeepL itself.
