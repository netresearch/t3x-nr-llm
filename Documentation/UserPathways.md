# User Pathways - nr_llm Backend Modules

This document defines all user pathways for the nr_llm TYPO3 extension backend modules.
These pathways serve as the basis for comprehensive E2E test coverage.

## 1. Setup Wizard Module

### Pathway 1.1: First-Time Provider Setup
**Trigger:** User has no providers configured and needs to set up LLM integration.

1. Navigate to Admin → LLM → Setup Wizard
2. Enter API endpoint URL (e.g., `https://api.openai.com/v1`)
3. Click "Detect Provider" → System auto-detects provider type
4. Enter API key
5. Click "Test Connection" → System validates credentials
6. Click "Discover Models" → System fetches available models
7. Select models to import
8. Click "Generate Configurations" → LLM suggests optimal configs
9. Review suggested configurations
10. Click "Save" → System creates Provider, Models, and Configurations

**Expected Outcomes:**
- Provider record created with correct type and credentials
- Selected models imported with detected limits
- Default configuration created
- User redirected to dashboard

### Pathway 1.2: Add Additional Provider
**Trigger:** User wants to add a second LLM provider.

1. Navigate to Setup Wizard
2. Enter different provider endpoint
3. Complete steps 3-10 from Pathway 1.1

**Expected Outcomes:**
- New provider created without affecting existing
- Models correctly associated with new provider
- User can switch between providers

### Pathway 1.3: Model Discovery
**Trigger:** User wants to see available models from provider API.

1. Navigate to Setup Wizard
2. Enter API endpoint and credentials
3. Click "Discover Models"
4. View list of available models

**Expected Outcomes:**
- List of available models from provider API
- Each model shows name, capabilities, context length
- User can select models to import

### Pathway 1.4: Configuration Generation
**Trigger:** User wants LLM-suggested configurations.

1. Complete model discovery
2. Select models to import
3. Click "Generate Configurations"
4. Review suggested configurations

**Expected Outcomes:**
- Configurations suggested based on model capabilities
- System prompts tailored to use case
- Temperature and token settings optimized

### Pathway 1.5: Test Connection
**Trigger:** User wants to verify API credentials before saving.

1. Enter API endpoint and key
2. Click "Test Connection"
3. View connection result

**Expected Outcomes:**
- Success: Confirmation message shown
- Failure: Error message with details
- Graceful handling of network errors

### Pathway 1.6: Setup Wizard Input Validation
**Trigger:** User enters various edge case inputs in setup wizard.

1. Enter special characters in endpoint URL
2. Enter unicode characters in provider name
3. Enter long API keys
4. Test with various adapter types
5. Test configuration parameter boundaries

**Expected Outcomes:**
- Special characters handled gracefully
- Unicode preserved in names
- Long inputs handled (stored or truncated)
- Unknown adapter types handled gracefully
- Temperature range (0-2) validated
- Max tokens edge cases handled

### Pathway 1.7: Provider Re-configuration
**Trigger:** User modifies an existing provider setup.

1. Access existing provider
2. Update configuration
3. Add new models to provider

**Expected Outcomes:**
- Existing provider editable
- New models addable
- Changes persist correctly

### Pathway 1.8: Wizard Validation
**Trigger:** Wizard validates user inputs at each step.

1. Validate URL format
2. Validate API key format
3. Validate model selection

**Expected Outcomes:**
- Invalid URLs detected
- API keys validated per provider type
- Model selection required

### Pathway 1.9: Multi-Provider Setup
**Trigger:** User sets up multiple providers sequentially.

1. Set up first provider
2. Set up second provider
3. Both active simultaneously

**Expected Outcomes:**
- Multiple providers created
- Each provider independent
- Different adapter types supported

### Pathway 1.10: Wizard Error Recovery
**Trigger:** User encounters and recovers from errors.

1. Experience detection failure
2. Retry with correct data
3. Experience test failure
4. Retry successfully

**Expected Outcomes:**
- Failed attempts don't corrupt state
- Retry mechanism works
- Clear error feedback provided

### Pathway 1.11: Provider Detection Edge Cases
**Trigger:** User enters various endpoint URL formats.

1. Enter localhost URL
2. Enter URL with non-standard port
3. Enter URL with path segments

**Expected Outcomes:**
- Localhost URLs detected correctly
- Custom ports handled properly
- Path segments preserved in endpoint
- Provider type inferred from URL patterns

### Pathway 1.12: Model Selection Variations
**Trigger:** User selects different model combinations.

1. Select single model from provider
2. Select all available models
3. Select mixed combination of models

**Expected Outcomes:**
- Single model imports correctly
- Multiple models all imported
- Mixed selection works
- Each model linked to provider

### Pathway 1.13: Configuration Template Variations
**Trigger:** User creates configurations with various templates.

1. Create multiple configurations at once
2. Create configuration with custom system prompt
3. Create configuration with default flag

**Expected Outcomes:**
- Multiple configurations created independently
- System prompts preserved correctly
- Default flag sets default configuration
- All configurations active and usable

### Pathway 1.14: API Key Format Variations
**Trigger:** User enters API keys in different formats.

1. Enter standard API key format
2. Enter API key with special characters

**Expected Outcomes:**
- Standard keys stored correctly
- Special characters preserved
- Keys encrypted in database
- Provider functional with stored key

### Pathway 1.15: Wizard Session State
**Trigger:** User navigates through wizard maintaining session context.

1. Test connection with credentials
2. Discover models using same credentials
3. Save configuration completing workflow

**Expected Outcomes:**
- Session context maintained across steps
- Backtracking allowed (test different endpoints)
- Partial progress doesn't corrupt state

### Pathway 1.16: Wizard Input Validation
**Trigger:** User enters edge case inputs during setup.

1. Enter empty endpoint URL
2. Enter empty API key
3. Enter very long endpoint URL
4. Enter invalid adapter type

**Expected Outcomes:**
- Empty inputs handled gracefully
- Long inputs processed without errors
- Invalid adapter types return structured error
- All responses valid JSON

### Pathway 1.17: Model Discovery Edge Cases
**Trigger:** User attempts model discovery with various credentials.

1. Discover with invalid credentials
2. Discover with unreachable endpoint
3. Discover with empty model list
4. Discover multiple times

**Expected Outcomes:**
- Invalid credentials return error response
- Unreachable endpoint returns structured error
- Empty model list handled
- Multiple discoveries don't corrupt state

### Pathway 1.18: Wizard Save Edge Cases
**Trigger:** User attempts to save with edge case data.

1. Save with empty models array
2. Save with no selected models
3. Save with duplicate provider name
4. Save with very long configuration name
5. Save with Unicode names
6. Save with extreme temperature values
7. Save with zero max tokens

**Expected Outcomes:**
- Empty/unselected models handled gracefully
- Duplicate names either create or return error
- Long names truncated or stored
- Unicode characters preserved
- Extreme values validated

---

## 2. Provider Management Module

### Pathway 2.1: View Provider List
**Trigger:** User wants to see all configured providers.

1. Navigate to Admin → LLM → Providers
2. View list of providers with status indicators

**Expected Outcomes:**
- All providers displayed with name, type, status
- Active/inactive indicators visible
- Action buttons available

### Pathway 2.2: Toggle Provider Status
**Trigger:** User wants to temporarily disable a provider.

1. Navigate to Provider list
2. Click toggle button for a provider
3. Confirm status change

**Expected Outcomes:**
- Provider status toggles (active ↔ inactive)
- UI updates immediately
- Provider no longer available for selection when inactive

### Pathway 2.3: Test Provider Connection
**Trigger:** User wants to verify provider is working.

1. Navigate to Provider list
2. Click "Test Connection" button
3. Wait for test result

