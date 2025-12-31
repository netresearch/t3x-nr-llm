-- Seed data for LLM Tasks (One-Shot Prompts)
-- Simple demonstration tasks showing LLM capabilities
-- Run with: ddev seed-tasks
--
-- IMPORTANT: These are one-shot prompts, NOT AI agents.
-- They cannot perform multi-step reasoning, use tools, or maintain context.

-- =====================================================================
-- LOG ANALYSIS TASKS
-- =====================================================================

-- Task: Analyze System Log Errors
INSERT INTO tx_nrllm_task (
    pid, identifier, name, description, category, configuration_uid,
    prompt_template, input_type, input_source, output_format,
    is_active, is_system, sorting, tstamp, crdate
) VALUES (
    0,
    'analyze-syslog',
    'Analyze System Log Errors',
    'Reviews TYPO3 sys_log entries and identifies patterns, critical errors, and potential issues.',
    'log_analysis',
    0,
    'You are a TYPO3 system administrator assistant. Analyze the following system log entries and provide:

1. **Summary**: Brief overview of the log content
2. **Critical Issues**: Any errors that need immediate attention
3. **Patterns**: Recurring problems or suspicious activity
4. **Recommendations**: Suggested actions to resolve issues

Focus on actionable insights. Be concise but thorough.

Log entries:
```
{{input}}
```',
    'syslog',
    '{"limit": 100, "error_only": true}',
    'markdown',
    1,
    1,
    10,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    prompt_template = VALUES(prompt_template),
    tstamp = UNIX_TIMESTAMP();

-- Task: Analyze Deprecation Log
INSERT INTO tx_nrllm_task (
    pid, identifier, name, description, category, configuration_uid,
    prompt_template, input_type, input_source, output_format,
    is_active, is_system, sorting, tstamp, crdate
) VALUES (
    0,
    'analyze-deprecation-log',
    'Analyze Deprecation Log',
    'Reviews TYPO3 deprecation log and suggests upgrade paths for deprecated code.',
    'log_analysis',
    0,
    'You are a TYPO3 upgrade specialist. Analyze the following deprecation log entries and provide:

1. **Summary**: Overview of deprecated functionality being used
2. **Priority Items**: Deprecations that will break in the next major version
3. **Migration Guide**: For each unique deprecation, suggest the modern replacement
4. **Effort Estimate**: Low/Medium/High for addressing each deprecation

Group similar deprecations together. Focus on what needs to be fixed before the next TYPO3 upgrade.

Deprecation log:
```
{{input}}
```',
    'deprecation_log',
    '',
    'markdown',
    1,
    1,
    20,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    prompt_template = VALUES(prompt_template),
    tstamp = UNIX_TIMESTAMP();

-- Task: Security Event Analysis
INSERT INTO tx_nrllm_task (
    pid, identifier, name, description, category, configuration_uid,
    prompt_template, input_type, input_source, output_format,
    is_active, is_system, sorting, tstamp, crdate
) VALUES (
    0,
    'security-analysis',
    'Security Event Analysis',
    'Analyzes system logs for potential security issues like failed logins or suspicious activity.',
    'log_analysis',
    0,
    'You are a security analyst reviewing TYPO3 system logs. Analyze for:

1. **Failed Login Attempts**: Look for brute force patterns or credential stuffing
2. **Unauthorized Access**: Attempts to access restricted areas
3. **Suspicious Patterns**: Unusual timing, IP addresses, or user agents
4. **Recommendations**: Security hardening suggestions

Rate the overall security risk: LOW / MEDIUM / HIGH / CRITICAL

Log entries:
```
{{input}}
```',
    'syslog',
    '{"limit": 200, "error_only": false}',
    'markdown',
    1,
    1,
    30,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    prompt_template = VALUES(prompt_template),
    tstamp = UNIX_TIMESTAMP();

-- =====================================================================
-- CONTENT OPERATIONS TASKS
-- =====================================================================

-- Task: Generate Alt Text
INSERT INTO tx_nrllm_task (
    pid, identifier, name, description, category, configuration_uid,
    prompt_template, input_type, input_source, output_format,
    is_active, is_system, sorting, tstamp, crdate
) VALUES (
    0,
    'generate-alt-text',
    'Generate Image Alt Text',
    'Generates accessible alt text descriptions for images based on context or filename.',
    'content',
    0,
    'Generate concise, accessible alt text for images. The alt text should:

1. Be descriptive but brief (under 125 characters if possible)
2. Describe the image content, not just "image of..."
3. Include relevant context for the webpage
4. Be useful for screen reader users

For each image or description provided, generate an appropriate alt text.

Input (image descriptions, filenames, or context):
{{input}}

Provide the alt text in a format that can be directly used in HTML alt attributes.',
    'manual',
    '',
    'plain',
    1,
    1,
    100,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    prompt_template = VALUES(prompt_template),
    tstamp = UNIX_TIMESTAMP();

-- Task: Generate Meta Descriptions
INSERT INTO tx_nrllm_task (
    pid, identifier, name, description, category, configuration_uid,
    prompt_template, input_type, input_source, output_format,
    is_active, is_system, sorting, tstamp, crdate
) VALUES (
    0,
    'generate-meta-description',
    'Generate Meta Descriptions',
    'Creates SEO-optimized meta descriptions for web pages based on content.',
    'content',
    0,
    'Generate an SEO-optimized meta description for a web page. Requirements:

1. Length: 150-160 characters (optimal for search results)
2. Include relevant keywords naturally
3. Be compelling and encourage clicks
4. Accurately summarize the page content
5. Include a call-to-action if appropriate

Page content or summary:
{{input}}

Provide ONLY the meta description text, ready to use in a <meta name="description"> tag.',
    'manual',
    '',
    'plain',
    1,
    1,
    110,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    prompt_template = VALUES(prompt_template),
    tstamp = UNIX_TIMESTAMP();

-- Task: Summarize Page Content
INSERT INTO tx_nrllm_task (
    pid, identifier, name, description, category, configuration_uid,
    prompt_template, input_type, input_source, output_format,
    is_active, is_system, sorting, tstamp, crdate
) VALUES (
    0,
    'summarize-content',
    'Summarize Page Content',
    'Creates a concise summary of page content for internal use or teasers.',
    'content',
    0,
    'Summarize the following web page content in 2-3 sentences. The summary should:

1. Capture the main topic and key points
2. Be suitable for use as a teaser or excerpt
3. Be engaging and informative
4. Avoid redundancy

Content:
{{input}}

Provide a clear, concise summary.',
    'manual',
    '',
    'plain',
    1,
    1,
    120,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    prompt_template = VALUES(prompt_template),
    tstamp = UNIX_TIMESTAMP();

-- Task: Translate Content
INSERT INTO tx_nrllm_task (
    pid, identifier, name, description, category, configuration_uid,
    prompt_template, input_type, input_source, output_format,
    is_active, is_system, sorting, tstamp, crdate
) VALUES (
    0,
    'translate-content',
    'Translate Content',
    'Translates content between languages while maintaining tone and context.',
    'content',
    0,
    'Translate the following content. Guidelines:

1. Maintain the original tone and style
2. Adapt cultural references appropriately
3. Preserve formatting (markdown, HTML tags, etc.)
4. Keep technical terms consistent

If the target language is not specified, translate to English.
Format: "Target language: [language]" or just provide the text for English translation.

Content to translate:
{{input}}

Provide only the translated text.',
    'manual',
    '',
    'plain',
    1,
    1,
    130,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    prompt_template = VALUES(prompt_template),
    tstamp = UNIX_TIMESTAMP();

-- =====================================================================
-- SYSTEM HEALTH TASKS
-- =====================================================================

-- Task: Analyze Broken Links Report
INSERT INTO tx_nrllm_task (
    pid, identifier, name, description, category, configuration_uid,
    prompt_template, input_type, input_source, output_format,
    is_active, is_system, sorting, tstamp, crdate
) VALUES (
    0,
    'analyze-broken-links',
    'Analyze Broken Links Report',
    'Reviews a list of broken links and suggests fixes or removal strategies.',
    'system',
    0,
    'Analyze the following broken links report and provide:

1. **Priority Fixes**: Links that are most critical to fix (high-traffic pages, important resources)
2. **Pattern Analysis**: Common issues (moved domains, outdated URLs, typos)
3. **Suggested Actions**: For each category of broken links, recommend:
   - Replace with updated URL
   - Remove the link
   - Find alternative resource
   - Archive.org fallback
4. **Prevention Tips**: How to avoid similar issues

Broken links report:
{{input}}

Format the response for easy action by content editors.',
    'manual',
    '',
    'markdown',
    1,
    1,
    200,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    prompt_template = VALUES(prompt_template),
    tstamp = UNIX_TIMESTAMP();

-- Task: Review Redirect Chains
INSERT INTO tx_nrllm_task (
    pid, identifier, name, description, category, configuration_uid,
    prompt_template, input_type, input_source, output_format,
    is_active, is_system, sorting, tstamp, crdate
) VALUES (
    0,
    'review-redirects',
    'Review Redirect Chains',
    'Analyzes redirect configurations and identifies chains or loops that need optimization.',
    'system',
    0,
    'Analyze the following redirect configurations for issues:

1. **Redirect Chains**: Identify any A→B→C patterns that should be A→C
2. **Redirect Loops**: Find any circular redirects
3. **Outdated Redirects**: Redirects pointing to pages that no longer exist
4. **Performance Issues**: Redirects that add unnecessary latency
5. **Optimization Suggestions**: Consolidated redirect rules

Redirect data:
{{input}}

Provide specific recommendations for each issue found.',
    'manual',
    '',
    'markdown',
    1,
    1,
    210,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    prompt_template = VALUES(prompt_template),
    tstamp = UNIX_TIMESTAMP();

-- =====================================================================
-- DEVELOPER ASSISTANCE TASKS
-- =====================================================================

-- Task: Explain TCA Configuration
INSERT INTO tx_nrllm_task (
    pid, identifier, name, description, category, configuration_uid,
    prompt_template, input_type, input_source, output_format,
    is_active, is_system, sorting, tstamp, crdate
) VALUES (
    0,
    'explain-tca',
    'Explain TCA Configuration',
    'Explains TYPO3 TCA (Table Configuration Array) settings in plain language.',
    'developer',
    0,
    'You are a TYPO3 expert. Explain the following TCA (Table Configuration Array) configuration in plain language.

For each significant setting, explain:
1. What it does
2. Why it might be configured this way
3. Common use cases
4. Any potential issues or improvements

If there are errors or deprecated settings, point them out.

TCA Configuration:
```php
{{input}}
```

Provide a clear explanation suitable for intermediate TYPO3 developers.',
    'manual',
    '',
    'markdown',
    1,
    1,
    300,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    prompt_template = VALUES(prompt_template),
    tstamp = UNIX_TIMESTAMP();

-- Task: Suggest TypoScript Fixes
INSERT INTO tx_nrllm_task (
    pid, identifier, name, description, category, configuration_uid,
    prompt_template, input_type, input_source, output_format,
    is_active, is_system, sorting, tstamp, crdate
) VALUES (
    0,
    'fix-typoscript',
    'Suggest TypoScript Fixes',
    'Analyzes TypoScript code and suggests fixes for common issues.',
    'developer',
    0,
    'You are a TYPO3 TypoScript expert. Analyze the following TypoScript configuration for issues:

1. **Syntax Errors**: Missing brackets, incorrect property assignments
2. **Deprecated Settings**: Settings that should be updated for modern TYPO3
3. **Performance Issues**: Inefficient configurations
4. **Best Practices**: Suggest improvements

For each issue, provide:
- The problem
- The fix
- Corrected TypoScript code

TypoScript Code:
```typoscript
{{input}}
```

Provide clear, actionable fixes.',
    'manual',
    '',
    'markdown',
    1,
    1,
    310,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    prompt_template = VALUES(prompt_template),
    tstamp = UNIX_TIMESTAMP();

-- Task: Analyze Fluid Template Issues
INSERT INTO tx_nrllm_task (
    pid, identifier, name, description, category, configuration_uid,
    prompt_template, input_type, input_source, output_format,
    is_active, is_system, sorting, tstamp, crdate
) VALUES (
    0,
    'analyze-fluid',
    'Analyze Fluid Template Issues',
    'Reviews Fluid templates for errors, accessibility issues, and best practices.',
    'developer',
    0,
    'You are a TYPO3 Fluid template expert. Analyze the following Fluid template for:

1. **Syntax Errors**: Invalid ViewHelper usage, missing closures
2. **Accessibility Issues**: Missing alt text, improper heading structure, ARIA issues
3. **Performance**: Inefficient iterations, unnecessary ViewHelper calls
4. **Best Practices**: XSS prevention, proper escaping, clean code
5. **TYPO3 Conventions**: Correct namespace usage, standard ViewHelpers

Fluid Template:
```html
{{input}}
```

Provide specific fixes with corrected code examples.',
    'manual',
    '',
    'markdown',
    1,
    1,
    320,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    prompt_template = VALUES(prompt_template),
    tstamp = UNIX_TIMESTAMP();

-- Task: Generate Extension Documentation
INSERT INTO tx_nrllm_task (
    pid, identifier, name, description, category, configuration_uid,
    prompt_template, input_type, input_source, output_format,
    is_active, is_system, sorting, tstamp, crdate
) VALUES (
    0,
    'generate-docs',
    'Generate Extension Documentation',
    'Creates documentation for TYPO3 extension classes or features.',
    'developer',
    0,
    'Generate documentation for the following TYPO3 code. Include:

1. **Purpose**: What this code does
2. **Usage**: How to use it (with examples)
3. **Parameters/Properties**: Explanation of each
4. **Return Values**: What to expect
5. **Dependencies**: Required classes or extensions
6. **Example**: Practical usage example

Code:
```php
{{input}}
```

Format the documentation in RST (reStructuredText) format suitable for TYPO3 documentation.',
    'manual',
    '',
    'markdown',
    1,
    1,
    330,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    prompt_template = VALUES(prompt_template),
    tstamp = UNIX_TIMESTAMP();

-- =====================================================================
-- Summary
-- =====================================================================

SELECT 'Task seed data imported successfully!' AS status;
SELECT CONCAT('Tasks: ', COUNT(*), ' configured') AS created
FROM tx_nrllm_task WHERE deleted = 0;
SELECT CONCAT('  - Log Analysis: ', COUNT(*), ' tasks') AS category
FROM tx_nrllm_task WHERE category = 'log_analysis' AND deleted = 0;
SELECT CONCAT('  - Content: ', COUNT(*), ' tasks') AS category
FROM tx_nrllm_task WHERE category = 'content' AND deleted = 0;
SELECT CONCAT('  - System: ', COUNT(*), ' tasks') AS category
FROM tx_nrllm_task WHERE category = 'system' AND deleted = 0;
SELECT CONCAT('  - Developer: ', COUNT(*), ' tasks') AS category
FROM tx_nrllm_task WHERE category = 'developer' AND deleted = 0;
