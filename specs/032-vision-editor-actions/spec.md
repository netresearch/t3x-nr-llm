<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# A vision editor action sends the image

The editor actions for the alternative text and the file metadata tell the model a table and a uid. The model never sees the image, so what it writes is a guess. The run now carries the image, downscaled first, and an operator decides per field what the action fills (ADR-235).

## Required behavior

1. `EditorAction` gains `visionFields`. `set_file_alternative_text` declares `alternative`, `update_fal_asset_meta` declares `title` and `description`. `EditorAction` is outside the `@api` surface, so `Tests/Unit/Api/api-surface.txt` does not change.
2. A run of such an action holds a second user message with the image as a data URI. The first message stays a string, so the input guardrails read the editor's note.
3. The image is scaled to `vision.maxImageEdge` (default 1024) through TYPO3's file processing. The bytes are measured: an image still over the bound is not sent, and neither is a format no provider takes.
4. No image is sent from a folder in `vision.excludedFolders`. A subfolder counts; `1:/priv` does not cover `1:/private/`.
5. The file is authorised through `FalStorageGate` as the acting user. A file that is missing, not permitted, excluded or not scalable gives one answer: no run. The editor reads the message for an action that is not available, so no uid is confirmed.
6. `vision.<field>.enabled`, `.maxLength` and `.prompt` exist for `alternative`, `title` and `description`. An action with no field on is not offered. A field that is off is not named in the prompt. A limit is named in the prompt as nine tenths of itself and enforced by the writing tool.
7. An action is not offered on a model that declares capabilities without `vision`. A model that declares none passes, as in model selection (ADR-138).
8. With no limit configured a writer behaves as before.

## Not done

No change to the assistant's use of these tools beyond a configured limit: `enabled` governs the editor action only. No vision for `set_file_reference_alternative_text`, which has no editor action. No translated metadata. The batch of ADR-162 stays synchronous and capped.

## Boundaries

Provider input changes: an image leaves the installation. It is a downscaled copy, the operator can exclude folders, and the storage gate decides per file. Input guardrails do not read images. The image sits in the transcript, so it lands in `suspended_state` (sealed) until the run settles, and in the event stream only at `privacy.level = full`.

## Tests

- Unit `VisionSettingsTest`, `VisionActionBuilderTest`, `FalImageDownscalerTest`, `EditorActionCatalogueTest`, `SetFileAlternativeTextToolTest`, `UpdateFalAssetMetaToolTest`.
- Functional `FalImageDownscalerFunctionalTest`: TYPO3's processing against a real file.
- The provider conversion of a part list stays covered by the existing `ClaudeProviderTest`, `GeminiProviderTest`, `OpenAiProviderTest`.