**Expected Outcomes:**
- Success: Green confirmation with model list
- Failure: Red error with specific message
- UI shows loading state during test

### Pathway 2.4: Edit Provider Configuration
**Trigger:** User needs to update API key or settings.

1. Navigate to Provider list
2. Click edit icon for provider
3. Modify fields (API key, base URL, timeout, etc.)
4. Save changes

**Expected Outcomes:**
- Changes persisted to database
- Existing models/configs remain linked
- Provider remains functional

### Pathway 2.5: Delete Provider
**Trigger:** User no longer needs a provider.

1. Navigate to Provider list
2. Click delete icon
3. Confirm deletion

**Expected Outcomes:**
- Provider deleted
- Associated models orphaned or deleted (configurable)
- Configurations using this provider marked invalid

### Pathway 2.6: Test Connection Edge Cases
**Trigger:** User tests connection with invalid or missing data.

1. Attempt test with missing/invalid UID
2. View error response

**Expected Outcomes:**
- Clear error message for missing UID
- Proper validation of request parameters
- No system crash on invalid input

### Pathway 2.7: Provider Data Validation
**Trigger:** System validates provider data on save.

1. Create/edit provider
2. System validates required fields
3. View validation results

**Expected Outcomes:**
- Identifier uniqueness enforced
- Required fields validated
- Clear error messages for violations

### Pathway 2.8: Provider-Model Cascade
**Trigger:** User deactivates provider with associated models.

1. Deactivate a provider
2. Check associated models

**Expected Outcomes:**
- Models remain in database (not deleted)
- Models retain provider association
- Can be reactivated together

### Pathway 2.9: Provider Types
**Trigger:** User works with different provider types.

1. View providers by type
2. Filter by adapter type

**Expected Outcomes:**
- All standard types supported (openai, anthropic, ollama, google, gemini, deepseek)
- Filtering returns correct providers
- Type-specific features available

### Pathway 2.10: Provider Endpoint Configuration
**Trigger:** User configures custom endpoints for providers.

1. Edit provider settings
2. Set custom endpoint URL
3. Configure timeout settings

**Expected Outcomes:**
- Custom endpoint URLs stored correctly
- Timeout settings applied
- Provider uses custom configuration

### Pathway 2.11: Provider Priority Management
**Trigger:** User manages provider priority order.

1. View provider list
2. Adjust priority values
3. Verify ordering

**Expected Outcomes:**
- Priority values can be changed
- findHighestPriority returns correct provider
- Fallback behavior respects priority

### Pathway 2.12: Provider Listing Filters
**Trigger:** User filters provider list.

1. Filter by active status
2. Filter by adapter type
3. Search by identifier

**Expected Outcomes:**
- Active filter shows only active providers
- Type filter shows matching providers
- Identifier search finds exact match

### Pathway 2.13: Provider Configuration Validation
**Trigger:** User creates providers with various configurations.

1. Create minimal provider (no API key)
2. Create fully configured provider
3. Test connection behavior

**Expected Outcomes:**
- Minimal providers can be created
- Full configuration is preserved
- Missing API key handled gracefully

### Pathway 2.14: Provider Search and Discovery
**Trigger:** User searches for providers by various criteria.

1. Find provider by identifier
2. Find provider by adapter type
3. Count providers

**Expected Outcomes:**
- Identifier search finds exact match
- Type filter returns matching providers
- Counts are accurate

### Pathway 2.15: Provider Lifecycle
**Trigger:** User manages complete provider lifecycle.

1. Create new provider
2. Configure with models
3. Deactivate provider
4. Delete provider

**Expected Outcomes:**
- Full lifecycle supported
- Provider with models works correctly
- Deactivation/deletion handled

### Pathway 2.16: Provider API Key Management
**Trigger:** User manages provider API keys.

1. Store API key
2. Verify key is encrypted
3. Provider without API key

**Expected Outcomes:**
- API keys stored securely (encrypted)
- Keys not exposed in plain text
- Missing key handled gracefully

### Pathway 2.17: Provider Timeout Configuration
**Trigger:** User configures provider timeouts.

1. Set custom timeout value
2. Use default timeout
3. Verify timeout applies

**Expected Outcomes:**
- Custom timeouts stored correctly
- Default timeouts work
- Settings apply to API calls

### Pathway 2.18: Provider Name Variations
**Trigger:** User creates providers with various name formats.

1. Create provider with long name
2. Create provider with unicode name
3. Create provider with special characters

**Expected Outcomes:**
- Long names stored correctly
- Unicode characters preserved
- Special characters handled

### Pathway 2.19: Provider AJAX Response Structure
**Trigger:** User interacts with provider AJAX endpoints.

1. Toggle provider status
2. Test connection
3. Handle errors

**Expected Outcomes:**
- Toggle returns success/isActive structure
- Test returns success/message/models structure
- Errors return success=false/error structure
- All responses valid JSON

### Pathway 2.20: Provider Endpoint URL Variations
**Trigger:** User configures various endpoint URL formats.

1. Configure HTTPS endpoint
2. Configure localhost endpoint
3. Configure IP address endpoint
4. Configure endpoint with path
5. Configure empty endpoint

**Expected Outcomes:**
- HTTPS URLs stored correctly
- Localhost URLs work
- IP addresses supported
- Path segments preserved
- Empty endpoints allowed

---

## 3. Model Management Module

### Pathway 3.1: View Model List
**Trigger:** User wants to see all available models.

1. Navigate to Admin → LLM → Models
2. View complete list of models

**Expected Outcomes:**
- All models displayed with provider association
- Status, capabilities, and limits visible
- Filter by provider available

### Pathway 3.2: Filter Models by Provider
**Trigger:** User wants to see models for specific provider.

1. Navigate to Model list
2. Select provider from filter dropdown
3. View filtered results

**Expected Outcomes:**
- Only models for selected provider shown
- Filter state preserved during session
- "All Providers" option available

### Pathway 3.3: Toggle Model Status
**Trigger:** User wants to enable/disable specific model.

1. Navigate to Model list
2. Click toggle button for model
3. Confirm status change

**Expected Outcomes:**
- Model status toggles
- Inactive models excluded from selection dropdowns
- Configurations using model show warning if disabled

### Pathway 3.4: Set Default Model
**Trigger:** User wants to designate primary model for provider.

1. Navigate to Model list
2. Click "Set Default" for desired model
3. Confirm action

**Expected Outcomes:**
- Selected model marked as default
- Previous default cleared
- Provider uses this model when none specified

### Pathway 3.5: Test Model
**Trigger:** User wants to verify model works.

1. Navigate to Model list
2. Click "Test" button for model
3. Wait for completion

**Expected Outcomes:**
- Success: Shows response and token usage
- Failure: Shows error message
- Loading state during test

### Pathway 3.6: Fetch Available Models
**Trigger:** User wants to discover new models from provider API.

1. Navigate to Model list
2. Click "Fetch Available" for provider
3. Review discovered models
4. Select models to import

**Expected Outcomes:**
- List of available models shown
- Already-imported models marked
- New models can be imported with one click

### Pathway 3.7: Detect Model Limits
**Trigger:** User wants to auto-populate model capabilities.

1. Navigate to Model list
2. Click "Detect Limits" for model
3. Wait for detection

**Expected Outcomes:**
- Context length updated
- Max output tokens updated
- Capabilities (vision, tools, etc.) updated
- Model record saved

### Pathway 3.8: Edit Model Configuration
**Trigger:** User needs to manually adjust model settings.

1. Navigate to Model list
2. Click edit icon
3. Modify fields (identifier, limits, capabilities)
4. Save changes

**Expected Outcomes:**
- Changes persisted
- Validation prevents invalid values
- Model immediately usable

