-- SPDX-License-Identifier: GPL-2.0-or-later
-- SPDX-FileCopyrightText: Netresearch DTT GmbH

-- E2E seed for batch-record-picker.spec.ts: two root-level pages for the
-- element browser to offer. A fresh TYPO3 setup has no page at all, so the
-- record browser would have nothing to pick.
--
-- CI-only, applied by Tests/E2E/seed-and-test.sh after TYPO3 setup.
-- Re-runnable (DELETE first).
DELETE FROM pages WHERE uid IN (41, 42);
INSERT INTO pages (uid, pid, title, slug, doktype, perms_userid, perms_user, perms_everybody, crdate, tstamp) VALUES
(41, 0, 'Products', '/products', 1, 1, 31, 31, 1750000000, 1750000000),
(42, 0, 'About us', '/about-us', 1, 1, 31, 31, 1750000000, 1750000000);
