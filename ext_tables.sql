#
# Table structure for table 'tx_nrllm_provider'
# API provider connections (endpoint, credentials, adapter type)
#
CREATE TABLE tx_nrllm_provider (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    -- Identity
    identifier varchar(100) DEFAULT '' NOT NULL,
    name varchar(255) DEFAULT '' NOT NULL,
    description text,

    -- Connection settings
    adapter_type varchar(50) DEFAULT '' NOT NULL,
    endpoint_url varchar(500) DEFAULT '' NOT NULL,
    api_key varchar(500) DEFAULT '' NOT NULL,
    organization_id varchar(100) DEFAULT '' NOT NULL,

    -- Request settings
    timeout int(11) DEFAULT '30' NOT NULL,
    max_retries int(11) DEFAULT '3' NOT NULL,

    -- Additional options (JSON)
    options text,

    -- Status
    is_active tinyint(1) DEFAULT '1' NOT NULL,
    sorting int(11) unsigned DEFAULT '0' NOT NULL,

    -- Standard TYPO3 fields
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    cruser_id int(11) unsigned DEFAULT '0' NOT NULL,
    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
    hidden tinyint(4) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    UNIQUE KEY identifier (identifier, deleted)
);

#
# Table structure for table 'tx_nrllm_model'
# Available LLM models with capabilities and pricing
#
CREATE TABLE tx_nrllm_model (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    -- Identity
    identifier varchar(100) DEFAULT '' NOT NULL,
    name varchar(255) DEFAULT '' NOT NULL,
    description text,

    -- Provider relation
    provider_uid int(11) unsigned DEFAULT '0' NOT NULL,

    -- Model settings
    model_id varchar(150) DEFAULT '' NOT NULL,
    context_length int(11) unsigned DEFAULT '0' NOT NULL,
    max_output_tokens int(11) unsigned DEFAULT '0' NOT NULL,

    -- Capabilities (comma-separated: chat,completion,embeddings,vision,streaming,tools)
    capabilities varchar(255) DEFAULT '' NOT NULL,

    -- Pricing (cents per 1M tokens)
    cost_input int(11) unsigned DEFAULT '0' NOT NULL,
    cost_output int(11) unsigned DEFAULT '0' NOT NULL,

    -- Status
    is_active tinyint(1) DEFAULT '1' NOT NULL,
    is_default tinyint(1) DEFAULT '0' NOT NULL,
    sorting int(11) unsigned DEFAULT '0' NOT NULL,

    -- Standard TYPO3 fields
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    cruser_id int(11) unsigned DEFAULT '0' NOT NULL,
    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
    hidden tinyint(4) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY provider_uid (provider_uid),
    UNIQUE KEY identifier (identifier, deleted)
);

#
# Table structure for table 'tx_nrllm_configuration'
# Named LLM configuration presets with provider, model, parameters, and access control
#
CREATE TABLE tx_nrllm_configuration (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    -- Identity
    identifier varchar(100) DEFAULT '' NOT NULL,
    name varchar(255) DEFAULT '' NOT NULL,
    description text,

    -- Model relation (new multi-tier architecture)
    model_uid int(11) unsigned DEFAULT '0' NOT NULL,

    -- Provider configuration (deprecated - kept for migration)
    provider varchar(50) DEFAULT '' NOT NULL,
    model varchar(100) DEFAULT '' NOT NULL,
    translator varchar(50) DEFAULT '' NOT NULL,
    system_prompt mediumtext,

    -- Model parameters
    temperature decimal(3,2) DEFAULT '0.70' NOT NULL,
    max_tokens int(11) DEFAULT '1000' NOT NULL,
    top_p decimal(3,2) DEFAULT '1.00' NOT NULL,
    frequency_penalty decimal(3,2) DEFAULT '0.00' NOT NULL,
    presence_penalty decimal(3,2) DEFAULT '0.00' NOT NULL,
    options text,

    -- Usage limits
    max_requests_per_day int(11) DEFAULT '0' NOT NULL,
    max_tokens_per_day int(11) DEFAULT '0' NOT NULL,
    max_cost_per_day decimal(10,2) DEFAULT '0.00' NOT NULL,

    -- Status
    is_active tinyint(1) DEFAULT '1' NOT NULL,
    is_default tinyint(1) DEFAULT '0' NOT NULL,

    -- Access control (MM relation to be_groups)
    allowed_groups int(11) DEFAULT '0' NOT NULL,

    -- Standard TYPO3 fields
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    cruser_id int(11) unsigned DEFAULT '0' NOT NULL,
    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
    hidden tinyint(4) unsigned DEFAULT '0' NOT NULL,
    sorting int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY model_uid (model_uid),
    UNIQUE KEY identifier (identifier, deleted)
);