### Pathway 3.9: Get Models by Provider (AJAX)
**Trigger:** User interface needs to populate a model dropdown for a selected provider.

1. User selects a provider in a dropdown
2. System makes AJAX request to get models for that provider
3. Model dropdown is populated with results

**Expected Outcomes:**
- Returns list of active models for provider
- Each model includes uid, name, modelId
- Empty array if provider has no models
- Error response if provider UID missing

### Pathway 3.10: Toggle Model Edge Cases
**Trigger:** User attempts toggle with invalid data.

1. Attempt toggle with missing/invalid UID
2. View validation response

**Expected Outcomes:**
- Missing UID: "No model UID specified"
- Zero UID: "No model UID specified"
- Non-existent: "Model not found"
- Proper validation enforced

### Pathway 3.11: Set Default Model Edge Cases
**Trigger:** User attempts set default with invalid data.

1. Attempt set default with missing/invalid UID
2. View validation response

**Expected Outcomes:**
- Missing UID: "No model UID specified"
- Non-existent: "Model not found"
- Proper validation enforced

### Pathway 3.12: Test Model Edge Cases
**Trigger:** User attempts test with invalid data.

1. Attempt test with missing/invalid UID
2. View validation response

**Expected Outcomes:**
- Missing UID: "No model UID specified"
- Non-existent: "Model not found"
- Clear error messages

### Pathway 3.13: Fetch Available Models Edge Cases
**Trigger:** User attempts fetch with invalid provider.

1. Attempt fetch with missing/invalid provider
2. View validation response

**Expected Outcomes:**
- Missing provider: "No provider UID specified"
- Non-existent: "Provider not found"
- Graceful handling of API errors

### Pathway 3.14: Detect Limits Edge Cases
**Trigger:** User attempts detect limits with invalid data.

1. Attempt detect limits with missing provider/model
2. View validation response

**Expected Outcomes:**
- Missing provider: "No provider UID specified"
- Missing model: "No model ID specified"
- Non-existent provider: "Provider not found"
- Clear error messages

### Pathway 3.15: Model Context and Token Limits
**Trigger:** User configures model token limits.

1. Set context length
2. Set max output tokens
3. Configure custom limits

**Expected Outcomes:**
- Context length stored correctly
- Max output tokens preserved
- Custom limits applied to completions

### Pathway 3.16: Model Capabilities
**Trigger:** User manages model capabilities.

1. Set capabilities array
2. Query capabilities
3. Filter models by capability

**Expected Outcomes:**
- Capabilities stored correctly
- Capabilities queryable
- Filter returns matching models

### Pathway 3.17: Model Search and Filtering
**Trigger:** User searches and filters models.

1. Find model by identifier
2. Count active models
3. Find default model

**Expected Outcomes:**
- Identifier search finds exact match
- Active count accurate
- Default model identifiable

### Pathway 3.18: Model Lifecycle
**Trigger:** User manages complete model lifecycle.

1. Create new model
2. Activate/deactivate
3. Set as default
4. Clear default

**Expected Outcomes:**
- Full lifecycle supported
- State transitions work
- Default uniqueness maintained

### Pathway 3.19: Model Name Variations
**Trigger:** User creates models with various name formats.

1. Create model with long name
2. Create model with unicode name
3. Create model with special characters

**Expected Outcomes:**
- Long names stored correctly
- Unicode characters preserved
- Special characters handled

### Pathway 3.20: Model AJAX Response Structure
**Trigger:** User interacts with model AJAX endpoints.

1. Toggle model status
2. Set as default
3. Test model
4. Handle errors

**Expected Outcomes:**
- Toggle returns success/isActive structure
- Set default returns success structure
- Test returns success/message structure
- Errors return success=false/error structure

### Pathway 3.21: Model ID Format Variations
**Trigger:** User creates models with various ID formats.

1. Use standard model ID (gpt-4-turbo)
2. Use versioned ID (claude-3-5-sonnet-20241022)
3. Use namespaced ID (models/gemini-2.0-flash)
4. Use local ID (llama3.2:latest)

**Expected Outcomes:**
- Standard IDs stored correctly
- Version suffixes preserved
- Namespace paths preserved
- Colon separators work

### Pathway 3.22: Model Limit Edge Cases
**Trigger:** User configures models with various limit values.

1. Create model with zero context length
2. Create model with large context length
3. Create model with minimal output tokens
4. Create model with equal context and output

**Expected Outcomes:**
- Zero context handled
- Large values (2M+) supported
- Minimal values work
- Equal limits valid

---

## 4. Configuration Management Module

### Pathway 4.1: View Configuration List
**Trigger:** User wants to see all LLM configurations.

1. Navigate to Admin → LLM → Configurations
2. View list of configurations

**Expected Outcomes:**
- All configs shown with model/provider info
- Temperature, max tokens visible
- Default indicator shown

### Pathway 4.2: Toggle Configuration Status
**Trigger:** User wants to enable/disable configuration.

1. Navigate to Configuration list
2. Click toggle button
3. Confirm change

**Expected Outcomes:**
- Configuration status toggles
- Inactive configs excluded from selection
- Tasks using config show warning

### Pathway 4.3: Set Default Configuration
**Trigger:** User wants to set primary configuration.

1. Navigate to Configuration list
2. Click "Set Default" for configuration
3. Confirm action

**Expected Outcomes:**
- Configuration marked as default
- Used when no specific config requested
- Previous default cleared

### Pathway 4.4: Test Configuration
**Trigger:** User wants to verify configuration works.

1. Navigate to Configuration list
2. Click "Test" button
3. Enter optional test prompt
4. Execute test

**Expected Outcomes:**
- Completion request sent with config parameters
- Response displayed with timing
- Token usage shown

### Pathway 4.5: Create New Configuration
**Trigger:** User needs new configuration preset.

1. Navigate to Configuration list
2. Click "Create New"
3. Fill form:
   - Name/identifier
   - Select provider
   - Select model
   - Set temperature (0-2)
   - Set max tokens
   - Set system prompt
4. Save configuration

**Expected Outcomes:**
- Configuration created
- Validation enforced (temperature range, required fields)
- Immediately available for use

### Pathway 4.6: Clone Configuration
**Trigger:** User wants configuration based on existing one.

1. Navigate to Configuration list
2. Click "Clone" for existing config
3. Modify as needed
4. Save with new name

**Expected Outcomes:**
- New config created with copied values
- Original unchanged
- Both configs available

### Pathway 4.7: Configuration Without Model
**Trigger:** User tests configuration that has no model assigned.

1. Attempt to test configuration without model
2. View error response

**Expected Outcomes:**
- Clear error: "Configuration has no model assigned"
- No system crash
- User guided to fix configuration

### Pathway 4.8: Toggle Configuration Edge Cases
**Trigger:** User attempts toggle with invalid data.

1. Attempt toggle with missing/invalid UID
2. View validation response

**Expected Outcomes:**
- Missing UID: "No configuration UID specified"
- Non-existent: "Configuration not found"
- Invalid format handled gracefully

### Pathway 4.9: Set Default Edge Cases
**Trigger:** User attempts set default with invalid data.

1. Attempt set default with missing/invalid UID
2. View validation response

**Expected Outcomes:**
- Missing UID: "No configuration UID specified"
- Non-existent: "Configuration not found"
- Proper validation enforced

### Pathway 4.10: Get Models Edge Cases
**Trigger:** User requests models for invalid provider.

1. Attempt get models with missing/empty provider
2. View validation response

**Expected Outcomes:**
- Missing provider: "No provider specified"
- Invalid provider: "Provider not available"
- Clear error messages

### Pathway 4.11: Configuration System Prompt Management
**Trigger:** User configures system prompts for configurations.

