-- SPDX-License-Identifier: GPL-2.0-or-later
-- SPDX-FileCopyrightText: Netresearch DTT GmbH

-- E2E seed for record-select-help.spec.ts: one provider, one model, two
-- configurations that differ only in what their descriptions say, and a task
-- that uses the first one. The help text under the task's configuration
-- select must name each configuration's purpose and model.
--
-- CI-only, applied by Tests/E2E/seed-and-test.sh after TYPO3 setup.
-- Re-runnable (DELETE first). The uids sit far above anything a test creates.
DELETE FROM tx_nrllm_provider WHERE uid = 9101;
DELETE FROM tx_nrllm_model WHERE uid = 9201;
DELETE FROM tx_nrllm_configuration WHERE uid IN (9301, 9302);
DELETE FROM tx_nrllm_task WHERE uid = 9401;
INSERT INTO tx_nrllm_provider (uid, pid, identifier, name, description, adapter_type, is_active, crdate, tstamp) VALUES
(9101, 0, 'e2e-choice-provider', 'E2E Choice Provider', '', 'ollama', 1, 1750000000, 1750000000);
INSERT INTO tx_nrllm_model (uid, pid, identifier, name, description, provider_uid, model_id, is_active, crdate, tstamp) VALUES
(9201, 0, 'e2e-choice-model', 'E2E Choice Model', '', 9101, 'e2e-choice:1b', 1, 1750000000, 1750000000);
INSERT INTO tx_nrllm_configuration (uid, pid, identifier, name, description, model_selection_mode, model_uid, is_active, crdate, tstamp) VALUES
(9301, 0, 'e2e-choice-teasers', 'E2E Choice A', 'Writes short teasers <b>for pages</b>', 'fixed', 9201, 1, 1750000000, 1750000000),
(9302, 0, 'e2e-choice-legal', 'E2E Choice B', 'Checks legal wording', 'fixed', 9201, 1, 1750000000, 1750000000);
INSERT INTO tx_nrllm_task (uid, pid, identifier, name, description, configuration_uid, is_active, crdate, tstamp) VALUES
(9401, 0, 'e2e-choice-task', 'E2E Choice Task', '', 9301, 1, 1750000000, 1750000000);
