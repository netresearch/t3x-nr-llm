<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

/**
 * Marks an invocation rule the loop may also ask before it shows an approval
 * card (ADR-293), so that an editor is not asked to approve a call the rule
 * refuses anyway.
 *
 * The rule answers from the call, the configuration and the execution context
 * alone, and gives the same answer each time it is asked about the same call.
 * It does not read the invocation history, because before the card nothing of
 * the turn has run yet and a history-dependent answer would differ from the
 * one given when the call runs. It writes nothing. A rule that cannot promise
 * all of this does not implement this interface and is asked only immediately
 * before the call runs, as before. A call the rule refuses before the card
 * does not wait for approval, so a rule that then allowed the same call would
 * let it run unapproved; the promise is what rules that out.
 *
 * It is a marker, not a second contract: the rule still decides through
 * {@see ToolInvocationRuleInterface::decide()}, and the same decision is taken
 * again right before the call runs, including when the run resumes.
 *
 * @api
 */
interface PreApprovalInvocationRuleInterface {}