1. Create configuration with system prompt
2. Create configuration with empty system prompt
3. Create configuration with unicode system prompt

**Expected Outcomes:**
- System prompt stored correctly
- Empty prompt handled (uses default behavior)
- Unicode characters preserved
- Configuration uses prompt in completions

### Pathway 4.12: Configuration Parameter Boundaries
**Trigger:** User tests configuration parameter edge cases.

1. Set temperature to 0 (deterministic)
2. Set temperature to maximum (2.0)
3. Set max tokens to minimum (1)
4. Set max tokens to large value

**Expected Outcomes:**
- Temperature 0 produces consistent output
- Temperature max produces varied output
- Min tokens limits output length
- Large token values handled by model limits

### Pathway 4.13: Configuration Listing and Filtering
**Trigger:** User lists and filters configurations.

1. Find all active configurations
2. Find default configuration
3. Search by identifier
4. Count active configurations

**Expected Outcomes:**
- Active filter shows only active configs
- Default configuration identifiable
- Identifier search finds exact match
- Counts reflect actual state

### Pathway 4.14: Configuration State Transitions
**Trigger:** User changes configuration states.

1. New configuration starts inactive
2. Deactivate default configuration
3. Reactivate configuration

**Expected Outcomes:**
- New configs default to inactive
- Deactivating default removes default flag
- Reactivation restores functionality
- State changes persist correctly

### Pathway 4.15: Configuration Identifier Management
**Trigger:** User manages configuration identifiers.

1. Verify identifier uniqueness
2. Rename configuration identifier
3. Use special characters in identifier

**Expected Outcomes:**
- Duplicate identifiers rejected
- Identifier preserved on save
- Special characters (dashes, underscores) allowed

### Pathway 4.16: Configuration Name Handling
**Trigger:** User creates configurations with various names.

1. Create configuration with long name
2. Create configuration with unicode name
3. Create configuration with HTML in name

**Expected Outcomes:**
- Long names handled (stored or truncated)
- Unicode preserved correctly
- HTML safely stored (escaped if needed)

### Pathway 4.17: Configuration Cascade Behavior
**Trigger:** Configuration relationships remain consistent.

1. Verify configuration retains model reference
2. Multiple configurations using same model
3. Update configuration preserves relationships

**Expected Outcomes:**
- Model → Provider chain intact
- Multiple configs can share model
- Updates don't break relationships

### Pathway 4.18: Configuration AJAX Response Structure
**Trigger:** AJAX endpoints return consistent responses.

1. Toggle action returns correct structure
2. Set default action returns correct structure
3. Test action returns correct structure
4. Error responses follow pattern

**Expected Outcomes:**
- Success responses have standard fields
- Error responses include error field
- JSON structure consistent

### Pathway 4.19: Configuration Count and Statistics
**Trigger:** User views configuration statistics.

1. Count all configurations
2. Count active configurations
3. Verify default count

**Expected Outcomes:**
- Total count accurate
- Active count matches filter
- At most one default exists

### Pathway 4.20: Configuration Precision Values
**Trigger:** User sets precise parameter values.

1. Temperature with many decimal places
2. Various max token values
3. Boundary value testing

**Expected Outcomes:**
- Float precision maintained
- Token values stored correctly
- Boundary values handled

---

## 5. Task Management Module

### Pathway 5.1: View Task List
**Trigger:** User wants to see available tasks.

1. Navigate to Admin → LLM → Tasks
2. View tasks grouped by category

**Expected Outcomes:**
- Tasks shown in categories
- Task descriptions visible
- Execute buttons available

### Pathway 5.2: Execute Task with Manual Input
**Trigger:** User wants to run task with custom text.

1. Navigate to Task list
2. Click "Execute" for task
3. Enter text in input field
4. Click "Run Task"
5. View results

**Expected Outcomes:**
- Task executed with input
- LLM response displayed
- Execution time and tokens shown
- Option to copy result

### Pathway 5.3: Execute Task with Database Records
**Trigger:** User wants task to process database records.

1. Navigate to Task execution form
2. Select input type: "Database Records"
3. Select table from picker
4. Browse and select records
5. Execute task
6. View results

**Expected Outcomes:**
- Table picker shows available tables
- Records searchable/filterable
- Selected records passed to task
- Results reference processed records

### Pathway 5.4: Execute Task with System Log
**Trigger:** User wants task to analyze recent logs.

1. Navigate to Task execution form
2. Select input type: "System Log"
3. Configure filters (time range, severity)
4. Preview log entries
5. Execute task

**Expected Outcomes:**
- Recent log entries fetched
- Filters applied correctly
- Task analyzes log data
- Insights/summary returned

### Pathway 5.5: Create Custom Task
**Trigger:** User needs new task type.

1. Navigate to Task list
2. Click "Create New"
3. Fill form:
   - Name and description
   - Category
   - Prompt template with variables
   - Input type
   - Output format
4. Save task

**Expected Outcomes:**
- Task created with template
- Variables extracted from template
- Task appears in list

### Pathway 5.6: Inactive Task Handling
**Trigger:** User attempts to use an inactive task.

1. Navigate to Task list
2. Deactivate a task
3. Attempt to execute the inactive task

**Expected Outcomes:**
- Error message: "Task is not active"
- Inactive tasks excluded from active task list
- Task remains in database for reactivation

### Pathway 5.7: Deprecation Log Analysis
**Trigger:** User wants to analyze TYPO3 deprecation logs.

1. Navigate to Task execution
2. Select task with deprecation_log input type
3. Click "Refresh Input" to load log data
4. Execute task

**Expected Outcomes:**
- Deprecation log file contents loaded
- "No deprecation log file found" if file missing
- Task analyzes log entries and provides insights

### Pathway 5.8: Task Prompt Template Variables
**Trigger:** User creates tasks with template variables.

1. Create task with single variable ({{input}})
2. Create task with multiple variables
3. Create task with no variables (static prompt)

**Expected Outcomes:**
- Single variable correctly parsed
- Multiple variables all accessible
- Static prompts work without substitution

### Pathway 5.9: Task Output Format Variations
**Trigger:** User configures different output formats.

1. Create task with text output format
2. Create task with markdown output format
3. Create task with JSON output format

**Expected Outcomes:**
- Text output returned as plain text
- Markdown output supports formatting
- JSON output parseable as valid JSON

### Pathway 5.10: Task Category Management
**Trigger:** User organizes tasks by category.

1. View tasks grouped by category
2. Create task in new category
3. Filter tasks by category

**Expected Outcomes:**
- Tasks grouped correctly
- New categories created automatically
- Filter shows matching tasks only

### Pathway 5.11: Task Execution with Special Inputs
**Trigger:** User enters special characters in task input.

1. Execute task with unicode input
2. Execute task with HTML/script tags
3. Execute task with newlines and tabs

**Expected Outcomes:**
- Unicode preserved correctly
- HTML handled safely (not executed)
- Whitespace preserved in output

### Pathway 5.12: Task Listing and Filtering
**Trigger:** User lists and filters tasks.

1. List all tasks (active and inactive)
2. Filter by input type
3. Find system vs user tasks

**Expected Outcomes:**
- All tasks retrievable
- Input type filter works correctly
- System/user distinction maintained

### Pathway 5.13: Task Execution Error Handling
**Trigger:** User encounters task execution errors.

1. Execute with missing UID
2. Execute with zero UID
3. Execute with negative UID

**Expected Outcomes:**
- Missing UID returns appropriate error
- Zero/negative UIDs rejected
- Error messages are clear

### Pathway 5.14: Task Configuration Relationship
**Trigger:** User manages task-to-configuration relationships.

1. Create task with configuration
2. Create task without configuration
3. Change task configuration

