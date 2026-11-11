<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Agent;

use JsonException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aAgentUnavailableException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aCallFailedException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aInvalidAgentResponseException;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aAgentCard;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aAgentInterface;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aProtocol;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aResponseParser;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aHttpRequest;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aTransportInterface;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aTransportOutcome;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aUrlGuardFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Imports the agent card of a remote agent record (spec 051 A2A-C2).
 *
 * The card URL the operator typed is fetched through the A2A transport, and
 * so through the address guard (ADR-279), without the record's credential: a
 * public card needs none. The card is validated against the proto (A2A-P5),
 * the first `supportedInterfaces` entry with binding `JSONRPC` and protocol
 * version `1.0` is selected (§8.3.2), and its URL must pass the same guard. A
 * card without such an entry is refused with a message naming what it offers.
 *
 * The import writes only the card fields. It never writes `data_class`,
 * `enabled` or `requires_approval`: the remote does not decide its own trust
 * (A2A-C1). When a record that was imported before gets another interface URL
 * or other capabilities, the import marks it `card_changed`, and the client
 * refuses it until an administrator confirms the card ({@see A2aRemoteAgentSaveHook}).
 *
 * The same holds on the FIRST import when the interface sits on another host
 * than the card URL the operator typed: the interface is where the record's
 * credential goes, and a card must not move it to a host nobody chose without
 * an operator looking at it first. Host and port are compared, scheme
 * included, so a card on https cannot point the credential to plain http on
 * the same host unconfirmed either.
 *
 * A failed import keeps the card that was there and records the reason.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aCardImporter
{
    public function __construct(
        private A2aTransportInterface $transport,
        private A2aResponseParser $parser,
        private A2aUrlGuardFactory $guardFactory,
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @throws A2aAgentUnavailableException when the record may not import
     * @throws A2aCallFailedException       when the card cannot be imported; the reason is stored on the record
     */
    public function import(A2aRemoteAgent $agent): A2aCardImportResult
    {
        $refusal = $agent->importRefusal();
        if ($refusal !== null) {
            throw A2aAgentUnavailableException::because($refusal);
        }

        try {
            [$card, $json, $etag] = $this->fetch($agent);
            $interface            = $card->selectInterface();
            if (!$interface instanceof A2aAgentInterface) {
                throw A2aCallFailedException::local(
                    'Card import',
                    'NO_JSONRPC_1_0_INTERFACE',
                    sprintf('the card offers no JSONRPC 1.0 interface, only: %s', $card->describeInterfaces()),
                );
            }

            $target = $this->guardFactory->create()->check(A2aUrlGuardFactory::GROUP_AGENT, $interface->url);
            if (!$target->allowed) {
                throw A2aCallFailedException::local('Card import', 'INTERFACE_ADDRESS_REFUSED', 'the interface URL of the card is refused: ' . $target->reason);
            }
        } catch (A2aCallFailedException $e) {
            $this->update($agent, ['import_error' => mb_substr($e->getMessage(), 0, 2000)]);

            throw $e;
        }

        $capabilities = $card->capabilities->fingerprint();
        $changed      = $agent->getInterfaceUrl() !== ''
            && ($agent->getInterfaceUrl() !== $interface->url || $agent->getCardCapabilities() !== $capabilities);
        $changed = $changed
            || (A2aRemoteAgent::originOf($interface->url) !== A2aRemoteAgent::originOf($agent->getCardUrl())
                && $interface->url !== $agent->getConfirmedInterface());

        $fields = [
            'card_json'         => $json,
            'card_fetched'      => time(),
            'card_etag'         => mb_substr($etag, 0, 255),
            'interface_url'     => $interface->url,
            'interface_tenant'  => $interface->tenant,
            'card_capabilities' => $capabilities,
            'import_error'      => '',
        ];
        if ($changed) {
            $fields['card_changed'] = 1;
        }

        $this->update($agent, $fields);

        return new A2aCardImportResult($card, $interface, $changed || $agent->isCardChanged());
    }

    /**
     * @return array{A2aAgentCard, string, string} the card, its JSON as received, its ETag
     *
     * @throws A2aCallFailedException
     */
    private function fetch(A2aRemoteAgent $agent): array
    {
        $result = $this->transport->send(new A2aHttpRequest(
            'GET',
            $agent->getCardUrl(),
            ['Accept' => A2aProtocol::CONTENT_TYPE, A2aProtocol::VERSION_HEADER => A2aProtocol::VERSION],
            '',
            '',
            $agent->getConnectTimeoutSeconds(),
            $agent->getReadTimeoutSeconds(),
            $agent->getMaxResponseBytes(),
            sprintf('nr-llm A2A card import for "%s"', $agent->getIdentifier()),
        ));

        if ($result->outcome !== A2aTransportOutcome::RESPONSE) {
            throw A2aCallFailedException::local('Card import', $result->reason, $result->detail);
        }

        if ($result->oversized) {
            throw A2aCallFailedException::local('Card import', 'RESPONSE_TOO_LARGE', "the card is larger than the record's maximum response size");
        }

        if ($result->status !== 200) {
            throw A2aCallFailedException::local('Card import', 'HTTP_STATUS', sprintf('the card URL answered HTTP %d', $result->status));
        }

        try {
            $decoded = json_decode($result->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw A2aCallFailedException::local('Card import', 'INVALID_CARD', 'the card is not JSON');
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw A2aCallFailedException::local('Card import', 'INVALID_CARD', 'the card is not a JSON object');
        }

        $object = [];
        foreach ($decoded as $key => $value) {
            $object[(string) $key] = $value;
        }

        try {
            $card = $this->parser->parseAgentCard($object);
        } catch (A2aInvalidAgentResponseException $e) {
            throw A2aCallFailedException::local('Card import', 'INVALID_CARD', $e->getMessage());
        }

        return [$card, $result->body, $result->etag];
    }

    /**
     * @param array<string, int|string> $fields
     */
    private function update(A2aRemoteAgent $agent, array $fields): void
    {
        $this->connectionPool
            ->getConnectionForTable(A2aRemoteAgentSaveHook::TABLE)
            ->update(A2aRemoteAgentSaveHook::TABLE, $fields + ['tstamp' => time()], ['uid' => (int) $agent->getUid()]);
    }
}
