-- SPDX-License-Identifier: GPL-2.0-or-later
-- SPDX-FileCopyrightText: Netresearch DTT GmbH

#
# Business columns only; uid, pid and the ctrl-declared columns come from the TCA.
#
CREATE TABLE tx_childrenfixture_item (
	parent_content int(11) unsigned DEFAULT '0' NOT NULL,
	header varchar(255) DEFAULT '' NOT NULL,
	subheader varchar(255) DEFAULT '' NOT NULL,
	bodytext text,
	link varchar(1024) DEFAULT '' NOT NULL,
	link_title varchar(255) DEFAULT '' NOT NULL,
	icon varchar(60) DEFAULT '' NOT NULL,
	image int(11) unsigned DEFAULT '0' NOT NULL
);

CREATE TABLE tx_childrenfixture_slide (
	parent_content int(11) unsigned DEFAULT '0' NOT NULL,
	kind varchar(32) DEFAULT 'text' NOT NULL,
	header varchar(255) DEFAULT '' NOT NULL
);

CREATE TABLE tt_content (
	tx_childrenfixture_items int(11) unsigned DEFAULT '0' NOT NULL,
	tx_childrenfixture_slides int(11) unsigned DEFAULT '0' NOT NULL,
	tx_childrenfixture_mm int(11) unsigned DEFAULT '0' NOT NULL,
	tx_childrenfixture_folder text,
	tx_childrenfixture_required text
);