**Expected Outcomes:**
- Configuration link preserved
- Tasks can work without configuration
- Configuration changes persist

### Pathway 5.15: Task AJAX Response Structure
**Trigger:** Task AJAX endpoints return consistent responses.

1. Execute response structure on success
2. Execute response structure on error
3. List tables response structure
4. Refresh input response structure

**Expected Outcomes:**
- Success responses have standard fields
- Error responses include error field
- JSON structure consistent

### Pathway 5.16: Task Count and Statistics
**Trigger:** User views task statistics.

1. Count all tasks
2. Count active tasks
3. Count by input type
4. Count system vs user tasks

**Expected Outcomes:**
- Total count accurate
- Active count matches filter
- Input type counts match
- System + User = Total

### Pathway 5.17: Task Description and Metadata
**Trigger:** User views and manages task metadata.

1. Task with description
2. Task with unicode description
3. Task input source configuration
4. Complete metadata validation

**Expected Outcomes:**
- Description preserved
- Unicode supported
- Input source JSON valid
- All metadata accessible

---

## 6. Dashboard Module

### Pathway 6.1: View Dashboard
**Trigger:** User accesses main LLM module.

1. Navigate to Admin → LLM
2. View dashboard

**Expected Outcomes:**
- Provider status overview
- Statistics (providers, models, configs, tasks)
- Feature matrix (capabilities per provider)
- Quick actions available

### Pathway 6.2: Quick Test Completion
**Trigger:** User wants to quickly test LLM.

1. On dashboard, find test section
2. Enter prompt
3. Select provider (optional)
4. Execute test
5. View response

**Expected Outcomes:**
- Completion executed
- Response displayed
- Usage statistics shown

### Pathway 6.3: Quick Test Edge Cases
**Trigger:** User attempts quick test with edge case inputs.

1. Attempt test with invalid/missing provider
2. Attempt test with special characters in prompt
3. Attempt test with very long prompt
4. Attempt test with whitespace-only prompt

**Expected Outcomes:**
- Invalid provider: Clear error message
- Special characters: Handled gracefully
- Long prompt: May fail with token limit error
- Whitespace: Uses default prompt or returns error

### Pathway 6.4: Dashboard Statistics Edge Cases
**Trigger:** Dashboard handles empty or deactivated entities.

1. Deactivate all providers/models/configurations
2. View dashboard statistics
3. Verify zero counts displayed

**Expected Outcomes:**
- Dashboard shows 0 active providers
- Dashboard shows 0 active models
- Dashboard shows 0 active configurations
- No errors or crashes

### Pathway 6.5: Dashboard Provider Health Check
**Trigger:** Dashboard displays provider health status.

1. View dashboard provider overview
2. Check provider health indicators

**Expected Outcomes:**
- Provider names displayed
- Active status shown
- API key status indicated (configured/missing)

### Pathway 6.6: Dashboard Model Overview
**Trigger:** Dashboard displays model summary.

1. View dashboard model section
2. Check model capabilities

**Expected Outcomes:**
- Model IDs displayed
- Context lengths shown
- Max output tokens shown
- Default model highlighted

### Pathway 6.7: Dashboard Task Overview
**Trigger:** Dashboard displays task summary.

1. View dashboard task section
2. Check task categories

**Expected Outcomes:**
- Tasks grouped by category
- Task names and status shown
- Active tasks counted

### Pathway 6.8: Dashboard Summary Statistics
**Trigger:** Dashboard displays entity counts.

1. View dashboard summary section
2. Check provider count
3. Check model count
4. Check configuration count

**Expected Outcomes:**
- Provider count accurate
- Model count accurate
- Configuration count accurate
- Active vs total distinction clear

### Pathway 6.9: Dashboard Feature Matrix
**Trigger:** Dashboard displays capability overview.

1. View feature matrix
2. Check model capabilities per provider

**Expected Outcomes:**
- Each provider type listed
- Model capabilities visible
- Feature comparison possible

### Pathway 6.10: Dashboard Navigation
**Trigger:** Dashboard provides access to all modules.

1. Access dashboard
2. Navigate to providers
3. Navigate to models
4. Navigate to configurations
5. Navigate to tasks

**Expected Outcomes:**
- All modules accessible
- Navigation consistent
- Entity lists load correctly

### Pathway 6.11: Quick Test with Different Prompts
**Trigger:** User tests different prompt types.

1. Test with code generation prompt
2. Test with translation prompt
3. Test with analysis prompt

**Expected Outcomes:**
- Code prompts handled
- Translation prompts handled
- Analysis prompts handled
- All return structured responses

### Pathway 6.12: Dashboard Default Entity Indicators
**Trigger:** Dashboard shows which entities are defaults.

1. Check default model indicator
2. Check default configuration indicator
3. Check highest priority provider indicator

**Expected Outcomes:**
- Default model highlighted
- Default configuration highlighted
- Highest priority provider identifiable
- At most one default per type

### Pathway 6.13: Dashboard Entity Counts by Type
**Trigger:** Dashboard shows entity breakdown by type.

1. View providers by adapter type
2. View tasks by category
3. View tasks by input type

**Expected Outcomes:**
- Providers grouped by adapter type
- Tasks grouped by category
- Tasks grouped by input type
- All counts accurate

### Pathway 6.14: Dashboard Quick Actions Availability
**Trigger:** Dashboard shows available quick actions.

1. Check quick test availability
2. Check quick task execution availability
3. Check configuration availability

**Expected Outcomes:**
- Quick test available when provider exists
- Quick task available when task exists
- Configuration usage available when config exists

### Pathway 6.15: Dashboard Data Integrity
**Trigger:** Dashboard verifies data consistency.

1. Check count consistency
2. Verify model-provider relationships
3. Verify configuration-model relationships

**Expected Outcomes:**
- All counts are integers
- All models have valid providers
- Configurations with models have valid chain

### Pathway 6.16: Dashboard Empty State Handling
**Trigger:** Dashboard handles empty data gracefully.

1. Handle zero providers
2. Handle zero models
3. Handle zero configurations
4. Handle zero tasks

**Expected Outcomes:**
- Zero counts returned as integers
- No errors on empty data
- UI handles empty state

### Pathway 6.17: Dashboard Statistics Accuracy
**Trigger:** Dashboard shows accurate statistics.

1. Compare active vs total providers
2. Compare active vs total models
3. Compare active vs total configurations
4. Compare active vs total tasks

**Expected Outcomes:**
- Active count <= total count
- Both counts are valid integers
- Statistics are accurate

### Pathway 6.18: Dashboard Quick Test Availability
**Trigger:** Dashboard checks entity query support.

1. Query active providers
2. Query active models
3. Query active configurations
4. Query active tasks

**Expected Outcomes:**
- Provider queries return results
- Model queries include provider
- Configuration queries return identifiers
- Task queries return identifiers

---

## 7. Error Handling Pathways

### Pathway 7.1: Invalid API Key
**Trigger:** User enters wrong API key.

1. Attempt connection test
2. View error response

**Expected Outcomes:**
- Clear error message (401 Unauthorized)
- Suggestion to check credentials
- No crash or hang

### Pathway 7.2: Rate Limit Exceeded
**Trigger:** Too many API requests.

1. Execute multiple tests rapidly
2. Hit rate limit

**Expected Outcomes:**
- 429 error displayed
- Retry-after time shown
- Graceful degradation

### Pathway 7.3: Network Timeout
**Trigger:** Provider API unresponsive.

1. Test against slow/unresponsive endpoint
2. Wait for timeout

**Expected Outcomes:**
- Timeout error after configured duration
- Clear message about connectivity
- Option to retry

### Pathway 7.4: Invalid Model Selection
**Trigger:** User selects model not available for provider.

