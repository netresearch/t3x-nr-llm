<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server;

/**
 * What the self-service tests add to {@see A2aServerFixture} (ADR-294): group
 * 30 holding the grant with profiles 1 and 2, editors 20 and 21 in it, editor
 * 24 in a group with the relaxations but without the grant, and draft
 * workspace 1 open to 20 and 21. The using test class loads the `workspaces`
 * core extension and the `A2aServerFixture` trait.
 */
trait A2aSelfServiceFixture
{
    private const GRANT = 'tx_nrllm_grants:a2a_token_self_service';

    private function setUpSelfService(): void
    {
        $this->db('be_groups')->insert('be_groups', ['uid' => 30, 'pid' => 0, 'title' => 'A2A editors', 'custom_options' => self::GRANT, 'tx_nrllm_a2a_profiles' => '1,2']);
        $this->db('be_groups')->insert('be_groups', ['uid' => 32, 'pid' => 0, 'title' => 'Relaxed, no grant', 'tx_nrllm_a2a_profiles' => '1,2', 'tx_nrllm_a2a_allow_live' => 1, 'tx_nrllm_a2a_allow_no_expiry' => 1]);
        foreach ([20 => ['editor', '30'], 21 => ['other-editor', '30'], 24 => ['no-grant', '32']] as $uid => [$name, $groups]) {
            $this->db('be_users')->insert('be_users', ['uid' => $uid, 'username' => $name, 'admin' => 0, 'usergroup' => $groups, 'workspace_perms' => 3]);
        }

        $this->db('sys_workspace')->insert('sys_workspace', ['uid' => 1, 'pid' => 0, 'title' => 'Drafts', 'members' => 'be_users_20,be_users_21,be_users_24']);
    }
}
