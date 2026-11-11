<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aProtocol;
use Throwable;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * The public agent card of a site (spec 051 A2A-SV3, A2A-P7, ADR-280).
 *
 * Every URL in it is built from the site's configured base URL. It declares
 * only what stage 3 offers: no streaming, no push notifications, no extended
 * card; one bearer scheme; the profile selector extension, not required; and
 * one skill per public publication profile.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aAgentCardBuilder
{
    private const SCHEME = 'bearer';

    public function __construct(
        private A2aSkillProfileRepository $profiles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Site $site, A2aBaseUrl $base): array
    {
        $title = $site->getConfiguration()['websiteTitle'] ?? '';

        return [
            'name'                => is_string($title) && trim($title) !== '' ? trim($title) : $site->getIdentifier(),
            'description'         => 'Skills this TYPO3 installation publishes to registered A2A clients.',
            'supportedInterfaces' => [[
                'url'             => $base->endpoint(),
                'protocolBinding' => A2aProtocol::BINDING_JSONRPC,
                'protocolVersion' => A2aProtocol::VERSION,
            ]],
            'version'      => $this->version(),
            'capabilities' => [
                'streaming'         => false,
                'pushNotifications' => false,
                'extensions'        => [[
                    'uri'         => A2aSendMessageHandler::PROFILE_EXTENSION,
                    'description' => 'Names the skill a SendMessage is for: SendMessageRequest.metadata["' . A2aSendMessageHandler::PROFILE_EXTENSION . '"] = <skill id>. Required when the client may call more than one skill.',
                    'required'    => false,
                ]],
            ],
            'securitySchemes'      => [self::SCHEME => ['httpAuthSecurityScheme' => ['scheme' => 'Bearer']]],
            'securityRequirements' => [['schemes' => [self::SCHEME => ['list' => []]]]],
            'defaultInputModes'    => ['text/plain'],
            'defaultOutputModes'   => ['text/plain'],
            'skills'               => array_map(static fn (A2aSkillProfile $profile): array => $profile->toAgentSkill(), $this->profiles->findPublic()),
        ];
    }

    private function version(): string
    {
        try {
            $version = ExtensionManagementUtility::getExtensionVersion('nr_llm');
        } catch (Throwable) {
            $version = '';
        }

        return $version !== '' ? $version : '0.0.0';
    }
}