#
# MM table for backend user group access control
#
CREATE TABLE tx_nrllm_configuration_begroups_mm (
    uid_local int(11) unsigned DEFAULT '0' NOT NULL,
    uid_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    sorting int(11) unsigned DEFAULT '0' NOT NULL,
    sorting_foreign int(11) unsigned DEFAULT '0' NOT NULL,

    KEY uid_local (uid_local),
    KEY uid_foreign (uid_foreign)
);

#
# Table structure for table 'tx_nrllm_prompttemplate'
# Reusable prompt templates with versioning and performance tracking
#
CREATE TABLE tx_nrllm_prompttemplate (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    -- Identity
    identifier varchar(100) DEFAULT '' NOT NULL,
    title varchar(255) DEFAULT '' NOT NULL,
    description text,

    -- Feature binding
    feature varchar(50) DEFAULT '' NOT NULL,

    -- Prompt content
    system_prompt mediumtext,
    user_prompt_template mediumtext,

    -- Versioning
    version int(11) DEFAULT '1' NOT NULL,
    parent_uid int(11) DEFAULT '0' NOT NULL,

    -- Status
    is_active tinyint(1) DEFAULT '1' NOT NULL,
    is_default tinyint(1) DEFAULT '0' NOT NULL,

    -- Model configuration
    provider varchar(50) DEFAULT '' NOT NULL,
    model varchar(100) DEFAULT '' NOT NULL,
    temperature decimal(3,2) DEFAULT '0.70' NOT NULL,
    max_tokens int(11) DEFAULT '1000' NOT NULL,
    top_p decimal(3,2) DEFAULT '1.00' NOT NULL,

    -- Variables definition (JSON)
    variables text,

    -- Example and metadata
    example_output text,
    tags text,

    -- Performance tracking
    usage_count int(11) DEFAULT '0' NOT NULL,
    avg_response_time int(11) DEFAULT '0' NOT NULL,
    avg_tokens_used int(11) DEFAULT '0' NOT NULL,
    quality_score decimal(3,2) DEFAULT '0.00' NOT NULL,

    -- Standard TYPO3 fields
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    cruser_id int(11) unsigned DEFAULT '0' NOT NULL,
    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
    hidden tinyint(4) unsigned DEFAULT '0' NOT NULL,
    sorting int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY identifier (identifier),
    KEY feature (feature),
    KEY parent_uid (parent_uid)
);

#
# Table for tracking specialized service usage (translation, speech, image)
#
CREATE TABLE tx_nrllm_service_usage (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,

    -- Service identification
    service_type varchar(50) DEFAULT '' NOT NULL,
    service_provider varchar(50) DEFAULT '' NOT NULL,
    configuration_uid int(11) unsigned DEFAULT '0' NOT NULL,

    -- User context
    be_user int(11) unsigned DEFAULT '0' NOT NULL,

    -- Usage metrics
    request_count int(11) unsigned DEFAULT '0' NOT NULL,
    tokens_used int(11) unsigned DEFAULT '0' NOT NULL,
    characters_used int(11) unsigned DEFAULT '0' NOT NULL,
    audio_seconds_used int(11) unsigned DEFAULT '0' NOT NULL,
    images_generated int(11) unsigned DEFAULT '0' NOT NULL,

    -- Cost tracking
    estimated_cost decimal(10,6) DEFAULT '0.000000' NOT NULL,

    -- Time tracking
    request_date int(11) unsigned DEFAULT '0' NOT NULL,

    -- Standard TYPO3 fields
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY lookup (service_type, service_provider, request_date),
    KEY user_lookup (be_user, service_type, request_date),
    KEY config_lookup (configuration_uid, request_date)
);