1. Attempt to use model not supported by provider
2. View error

**Expected Outcomes:**
- Clear error about model availability
- Suggestion to check model list
- No data corruption

### Pathway 7.5: Input Validation and Security
**Trigger:** User enters potentially malicious or edge case inputs.

1. Enter special characters (XSS attempts) in names
2. Enter unicode characters
3. Attempt SQL injection via identifiers
4. Enter negative, zero, very large UIDs
5. Enter null bytes or other special values
6. Enter very long API keys

**Expected Outcomes:**
- XSS attempts stored safely (not executed)
- Unicode preserved correctly
- SQL injection attempts fail safely
- Invalid UIDs return appropriate errors
- Null bytes handled without crashes
- Long inputs stored or truncated safely

### Pathway 7.6: Error Recovery and State Consistency
**Trigger:** Operation fails midway or rapid operations occur.

1. Failed operation on non-existent record
2. Rapid sequential toggle operations
3. Concurrent default model changes

**Expected Outcomes:**
- Failed operations don't corrupt state
- Rapid operations maintain consistency
- Only one default exists after concurrent changes
- Database state remains valid

### Pathway 7.7: Connection Failure Handling
**Trigger:** Provider connection fails due to network issues.

1. Configure provider with unreachable endpoint
2. Test connection
3. Retry after fixing

**Expected Outcomes:**
- Structured error returned (not crash)
- Error message describes issue
- Retry mechanism works

### Pathway 7.8: Data Integrity Under Errors
**Trigger:** Errors don't corrupt existing data.

1. Record initial state
2. Trigger error condition
3. Verify state unchanged

**Expected Outcomes:**
- Failed operations don't affect other entities
- Entity counts unchanged after errors
- Related entities remain consistent

### Pathway 7.9: Error Message Quality
**Trigger:** Error messages are user-friendly.

1. Trigger missing UID error
2. Trigger not found error
3. Trigger validation error

**Expected Outcomes:**
- No stack traces in user messages
- Specific error descriptions
- Actionable error guidance

### Pathway 7.10: Graceful Degradation
**Trigger:** System works with partial configuration.

1. System with no providers
2. System with deactivated provider
3. Model actions with inactive provider

**Expected Outcomes:**
- Empty states handled gracefully
- Partial configurations functional
- Independent operations work

### Pathway 7.11: HTTP Method and Content Type Validation
**Trigger:** User sends malformed requests.

1. Send empty request body
2. Send malformed JSON
3. Send extra unexpected fields

**Expected Outcomes:**
- Empty body returns 400 error
- Malformed data handled gracefully
- Extra fields ignored safely

### Pathway 7.12: Boundary Value Testing
**Trigger:** User tests extreme values.

1. Use max integer UID
2. Use min integer UID
3. Use zero timeout
4. Use negative timeout

**Expected Outcomes:**
- Max int returns not found
- Min int handled gracefully
- Zero/negative timeout normalized

### Pathway 7.13: Concurrent Operation Safety
**Trigger:** Rapid sequential operations.

1. Rapid toggle operations (10x)
2. Sequential default changes
3. Concurrent state modifications

**Expected Outcomes:**
- State consistent after rapid ops
- Only one default after changes
- No race condition issues

### Pathway 7.14: Error Response Format Consistency
**Trigger:** All errors follow same format.

1. Check success field in errors
2. Check error field in errors
3. Validate JSON structure

**Expected Outcomes:**
- All errors have success=false
- All errors have error message
- All responses are valid JSON

### Pathway 7.15: Provider Adapter Type Errors
**Trigger:** User creates provider with invalid adapter.

1. Unknown adapter type
2. Empty adapter type
3. Test connection with invalid adapter

**Expected Outcomes:**
- Unknown adapter created (validated later)
- Empty adapter handled
- Test returns structured error

### Pathway 7.16: Model Discovery Errors
**Trigger:** User tries model discovery with invalid data.

1. Fetch models for invalid provider
2. Detect limits for invalid provider
3. Detect limits without model ID

**Expected Outcomes:**
- Provider not found error
- Missing parameters return 400
- Structured error responses

---

## 8. Multi-Provider Workflows

### Pathway 8.1: Switch Between Providers
**Trigger:** User wants to compare providers.

1. Execute task with Provider A
2. View results
3. Change provider in configuration
4. Execute same task
5. Compare results

**Expected Outcomes:**
- Both executions complete
- Results clearly attributed to providers
- Easy comparison possible

### Pathway 8.2: Fallback Provider
**Trigger:** Primary provider fails.

1. Configure primary and fallback providers
2. Primary becomes unavailable
3. System uses fallback

**Expected Outcomes:**
- Automatic failover
- User notified of fallback use
- Results still delivered

### Pathway 8.3: Provider Comparison
**Trigger:** User compares capabilities across providers.

1. View all active providers
2. Compare model counts and capabilities
3. Review model specifications per provider

**Expected Outcomes:**
- Provider capabilities retrievable
- Models distinct per provider
- Comparison data accessible

### Pathway 8.4: Provider Selection for Tasks
**Trigger:** User assigns tasks to different providers.

1. View tasks and their configurations
2. Change task configuration to use different provider
3. Verify task uses new configuration

**Expected Outcomes:**
- Tasks can use any active configuration
- Configuration changes persist
- Task execution uses selected provider

### Pathway 8.5: Multi-Provider Model Selection
**Trigger:** User selects models across providers.

1. View models from all providers
2. Select model for configuration
3. Verify default model uniqueness

**Expected Outcomes:**
- Models from all providers visible
- Each model uniquely identifiable
- Only one default model globally

### Pathway 8.6: Complete End-to-End Workflow
**Trigger:** User traces complete data flow.

1. Verify provider is active
2. Check provider has models
3. Verify model links to provider
4. Find configuration using model
5. Verify complete chain works

**Expected Outcomes:**
- Provider → Model → Configuration chain intact
- All relationships bidirectional
- Complete workflow executable

### Pathway 8.7: Provider Type Comparison
**Trigger:** User compares different provider types.

1. List providers by adapter type
2. Filter by specific adapter type
3. Compare capabilities per type

**Expected Outcomes:**
- Each type correctly categorized
- Filtering returns accurate results
- Type-specific features available

### Pathway 8.8: Cross-Module State Consistency
**Trigger:** Changes in one module affect related modules.

1. Change provider name
2. Verify models reflect change
3. Change model name
4. Verify configurations reflect change

**Expected Outcomes:**
- Related entities update correctly
- No orphaned references
- State remains consistent

### Pathway 8.9: Bulk Operations
**Trigger:** User performs operations on multiple entities.

1. Deactivate all providers
2. Verify system handles empty state
3. Restore all providers

**Expected Outcomes:**
- Bulk deactivation works
- Empty state handled gracefully
- Restoration successful

### Pathway 8.10: Provider-Specific Features
**Trigger:** User accesses provider-specific settings.

1. Check endpoint URL configuration
2. Check timeout settings
3. Check priority settings

**Expected Outcomes:**
- Custom endpoints configurable
- Timeouts apply to API calls
- Priority determines fallback order

### Pathway 8.11: Cross-Entity Validation
**Trigger:** System validates entity relationships.

1. Verify all models have valid providers
2. Verify configurations have valid models
3. Verify task-configuration chains

**Expected Outcomes:**
- Models always linked to providers
- Configuration model references valid
- Task chains complete when configured

### Pathway 8.12: Multi-Provider Model Distribution
**Trigger:** User manages models across providers.

1. View models per provider
2. Query by provider UID
3. Verify counts match

**Expected Outcomes:**
- Models distributed across providers
- Per-provider queries work
- Sum matches total

