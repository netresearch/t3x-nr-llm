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

    -- Where this provider's inference happens, as declared by the operator
    -- (ADR-094). Governs the most sensitive tool output a run reaching it may
    -- collect. Empty resolves to the strictest zone, so an un-migrated row can
    -- never widen the gate.
    trust_zone varchar(20) DEFAULT '' NOT NULL,
    endpoint_url varchar(500) DEFAULT '' NOT NULL,
    api_key varchar(500) DEFAULT '' NOT NULL,
    organization_id varchar(100) DEFAULT '' NOT NULL,

    -- Request settings (for API operations like list models, test connection)
    api_timeout int(11) DEFAULT '120' NOT NULL,
    max_retries int(11) DEFAULT '3' NOT NULL,

    -- Additional options (JSON)
    options text,

    -- Status and Priority
    is_active tinyint(1) DEFAULT '1' NOT NULL,
    priority int(11) DEFAULT '50' NOT NULL,
    sorting int(11) unsigned DEFAULT '0' NOT NULL,

    -- Standard TYPO3 fields
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
    hidden tinyint(4) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY identifier (identifier),
    KEY priority_active (priority, is_active)
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

    -- Embedding vector dimensionality (0 = unknown)
    dimensions int(11) unsigned DEFAULT '0' NOT NULL,

    -- Capabilities (comma-separated: chat,completion,embeddings,vision,streaming,tools)
    capabilities varchar(255) DEFAULT '' NOT NULL,

    -- Default timeout for LLM inference (seconds, 0 = provider default)
    default_timeout int(11) DEFAULT '120' NOT NULL,

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

    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
    hidden tinyint(4) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY provider_uid (provider_uid),
    KEY identifier (identifier)
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

    -- Dynamic model selection (criteria-based runtime selection)
    model_selection_mode varchar(20) DEFAULT 'fixed' NOT NULL,
    model_selection_criteria text,

    -- Translation service
    translator varchar(50) DEFAULT '' NOT NULL,
    system_prompt mediumtext,

    -- Model parameters
    temperature decimal(3,2) DEFAULT '0.70' NOT NULL,
    max_tokens int(11) DEFAULT '1000' NOT NULL,
    top_p decimal(3,2) DEFAULT '1.00' NOT NULL,
    frequency_penalty decimal(3,2) DEFAULT '0.00' NOT NULL,
    presence_penalty decimal(3,2) DEFAULT '0.00' NOT NULL,
    timeout int(11) DEFAULT '0' NOT NULL,
    options text,

    -- Usage limits
    max_requests_per_day int(11) DEFAULT '0' NOT NULL,
    max_tokens_per_day int(11) DEFAULT '0' NOT NULL,
    max_cost_per_day decimal(10,2) DEFAULT '0.00' NOT NULL,

    -- Fallback chain (JSON list of configuration identifiers to try on retryable failures)
    fallback_chain text,

    -- SHA-256 checksum of the configuration preset this record was imported from ('' = not preset-imported)
    preset_checksum varchar(64) DEFAULT '' NOT NULL,

    -- Status
    is_active tinyint(1) DEFAULT '1' NOT NULL,
    is_default tinyint(1) DEFAULT '0' NOT NULL,

    -- Access control (MM relation to be_groups)
    allowed_groups int(11) DEFAULT '0' NOT NULL,

    -- Comma list of permitted tool groups (empty = all groups allowed)
    allowed_tool_groups varchar(255) DEFAULT '' NOT NULL,
    allowed_guardrails varchar(255) DEFAULT '' NOT NULL,

    -- Attached skills (MM relation to tx_nrllm_skill)
    skills int(11) DEFAULT '0' NOT NULL,

    -- Standard TYPO3 fields
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
    hidden tinyint(4) unsigned DEFAULT '0' NOT NULL,
    sorting int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY model_uid (model_uid),
    KEY identifier (identifier)
);

