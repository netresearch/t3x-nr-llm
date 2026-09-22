#
# Business columns only; uid, pid and the ctrl-declared columns come from the TCA.
#
CREATE TABLE tx_writerfixture_item (
	title varchar(255) DEFAULT '' NOT NULL,
	teaser text,
	body text,
	kind varchar(32) DEFAULT 'note' NOT NULL,
	published_at int(11) unsigned DEFAULT '0' NOT NULL,
	priority int(11) DEFAULT '0' NOT NULL,
	featured smallint(5) unsigned DEFAULT '0' NOT NULL,
	contact varchar(255) DEFAULT '' NOT NULL,
	tone varchar(32) DEFAULT '' NOT NULL,
	rating double(11,2) DEFAULT '0.00' NOT NULL,
	mood varchar(32) DEFAULT '' NOT NULL,
	related text
);

CREATE TABLE tx_writerfixture_plain (
	title varchar(255) DEFAULT '' NOT NULL
);

CREATE TABLE tx_writerfixture_variant (
	title varchar(255) DEFAULT '' NOT NULL,
	variant varchar(32) DEFAULT '' NOT NULL
);