### Pathway 8.13: Provider Adapter Type Consistency
**Trigger:** System validates adapter types.

1. All providers have valid type
2. Types distributed correctly
3. Query by adapter type

**Expected Outcomes:**
- Only valid types allowed
- Type distribution visible
- Type filtering works

### Pathway 8.14: Complete Data Chain Integrity
**Trigger:** System validates full data chains.

1. Task-config-model-provider chain
2. No orphaned models
3. Default entity uniqueness
4. All entities have required fields

**Expected Outcomes:**
- Full chains are valid
- No orphaned entities
- Only one default per type
- Required fields present

---

## Test Coverage Matrix

| Module | Pathway | Priority | Complexity | Controller | Repository | Service |
|--------|---------|----------|------------|------------|------------|---------|
| Wizard | 1.1 First-Time Setup | High | High | ✅ | ✅ | ✅ |
| Wizard | 1.2 Add Provider | High | Medium | ✅ | ✅ | ✅ |
| Wizard | 1.3 Model Discovery | High | Medium | ✅ | ✅ | ✅ |
| Wizard | 1.4 Config Generation | Medium | Medium | ✅ | ✅ | ✅ |
| Wizard | 1.5 Test Connection | High | Low | ✅ | - | ✅ |
| Wizard | 1.6 Input Validation | Medium | Low | ✅ | ✅ | - |
| Wizard | 1.7 Reconfiguration | Medium | Low | ✅ | ✅ | - |
| Wizard | 1.8 Wizard Validation | Medium | Low | ✅ | ✅ | - |
| Wizard | 1.9 Multi-Provider | Medium | Low | ✅ | ✅ | - |
| Wizard | 1.10 Error Recovery | Medium | Low | ✅ | ✅ | - |
| Wizard | 1.11 Detection Edge Cases | Medium | Low | ✅ | ✅ | - |
| Wizard | 1.12 Model Selection | Medium | Low | ✅ | ✅ | - |
| Wizard | 1.13 Config Templates | Medium | Low | ✅ | ✅ | - |
| Wizard | 1.14 API Key Formats | Medium | Low | ✅ | ✅ | - |
| Provider | 2.1 View List | High | Low | ✅ | ✅ | - |
| Provider | 2.2 Toggle Status | High | Low | ✅ | ✅ | - |
| Provider | 2.3 Test Connection | High | Medium | ✅ | ✅ | ✅ |
| Provider | 2.4 Edit Provider | Medium | Medium | 📋 | ✅ | - |
| Provider | 2.5 Delete Provider | Medium | Medium | 📋 | ✅ | - |
| Provider | 2.6 Test Edge Cases | Medium | Low | ✅ | - | - |
| Provider | 2.7 Data Validation | Medium | Low | ✅ | ✅ | - |
| Provider | 2.8 Model Cascade | Medium | Medium | ✅ | ✅ | - |
| Provider | 2.9 Provider Types | Low | Low | ✅ | ✅ | - |
| Provider | 2.10 Endpoint Config | Medium | Low | ✅ | ✅ | - |
| Provider | 2.11 Priority Mgmt | Medium | Low | ✅ | ✅ | - |
| Provider | 2.12 Listing Filters | Medium | Low | ✅ | ✅ | - |
| Provider | 2.13 Config Validation | Medium | Low | ✅ | ✅ | - |
| Provider | 2.14 Search/Discovery | Medium | Low | ✅ | ✅ | - |
| Provider | 2.15 Lifecycle | Medium | Medium | ✅ | ✅ | - |
| Provider | 2.16 API Key Mgmt | Medium | Low | ✅ | ✅ | - |
| Provider | 2.17 Timeout Config | Medium | Low | ✅ | ✅ | - |
| Provider | 2.18 Name Variations | Medium | Low | ✅ | ✅ | - |
| Provider | 2.19 AJAX Response | Medium | Low | ✅ | - | - |
| Provider | 2.20 Endpoint Variations | Medium | Low | ✅ | ✅ | - |
| Model | 3.1 View List | High | Low | ✅ | ✅ | - |
| Model | 3.2 Filter by Provider | Medium | Low | ✅ | ✅ | - |
| Model | 3.3 Toggle Status | High | Low | ✅ | ✅ | - |
| Model | 3.4 Set Default | High | Low | ✅ | ✅ | - |
| Model | 3.5 Test Model | High | Medium | ✅ | ✅ | ✅ |
| Model | 3.6 Fetch Available | Medium | High | ✅ | ✅ | ✅ |
| Model | 3.7 Detect Limits | Medium | Medium | ✅ | ✅ | ✅ |
| Model | 3.8 Edit Model | Medium | Medium | 📋 | ✅ | - |
| Model | 3.9 Get by Provider | Medium | Low | ✅ | ✅ | - |
| Model | 3.10 Toggle Edge Cases | Medium | Low | ✅ | - | - |
| Model | 3.11 Set Default Edge | Medium | Low | ✅ | - | - |
| Model | 3.12 Test Model Edge | Medium | Low | ✅ | - | - |
| Model | 3.13 Fetch Edge Cases | Medium | Low | ✅ | - | - |
| Model | 3.14 Detect Limits Edge | Medium | Low | ✅ | - | - |
| Model | 3.15 Context/Tokens | Medium | Low | ✅ | ✅ | - |
| Model | 3.16 Capabilities | Medium | Low | ✅ | ✅ | - |
| Model | 3.17 Search/Filtering | Medium | Low | ✅ | ✅ | - |
| Model | 3.18 Lifecycle | Medium | Medium | ✅ | ✅ | - |
| Model | 3.19 Name Variations | Medium | Low | ✅ | ✅ | - |
| Model | 3.20 AJAX Response | Medium | Low | ✅ | - | - |
| Model | 3.21 ID Format Variations | Medium | Low | ✅ | ✅ | - |
| Model | 3.22 Limit Edge Cases | Medium | Low | ✅ | ✅ | - |
| Config | 4.1 View List | High | Low | ✅ | ✅ | ✅ |
| Config | 4.2 Toggle Status | High | Low | ✅ | ✅ | ✅ |
| Config | 4.3 Set Default | High | Low | ✅ | ✅ | ✅ |
| Config | 4.4 Test Config | High | Medium | ✅ | ✅ | ✅ |
| Config | 4.5 Create New | High | Medium | 📋 | ✅ | ✅ |
| Config | 4.6 Clone Config | Low | Low | 📋 | ✅ | - |
| Config | 4.7 No Model Error | Medium | Low | ✅ | ✅ | - |
| Config | 4.8 Toggle Edge Cases | Medium | Low | ✅ | - | - |
| Config | 4.9 Set Default Edge | Medium | Low | ✅ | - | - |
| Config | 4.10 Get Models Edge | Medium | Low | ✅ | - | - |
| Config | 4.11 System Prompt Mgmt | Medium | Low | ✅ | ✅ | - |
| Config | 4.12 Parameter Bounds | Medium | Low | ✅ | ✅ | - |
| Config | 4.13 Listing/Filtering | Medium | Low | ✅ | ✅ | - |
| Config | 4.14 State Transitions | Medium | Low | ✅ | ✅ | - |
| Config | 4.15 Identifier Mgmt | Medium | Low | ✅ | ✅ | - |
| Config | 4.16 Name Handling | Medium | Low | ✅ | ✅ | - |
| Config | 4.17 Cascade Behavior | Medium | Medium | ✅ | ✅ | - |
| Config | 4.18 AJAX Response | Medium | Low | ✅ | - | - |
| Config | 4.19 Count/Stats | Medium | Low | ✅ | ✅ | - |
| Config | 4.20 Precision Values | Medium | Low | ✅ | ✅ | - |
| Task | 5.1 View List | High | Low | ✅ | ✅ | - |
| Task | 5.2 Manual Input | High | Medium | ✅ | ✅ | ✅ |
| Task | 5.3 Database Records | Medium | High | ✅ | ✅ | ✅ |
| Task | 5.4 System Log | Medium | High | ✅ | ✅ | ✅ |
| Task | 5.5 Create Task | Medium | Medium | 📋 | ✅ | - |
| Task | 5.6 Inactive Task | Medium | Low | ✅ | ✅ | - |
| Task | 5.7 Deprecation Log | Medium | Medium | ✅ | ✅ | - |
| Task | 5.8 Template Variables | Medium | Low | ✅ | ✅ | - |
| Task | 5.9 Output Formats | Medium | Low | ✅ | ✅ | - |
| Task | 5.10 Category Mgmt | Medium | Low | ✅ | ✅ | - |
| Task | 5.11 Special Inputs | Medium | Low | ✅ | - | - |
| Task | 5.12 Listing/Filtering | Medium | Low | ✅ | ✅ | - |
| Task | 5.13 Error Handling | Medium | Low | ✅ | - | - |
| Task | 5.14 Config Relationship | Medium | Low | ✅ | ✅ | - |
| Task | 5.15 AJAX Response | Medium | Low | ✅ | - | - |
| Task | 5.16 Count/Stats | Medium | Low | ✅ | ✅ | - |
| Task | 5.17 Metadata | Medium | Low | ✅ | ✅ | - |
| Dashboard | 6.1 View Dashboard | High | Low | ✅ | - | - |
| Dashboard | 6.2 Quick Test | Medium | Medium | ✅ | - | ✅ |
| Dashboard | 6.3 Quick Test Edge | Medium | Low | ✅ | - | - |
| Dashboard | 6.4 Statistics Edge | Medium | Low | ✅ | ✅ | - |
| Dashboard | 6.5 Provider Health | Medium | Low | ✅ | ✅ | - |
| Dashboard | 6.6 Model Overview | Medium | Low | ✅ | ✅ | - |
| Dashboard | 6.7 Task Overview | Medium | Low | ✅ | ✅ | - |
| Dashboard | 6.8 Summary Stats | Medium | Low | ✅ | ✅ | - |
| Dashboard | 6.9 Feature Matrix | Medium | Low | ✅ | ✅ | - |
| Dashboard | 6.10 Navigation | Medium | Low | ✅ | ✅ | - |
| Dashboard | 6.11 Prompt Types | Medium | Low | ✅ | - | - |
| Dashboard | 6.12 Default Indicators | Medium | Low | ✅ | ✅ | - |
| Dashboard | 6.13 Counts by Type | Medium | Low | ✅ | ✅ | - |
| Dashboard | 6.14 Quick Actions | Medium | Low | ✅ | ✅ | - |
| Dashboard | 6.15 Data Integrity | Medium | Low | ✅ | ✅ | - |
| Dashboard | 6.16 Empty State | Medium | Low | ✅ | ✅ | - |
| Dashboard | 6.17 Statistics Accuracy | Medium | Low | ✅ | ✅ | - |
| Dashboard | 6.18 Query Support | Medium | Low | ✅ | ✅ | - |
| Error | 7.1 Invalid API Key | High | Low | ✅ | - | ✅ |
| Error | 7.2 Rate Limit | Medium | Medium | ✅ | - | ✅ |
| Error | 7.3 Timeout | Medium | Medium | ✅ | - | ✅ |
| Error | 7.4 Invalid Model | Medium | Low | ✅ | - | ✅ |
| Error | 7.5 Input Validation | Medium | Low | ✅ | ✅ | - |
| Error | 7.6 State Consistency | Medium | Medium | ✅ | ✅ | - |
| Error | 7.7 Connection Failure | Medium | Low | ✅ | ✅ | - |
| Error | 7.8 Data Integrity | Medium | Medium | ✅ | ✅ | - |
| Error | 7.9 Message Quality | Medium | Low | ✅ | ✅ | - |
| Error | 7.10 Graceful Degradation | Medium | Low | ✅ | ✅ | - |
| Error | 7.11 HTTP Validation | Medium | Low | ✅ | - | - |
| Error | 7.12 Boundary Values | Medium | Low | ✅ | - | - |
| Error | 7.13 Concurrent Safety | Medium | Medium | ✅ | - | - |
| Error | 7.14 Response Format | Medium | Low | ✅ | - | - |
| Error | 7.15 Adapter Errors | Medium | Low | ✅ | - | - |
| Error | 7.16 Discovery Errors | Medium | Low | ✅ | - | - |
| Multi | 8.1 Switch Providers | Medium | Medium | ✅ | ✅ | ✅ |
| Multi | 8.2 Fallback | Low | High | ✅ | ✅ | ✅ |
| Multi | 8.3 Provider Compare | Medium | Low | ✅ | ✅ | - |
| Multi | 8.4 Task Selection | Medium | Medium | ✅ | ✅ | - |
| Multi | 8.5 Model Selection | Medium | Low | ✅ | ✅ | - |
| Multi | 8.6 E2E Workflow | Medium | Medium | ✅ | ✅ | - |
| Multi | 8.7 Type Comparison | Medium | Low | ✅ | ✅ | - |
| Multi | 8.8 State Consistency | Medium | Medium | ✅ | ✅ | - |
| Multi | 8.9 Bulk Operations | Medium | Medium | ✅ | ✅ | - |
| Multi | 8.10 Provider Features | Medium | Low | ✅ | ✅ | - |
| Multi | 8.11 Cross-Entity Validation | Medium | Low | ✅ | ✅ | - |
| Multi | 8.12 Model Distribution | Medium | Low | ✅ | ✅ | - |
| Multi | 8.13 Adapter Type Consistency | Medium | Low | ✅ | ✅ | - |
| Multi | 8.14 Data Chain Integrity | Medium | Medium | ✅ | ✅ | - |

