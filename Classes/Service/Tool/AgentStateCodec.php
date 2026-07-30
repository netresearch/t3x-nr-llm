<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Exception\AgentStateDecryptionException;
use Netresearch\NrVault\Crypto\EnvelopeCodecInterface;
use Netresearch\NrVault\Exception\EncryptionException;
use Netresearch\NrVault\Exception\EnvelopeFormatException;
use Netresearch\NrVault\Exception\MasterKeyException;
use SensitiveParameter;

/**
 * Authenticated encryption for an agent run's state at rest (ADR-114).
 *
 * A queued run stores its serialised request, and a suspended run its
 * transcript and pending tool calls, in `tx_nrllm_agentrun` while it waits for a
 * worker or a human. Both hold prompts, tool arguments and internal TYPO3
 * content in cleartext — readable by anyone with database access. This codec
 * encrypts them so the row carries ciphertext, not the conversation.
 *
 * Values written by nr-llm 0.24.0-0.25.x carry an older marker;
 * {@see AgentStateEnvelopeMarker} maps them onto the current form so no data
 * migration is needed.
 *
 * The envelope is nr-vault's ({@see EnvelopeCodecInterface}, nr-vault ADR-032):
 * a per-value data key wrapped by a rotatable master key, authenticated so a
 * tampered row fails to decrypt, and bound to a per-column ``identifier`` used as
 * additional authenticated data so a ciphertext cannot be moved between the two
 * columns. What used to be a hundred lines of framing and field validation here
 * now lives upstream, and this class is only the storage boundary: the marker
 * compatibility below, and the mapping of vault exceptions onto the fail-soft
 * contract the repository expects.
 *
 * Rotation is covered by {@see AgentStateEnvelopeRotator}, registered with
 * nr-vault so ``vault:rotate-master-key`` re-wraps these rows inside its own
 * transaction (nr-vault ADR-033). Sealing without that registration would leave
 * every encrypted row unreadable after the next rotation.
 */
final readonly class AgentStateCodec
{
    /** AAD identifier for the queued request payload (ADR-114). */
    public const PURPOSE_QUEUED_REQUEST = 'nrllm:agent-state:queued-request';

    /** AAD identifier for the suspended run state payload (ADR-114). */
    public const PURPOSE_SUSPENDED_STATE = 'nrllm:agent-state:suspended-state';

    public function __construct(
        private EnvelopeCodecInterface $envelopeCodec,
    ) {}

    /**
     * Encrypt a plaintext state payload for storage under the given per-column
     * identifier (used as AAD). An empty string stores as empty (the "no state"
     * sentinel the columns already use), never as a ciphertext of the empty
     * string.
     *
     * @throws EncryptionException If encryption fails
     * @throws MasterKeyException  If the master key is unavailable — a SIBLING of
     *                             EncryptionException, not a subclass, so the
     *                             fail-soft persister must catch both rather than
     *                             storing cleartext
     */
    public function encode(#[SensitiveParameter] string $plaintext, string $identifier): string
    {
        if ($plaintext === '') {
            return '';
        }

        return $this->envelopeCodec->seal($plaintext, $identifier);
    }

    /**
     * Decrypt a stored state payload. A value with neither the current nor the
     * legacy marker is treated as pre-encryption cleartext and returned verbatim
     * (upgrade path); an empty value returns empty. A marked value that fails
     * authentication — tampered, truncated, moved to the wrong column, or written
     * under a different key — throws rather than returning a forged plaintext.
     *
     * @throws AgentStateDecryptionException
     */
    public function decode(string $stored, string $identifier): string
    {
        $sealed = AgentStateEnvelopeMarker::normalise($stored);
        if ($sealed === null) {
            return $stored;
        }

        try {
            return $this->envelopeCodec->open($sealed, $identifier);
        } catch (EnvelopeFormatException $exception) {
            throw AgentStateDecryptionException::corrupted($exception);
        } catch (EncryptionException|MasterKeyException $exception) {
            throw AgentStateDecryptionException::authenticationFailed($exception);
        }
    }

}
