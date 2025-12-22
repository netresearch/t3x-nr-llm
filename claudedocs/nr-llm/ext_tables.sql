-- API Keys table with encrypted storage
CREATE TABLE tx_nrllm_apikeys (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    -- Key identification
    provider varchar(50) DEFAULT '' NOT NULL,
    scope varchar(100) DEFAULT 'global' NOT NULL,

    -- Encrypted data (AES-256-GCM)
    encrypted_key text NOT NULL,
    encryption_iv varchar(255) DEFAULT '' NOT NULL,
    encryption_tag varchar(255) DEFAULT '' NOT NULL,

    -- Metadata
    metadata text,
    last_rotated int(11) DEFAULT '0' NOT NULL,

    -- Standard TYPO3 fields
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
    hidden tinyint(4) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    UNIQUE KEY provider_scope (provider, scope, deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit log table for security events
CREATE TABLE tx_nrllm_audit (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    -- Event information
    event_type varchar(50) DEFAULT '' NOT NULL,
    severity tinyint(1) DEFAULT '0' NOT NULL,
    message text NOT NULL,

    -- User information
    user_id int(11) DEFAULT '0' NOT NULL,
    username varchar(255) DEFAULT '' NOT NULL,
    ip_address varchar(45) DEFAULT '' NOT NULL,
    user_agent text,

    -- Event data (JSON)
    data mediumtext,

    -- Privacy compliance
    anonymized tinyint(1) DEFAULT '0' NOT NULL,

    -- Timestamp
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY event_type (event_type),
    KEY user_id (user_id),
    KEY severity (severity),
    KEY tstamp (tstamp),
    KEY anonymized (anonymized)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- LLM usage tracking (for quota enforcement and analytics)
CREATE TABLE tx_nrllm_usage (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    -- User/site identification
    user_id int(11) DEFAULT '0' NOT NULL,
    site_identifier varchar(100) DEFAULT '' NOT NULL,

    -- Request information
    provider varchar(50) DEFAULT '' NOT NULL,
    model varchar(100) DEFAULT '' NOT NULL,

    -- Token usage
    prompt_tokens int(11) DEFAULT '0' NOT NULL,
    completion_tokens int(11) DEFAULT '0' NOT NULL,
    total_tokens int(11) DEFAULT '0' NOT NULL,

    -- Cost tracking (optional)
    estimated_cost decimal(10,6) DEFAULT '0.000000',

    -- Performance metrics
    request_duration decimal(8,3) DEFAULT '0.000',

    -- Status
    status varchar(20) DEFAULT 'success' NOT NULL,
    error_message text,

    -- Timestamp
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY user_id (user_id),
    KEY site_identifier (site_identifier),
    KEY provider (provider),
    KEY model (model),
    KEY tstamp (tstamp),
    KEY status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quota configuration table
CREATE TABLE tx_nrllm_quotas (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    -- Quota scope
    scope_type varchar(20) DEFAULT 'user' NOT NULL, -- 'user', 'group', 'site', 'global'
    scope_identifier varchar(100) DEFAULT '' NOT NULL,

    -- Quota limits
    requests_per_hour int(11) DEFAULT '0' NOT NULL,
    requests_per_day int(11) DEFAULT '0' NOT NULL,
    tokens_per_hour int(11) DEFAULT '0' NOT NULL,
    tokens_per_day int(11) DEFAULT '0' NOT NULL,
    monthly_cost_limit decimal(10,2) DEFAULT '0.00',

    -- Standard TYPO3 fields
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
    hidden tinyint(4) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY scope (scope_type, scope_identifier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