**Legend:**
- ✅ **Covered**: Functional tests exist at this layer
- 📋 **FormEngine**: Uses TYPO3 FormEngine (TCA-based forms) - covered by TCA validation
- `-` **N/A**: Not applicable for this layer

**Test Statistics (as of 2026-01-01):**
- E2E Backend tests: 497 tests across 8 test files (complete user pathway coverage)
- Functional tests: 248 tests across 16 test files
- Unit tests: 1407 tests, 17709 assertions
- Total coverage: All 158 user pathways have comprehensive E2E and functional test coverage

**Test File Organization:**

E2E Tests (`Tests/E2E/Backend/`):
- `SetupWizardE2ETest.php` - Pathways 1.1-1.14: Setup wizard flows (57 tests)
- `ProviderManagementE2ETest.php` - Pathways 2.1-2.20: Provider management (62 tests)
- `ModelManagementE2ETest.php` - Pathways 3.1-3.22: Model management flows (66 tests)
- `ConfigurationManagementE2ETest.php` - Pathways 4.1-4.20: Configuration management (65 tests)
- `TaskExecutionE2ETest.php` - Pathways 5.1-5.17: Task execution flows (73 tests)
- `DashboardE2ETest.php` - Pathways 6.1-6.18: Dashboard and quick test (63 tests)
- `ErrorPathwaysE2ETest.php` - Pathways 7.1-7.16: Error handling and security (59 tests)
- `MultiProviderWorkflowsE2ETest.php` - Pathways 8.1-8.14: Multi-provider workflows (52 tests)

Functional Tests:
- `Tests/Functional/Controller/Backend/` - Backend controller tests (8 files)
- `Tests/Functional/Repository/` - Repository layer tests (4 files)
- `Tests/Functional/Service/` - Service layer tests (2 files)
- `Tests/Functional/Provider/` - Provider adapter tests (2 files)
