-- E2E seed (ADR-109): two deterministic waiting agent runs so the Playwright
-- accessibility suite can exercise the Agent Runs inbox's approve/deny and
-- schema-input forms (an empty inbox only renders the module chrome).
--
-- CI-only, applied by Tests/E2E/seed-and-test.sh AFTER TYPO3 setup + the PHP
-- server start. The suspended_state JSON was generated from the real
-- SuspendedRunState/ToolCall value objects and verified to parse into the
-- approval/input view modes (WaitingRunViewFactory). Re-runnable (DELETE first).
DELETE FROM tx_nrllm_agentrun WHERE uuid IN ('e2e-approval-0001', 'e2e-input-0001');
INSERT INTO tx_nrllm_agentrun (pid, uuid, status, configuration_identifier, be_user, crdate, tstamp, suspended_state) VALUES
(0, 'e2e-approval-0001', 'waiting_for_approval', 'e2e-demo', 1, 1750000000, 1750000000, '{"messages":[],"pendingCalls":[{"id":"call_1","type":"function","function":{"name":"delete_thing","arguments":{"uid":42,"table":"pages"}}}],"iterations":1,"promptTokens":0,"completionTokens":0,"allowedToolNames":null,"options":[],"inputToolName":null,"inputSchema":[]}'),
(0, 'e2e-input-0001', 'waiting_for_input', 'e2e-demo', 1, 1750000100, 1750000100, '{"messages":[],"pendingCalls":[{"id":"call_1","type":"function","function":{"name":"ask","arguments":[]}}],"iterations":1,"promptTokens":0,"completionTokens":0,"allowedToolNames":null,"options":[],"inputToolName":"ask","inputSchema":{"type":"object","properties":{"reason":{"type":"string","title":"Reason","description":"Why continue"},"max_items":{"type":"integer"},"confirm":{"type":"boolean","title":"Confirm"}},"required":["reason"]}}');
