-- SPDX-License-Identifier: GPL-2.0-or-later
-- SPDX-FileCopyrightText: Netresearch DTT GmbH

-- Seed for the two screenshots.spec.ts shots of the group usage feature
-- (ADR-277): two editor groups, a head editors' group holding the
-- usage_view_groups grant with both assigned, and one user each. The head
-- editor gets the admin's password hash, so the fixtures need only another
-- username. workspace_perms 1 is access to the live workspace: with
-- EXT:workspaces installed, a user without it sees no module at all.
--
-- Two thirds of the usage rows move to the editors. `nrllm:demo:seed` books
-- every row on be_user 1, the admin `_cli_` user `typo3 setup` creates, whom
-- the module never shows; without the move the head editor sees nobody.
-- Where no usage rows exist (CI), the move changes nothing.
--
-- Applied by Tests/E2E/seed-and-test.sh (MariaDB, CI) and by
-- Build/Scripts/screenshots.sh (SQLite), so plain SQL both accept.
-- Re-runnable (DELETE first).
DELETE FROM be_users WHERE uid IN (10, 11, 12);
DELETE FROM be_groups WHERE uid IN (10, 11, 12);
INSERT INTO be_groups (uid, pid, title, custom_options, groupMods, tx_nrllm_usage_visible_groups, workspace_perms, tstamp, crdate) VALUES
(10, 0, 'News desk', '', '', '', 1, 1750000000, 1750000000),
(11, 0, 'Sports desk', '', '', '', 1, 1750000000, 1750000000),
(12, 0, 'Head editors', 'tx_nrllm_grants:usage_view_groups', 'nrllm_groupusage', '10,11', 1, 1750000000, 1750000000);
INSERT INTO be_users (uid, pid, username, realName, password, admin, usergroup, tstamp, crdate)
SELECT 10, 0, 'news-alice', 'Alice News', password, 0, '10', 1750000000, 1750000000 FROM be_users WHERE username = 'admin';
INSERT INTO be_users (uid, pid, username, realName, password, admin, usergroup, tstamp, crdate)
SELECT 11, 0, 'sports-bob', 'Bob Sports', password, 0, '11', 1750000000, 1750000000 FROM be_users WHERE username = 'admin';
INSERT INTO be_users (uid, pid, username, realName, password, admin, usergroup, tstamp, crdate)
SELECT 12, 0, 'head-hugo', 'Hugo Head', password, 0, '12', 1750000000, 1750000000 FROM be_users WHERE username = 'admin';
UPDATE tx_nrllm_service_usage SET be_user = CASE uid % 3 WHEN 0 THEN 10 WHEN 1 THEN 11 ELSE be_user END;