#
# Table structure for table 'tx_nrllm_user_budget'
# Per-backend-user spending and request limits.
#
CREATE TABLE tx_nrllm_user_budget (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    -- Backend user reference
    be_user int(11) unsigned DEFAULT '0' NOT NULL,

    -- Daily limits (0 = unlimited)
    max_requests_per_day int(11) unsigned DEFAULT '0' NOT NULL,
    max_tokens_per_day int(11) unsigned DEFAULT '0' NOT NULL,
    max_cost_per_day decimal(10,4) DEFAULT '0.0000' NOT NULL,

    -- Monthly limits (0 = unlimited)
    max_requests_per_month int(11) unsigned DEFAULT '0' NOT NULL,
    max_tokens_per_month int(11) unsigned DEFAULT '0' NOT NULL,
    max_cost_per_month decimal(10,4) DEFAULT '0.0000' NOT NULL,

    -- Status
    is_active tinyint(1) DEFAULT '1' NOT NULL,

    -- Standard TYPO3 fields
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
    hidden tinyint(4) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    -- No DB UNIQUE on be_user: a soft-deleted row keeps its be_user value in
    -- the index and would block re-creating a budget for that user. One budget
    -- per user is enforced in application logic (repository getFirst()).
    KEY be_user (be_user)
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
# Table structure for table 'tx_nrllm_task'
# One-shot prompt tasks for common operations (demonstration/utility)
#
CREATE TABLE tx_nrllm_task (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    -- Identity
    identifier varchar(100) DEFAULT '' NOT NULL,
    name varchar(255) DEFAULT '' NOT NULL,
    description text,

    -- Category for grouping
    category varchar(50) DEFAULT 'general' NOT NULL,

    -- Configuration relation
    configuration_uid int(11) unsigned DEFAULT '0' NOT NULL,

    -- Prompt template (with {{input}} placeholder)
    prompt_template mediumtext,

    -- Input configuration
    input_type varchar(50) DEFAULT 'manual' NOT NULL,
    input_source text,

    -- Output configuration
    output_format varchar(20) DEFAULT 'markdown' NOT NULL,

    -- Attached skills (MM relation to tx_nrllm_skill)
    skills int(11) DEFAULT '0' NOT NULL,

    -- Status
    is_active tinyint(1) DEFAULT '1' NOT NULL,
    is_system tinyint(1) DEFAULT '0' NOT NULL,
    sorting int(11) unsigned DEFAULT '0' NOT NULL,

    -- Standard TYPO3 fields
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
    hidden tinyint(4) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY configuration_uid (configuration_uid),
    -- No DB UNIQUE on identifier: a soft-deleted row keeps its identifier in
    -- the index and would block recreating/re-seeding a task with the same
    -- identifier. Uniqueness is enforced (delete-aware) via TCA eval=unique.
    KEY identifier (identifier),
    KEY category (category)
);

#
# Table structure for table 'tx_nrllm_promptsnippet'
# Tagged prompt fragments (personas, tones of voice, audiences, styles, layouts)
#
CREATE TABLE tx_nrllm_promptsnippet (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    -- Identity
    identifier varchar(100) DEFAULT '' NOT NULL,
    name varchar(255) DEFAULT '' NOT NULL,
    description text,

    -- Tagging (comma-separated free-form tags)
    tags varchar(255) DEFAULT '' NOT NULL,

    -- Fragment content
    snippet text,

    -- Additional metadata (JSON object, e.g. {"voice":"nova"})
    metadata text,

    -- Status
    is_active tinyint(1) DEFAULT '1' NOT NULL,
    sorting int(11) unsigned DEFAULT '0' NOT NULL,

    -- Standard TYPO3 fields
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
    hidden tinyint(4) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY identifier (identifier),
    KEY active_sorted (is_active, sorting, name)
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

    -- Model dimension (usage analytics dashboard)
    model_uid int(11) unsigned DEFAULT '0' NOT NULL,
    model_id varchar(150) DEFAULT '' NOT NULL,

    -- Task dimension (per-task usage tracking)
    task_uid int(11) unsigned DEFAULT '0' NOT NULL,

    -- User context
    be_user int(11) unsigned DEFAULT '0' NOT NULL,

    -- Usage metrics
    request_count int(11) unsigned DEFAULT '0' NOT NULL,
    tokens_used int(11) unsigned DEFAULT '0' NOT NULL,
    prompt_tokens int(11) unsigned DEFAULT '0' NOT NULL,
    completion_tokens int(11) unsigned DEFAULT '0' NOT NULL,
    characters_used int(11) unsigned DEFAULT '0' NOT NULL,
    audio_seconds_used int(11) unsigned DEFAULT '0' NOT NULL,
    images_generated int(11) unsigned DEFAULT '0' NOT NULL,

    -- Cost tracking. Width aligned with the budget-ceiling columns
    -- (max_cost_per_day/month) so an aggregated daily cost cannot overflow the
    -- column before it can be compared against a configured budget ceiling.
    estimated_cost decimal(14,6) DEFAULT '0.000000' NOT NULL,

    -- Time tracking
    request_date int(11) unsigned DEFAULT '0' NOT NULL,

    -- Standard TYPO3 fields
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY lookup (service_type, service_provider, request_date, model_uid, model_id),
    KEY user_lookup (be_user, service_type, request_date),
    KEY config_lookup (configuration_uid, request_date),
    KEY model_lookup (model_uid, request_date),
    KEY task_lookup (task_uid, request_date),
    -- Leading request_date index for the analytics/dashboard read paths that
    -- filter by a date-range window only (no leading dimension) — the other
    -- composite indexes cannot serve those range scans.
    KEY request_date (request_date)
);

#
# Table structure for table 'tx_nrllm_skill_source'
# GitHub-hosted skill sources (single SKILL.md, repo, or marketplace index)
#
CREATE TABLE tx_nrllm_skill_source (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    -- Identity
    title varchar(255) DEFAULT '' NOT NULL,
    type varchar(20) DEFAULT 'single_file' NOT NULL,

    -- Source location
    url varchar(2048) DEFAULT '' NOT NULL,
    ref varchar(255) DEFAULT '' NOT NULL,
    pinned_sha varchar(64) DEFAULT '' NOT NULL,

    -- Credentials (nr-vault UUID, never plaintext)
    github_token varchar(64) DEFAULT '' NOT NULL,

    -- Publisher trust + manifest fingerprint (ADR-061)
    trust_level varchar(20) DEFAULT 'untrusted' NOT NULL,
    expected_fingerprint varchar(64) DEFAULT '' NOT NULL,

    -- Sync state
    sync_status varchar(20) DEFAULT 'never_synced' NOT NULL,
    sync_error text,
    last_synced int(11) unsigned DEFAULT '0' NOT NULL,

    -- Status
    enabled tinyint(1) DEFAULT '1' NOT NULL,

    -- Standard TYPO3 fields
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
    hidden tinyint(4) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY type (type)
);

#
# Table structure for table 'tx_nrllm_skill'
# Parsed SKILL.md records materialized from a skill source
#
CREATE TABLE tx_nrllm_skill (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    -- Source relation
    source int(11) unsigned DEFAULT '0' NOT NULL,

    -- Identity
    identifier varchar(512) DEFAULT '' NOT NULL,
    name varchar(255) DEFAULT '' NOT NULL,
    description text,

    -- Content
    body mediumtext,
    body_checksum varchar(64) DEFAULT '' NOT NULL,
    source_sha varchar(64) DEFAULT '' NOT NULL,
    raw_frontmatter text,

    -- Support assessment
    support_status varchar(20) DEFAULT 'full' NOT NULL,
    unsupported_notes text,
    allowed_tools text,

    -- Isolation metadata (ADR-061): trust denormalized from the source,
    -- prompt-injection scan findings recorded at ingest.
    trust_level varchar(20) DEFAULT 'untrusted' NOT NULL,
    injection_scan text,

    -- Lifecycle
    orphaned tinyint(1) DEFAULT '0' NOT NULL,
    enabled tinyint(1) DEFAULT '0' NOT NULL,

    -- Standard TYPO3 fields
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
    hidden tinyint(4) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY source (source),
    KEY identifier (identifier(191))
);

#
# MM table for task ↔ skill relations
#
CREATE TABLE tx_nrllm_task_skill_mm (
    uid_local int(11) unsigned DEFAULT '0' NOT NULL,
    uid_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    sorting int(11) unsigned DEFAULT '0' NOT NULL,
    sorting_foreign int(11) unsigned DEFAULT '0' NOT NULL,

    KEY uid_local (uid_local),
    KEY uid_foreign (uid_foreign)
);

#
# MM table for configuration ↔ skill relations
#
CREATE TABLE tx_nrllm_configuration_skill_mm (
    uid_local int(11) unsigned DEFAULT '0' NOT NULL,
    uid_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    sorting int(11) unsigned DEFAULT '0' NOT NULL,
    sorting_foreign int(11) unsigned DEFAULT '0' NOT NULL,

    KEY uid_local (uid_local),
    KEY uid_foreign (uid_foreign)
);

#
# Table structure for table 'tx_nrllm_tool_state'
# Global per-tool enable/disable overrides for the agent tool runtime.
# Managed via the Tool Playground module toggles (no FormEngine UI / no TCA);
# a missing row means "use the tool's isEnabledByDefault()".
#
CREATE TABLE tx_nrllm_tool_state (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    tool_name varchar(190) DEFAULT '' NOT NULL,
    enabled smallint(5) unsigned DEFAULT '1' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY tool_name (tool_name)
);

#
# Table structure for table 'tx_nrllm_tool_group_state'
# Global per-GROUP enable/disable overrides for the agent tool runtime.
# Managed via the Tools module group toggles (no FormEngine UI / no TCA);
# a missing row means "group enabled". A disabled group also covers tools
# of that group installed later — the runtime gate is fail-closed.
#
CREATE TABLE tx_nrllm_tool_group_state (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    group_name varchar(190) DEFAULT '' NOT NULL,
    enabled smallint(5) unsigned DEFAULT '1' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY group_name (group_name)
);

#
# Table structure for table 'tx_nrllm_telemetry'
# One row per provider middleware pipeline run (ADR-058). Unlike
# tx_nrllm_service_usage (a daily cost AGGREGATE) this is a per-request log:
# every run appends exactly one immutable row, success or failure. It stores
# only cross-cutting observability fields — NO prompts, NO responses, and the
# exception FQCN (error_class) rather than the message, because messages can
# carry payload fragments. Growth is bounded by the nrllm:telemetry:purge
# command. No TCA / backend UI (reads happen via SQL / analytics), matching
# the other UI-less log tables in this extension.
#
CREATE TABLE tx_nrllm_telemetry (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,

    -- Trace correlation (UUID v4, RFC 4122 = 36 chars)
    correlation_id varchar(36) DEFAULT '' NOT NULL,

    -- What ran: operation kind + the requested primary configuration
    operation varchar(32) DEFAULT '' NOT NULL,
    provider varchar(64) DEFAULT '' NOT NULL,
    model varchar(128) DEFAULT '' NOT NULL,
    configuration_identifier varchar(150) DEFAULT '' NOT NULL,

    -- Attribution (backend user; 0 for CLI / scheduler / unauthenticated)
    be_user int(11) unsigned DEFAULT '0' NOT NULL,

    -- Outcome. error_class is the exception FQCN on failure ('' on success);
    -- the message is deliberately NOT stored (privacy).
    success smallint(5) unsigned DEFAULT '0' NOT NULL,
    error_class varchar(255) DEFAULT '' NOT NULL,

    -- Wall-clock latency around the whole pipeline (includes cache lookup)
    latency_ms int(11) unsigned DEFAULT '0' NOT NULL,

    -- Pipeline signals collected on the way out
    cache_hit smallint(5) unsigned DEFAULT '0' NOT NULL,
    fallback_attempts smallint(5) unsigned DEFAULT '0' NOT NULL,

    -- Time-to-first-token for streamed runs (ADR-062). NULL for every
    -- non-streaming run — there is no partial-response milestone to measure —
    -- which is deliberately distinct from a real 0 ms first token.
    time_to_first_token_ms int(11) unsigned DEFAULT NULL,

    -- Standard TYPO3 field (append-only; no tstamp — rows are never updated)
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    -- Fetch every hop of one traced call.
    KEY correlation (correlation_id),
    -- Purge and time-range analytics scan by crdate only.
    KEY crdate (crdate),
    -- Failure-rate / latency breakdowns filter by operation over a window.
    KEY operation_lookup (operation, crdate),
    -- "which provider fails most" breakdowns.
    KEY provider_lookup (provider, success, crdate)
);

#
# Table structure for table 'tx_nrllm_skill_audit'
# Append-only provenance trail for skill ingest/enable/disable (ADR-061).
# Written only by SkillAuditRepository::record() (INSERT); the application has
# no update/delete path. No TCA (UI-less log, like tx_nrllm_tool_state), no
# soft-delete column — crdate is the immutable event time.
#
CREATE TABLE tx_nrllm_skill_audit (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    event varchar(40) DEFAULT '' NOT NULL,
    source_uid int(11) unsigned DEFAULT '0' NOT NULL,
    skill_identifier varchar(512) DEFAULT '' NOT NULL,
    source_sha varchar(64) DEFAULT '' NOT NULL,
    body_checksum varchar(64) DEFAULT '' NOT NULL,
    trust_level varchar(20) DEFAULT 'untrusted' NOT NULL,
    scan_result text,
    actor_uid int(11) unsigned DEFAULT '0' NOT NULL,
    detail text,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY source_uid (source_uid),
    KEY event (event)
);

#
# Table structure for table 'tx_nrllm_eval_result'
# Quality-evaluation run results (ADR-060). One row per golden-set run
# against a model: aggregate pass rate / mean score plus a JSON snapshot of
# the per-prompt outcomes. UI-less result log (no TCA), like
# tx_nrllm_service_usage; written only by the nrllm:eval:run command and read
# for regression comparison and quality-aware routing. Grows per run;
# retention is a documented follow-up (ADR-060).
#
CREATE TABLE tx_nrllm_eval_result (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,

    -- Run identity
    set_identifier varchar(190) DEFAULT '' NOT NULL,
    model_id varchar(150) DEFAULT '' NOT NULL,
    grader varchar(50) DEFAULT '' NOT NULL,

    -- Aggregate metrics (pass_rate / mean_score normalised 0.0000-1.0000)
    prompt_count int(11) unsigned DEFAULT '0' NOT NULL,
    passed_count int(11) unsigned DEFAULT '0' NOT NULL,
    pass_rate decimal(5,4) DEFAULT '0.0000' NOT NULL,
    mean_score decimal(5,4) DEFAULT '0.0000' NOT NULL,

    -- Per-prompt outcomes snapshot (JSON) for later inspection
    details mediumtext,

    -- Time tracking
    run_date int(11) unsigned DEFAULT '0' NOT NULL,

    -- Standard TYPO3 fields
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    -- Regression read path: latest run(s) for a (set, model), newest first.
    KEY set_model (set_identifier, model_id, run_date),
    -- Quality-routing read path: latest runs per set for a model.
    KEY model_lookup (model_id, run_date)
);

#
# Persisted agent runs (ADR-081): one row per ToolLoopService run, promoted from
# the in-memory RunTrace so runs survive the request. UI-less append-and-update
# log, mirroring tx_nrllm_telemetry (no TCA, no Extbase).
#
CREATE TABLE tx_nrllm_agentrun (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,

    uuid varchar(36) DEFAULT '' NOT NULL,
    status varchar(32) DEFAULT 'queued' NOT NULL,
    configuration_uid int(11) unsigned DEFAULT '0' NOT NULL,
    configuration_identifier varchar(150) DEFAULT '' NOT NULL,
    be_user int(11) unsigned DEFAULT '0' NOT NULL,

    -- Outcome
    iterations int(11) unsigned DEFAULT '0' NOT NULL,
    truncated smallint(5) unsigned DEFAULT '0' NOT NULL,
    total_prompt_tokens int(11) unsigned DEFAULT '0' NOT NULL,
    total_completion_tokens int(11) unsigned DEFAULT '0' NOT NULL,
    total_tokens int(11) unsigned DEFAULT '0' NOT NULL,
    estimated_cost decimal(10,6) DEFAULT '0.000000' NOT NULL,
    error_class varchar(255) DEFAULT '' NOT NULL,

    -- Why the run ended (ADR-092). The status says what state the run is in,
    -- this says how it got there: an iteration cap and an exhausted budget both
    -- surface as completed-but-truncated and are otherwise indistinguishable.
    termination_reason varchar(32) DEFAULT '' NOT NULL,

    -- Resumable state (ADR-084): serialised transcript + pending tool calls while
    -- status = waiting_for_approval; empty otherwise.
    suspended_state mediumtext,

    -- Queued execution (ADR-102): the serialised AgentRunRequest while
    -- status = queued, so a worker in another process can rehydrate and run it.
    -- Cleared by the guarded terminal settle, like suspended_state.
    queued_request mediumtext,

    -- Worker lease (ADR-102): who claimed the queued run and until when the
    -- claim is presumed live. ''/0 = not claimed — the fail-safe default, so an
    -- un-migrated row can never look leased. Written by the atomic
    -- QUEUED -> RUNNING claim; the stale-run reaper epic reads lease_expires.
    claimed_by varchar(64) DEFAULT '' NOT NULL,
    lease_expires int(11) unsigned DEFAULT '0' NOT NULL,

    -- Requeue budget (ADR-104): how many times this run was requeued for a
    -- retryable failure or a stale-lease reclaim. Capped so a deterministically
    -- failing run cannot loop forever; 0 = never requeued.
    requeue_count int(11) unsigned DEFAULT '0' NOT NULL,

    -- Time tracking
    started_at int(11) unsigned DEFAULT '0' NOT NULL,
    finished_at int(11) unsigned DEFAULT '0' NOT NULL,

    -- Standard TYPO3 fields
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY run_uuid (uuid),
    KEY be_user (be_user, crdate),
    KEY status_lookup (status, crdate),
    KEY crdate (crdate)
);

#
# Agent-run event stream (ADR-081): the durable, replayable form of each
# RunStep, one row per step, ordered by sequence within a run.
#
CREATE TABLE tx_nrllm_agentrun_event (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,

    run int(11) unsigned DEFAULT '0' NOT NULL,
    sequence int(11) unsigned DEFAULT '0' NOT NULL,
    kind varchar(32) DEFAULT '' NOT NULL,
    round int(11) unsigned DEFAULT '0' NOT NULL,
    duration_ms decimal(10,2) DEFAULT '0.00' NOT NULL,
    payload mediumtext,

    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    -- Replay read path: a run's events in order.
    KEY run_sequence (run, sequence),
    KEY crdate (crdate)
);

#
# Conversation sessions (ADR-083): one row per multi-turn conversation, so a
# stateful assistant can resume. UI-less append-and-update log (no TCA, no
# Extbase), mirroring tx_nrllm_telemetry.
#
CREATE TABLE tx_nrllm_ai_session (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,

    uuid varchar(36) DEFAULT '' NOT NULL,
    be_user int(11) unsigned DEFAULT '0' NOT NULL,
    configuration_identifier varchar(150) DEFAULT '' NOT NULL,
    title varchar(255) DEFAULT '' NOT NULL,
    message_count int(11) unsigned DEFAULT '0' NOT NULL,

    -- Retention is by inactivity: the purge deletes sessions whose last_activity
    -- predates the window.
    last_activity int(11) unsigned DEFAULT '0' NOT NULL,

    -- Standard TYPO3 fields
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    UNIQUE KEY session_uuid (uuid),
    KEY be_user (be_user, last_activity),
    KEY inactivity (last_activity)
);

#
# Conversation session messages (ADR-083): one row per turn, ordered by sequence
# within a session. `content` carries the prompt/reply text.
#
CREATE TABLE tx_nrllm_ai_session_message (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,

    session int(11) unsigned DEFAULT '0' NOT NULL,
    sequence int(11) unsigned DEFAULT '0' NOT NULL,
    role varchar(32) DEFAULT '' NOT NULL,
    content mediumtext,
    model varchar(128) DEFAULT '' NOT NULL,
    prompt_tokens int(11) unsigned DEFAULT '0' NOT NULL,
    completion_tokens int(11) unsigned DEFAULT '0' NOT NULL,
    total_tokens int(11) unsigned DEFAULT '0' NOT NULL,

    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    -- Replay read path: a session's turns in order.
    -- Unique, not merely indexed: it is the authority that decides which of two
    -- concurrent turns owns a sequence (ADR-083).
    UNIQUE KEY session_sequence (session, sequence)
);
