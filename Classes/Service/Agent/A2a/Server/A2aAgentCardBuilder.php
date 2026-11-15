<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aProtocol;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aDomainHandlerInterface;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aDomainHandlerRegistry;
use Netresearch\NrLlm\Service\Privacy\RunPrivacyLevelResolver;
use Throwable;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * The public agent card of a site (spec 051 A2A-SV3, A2A-P7, ADR-280).
 *
 * Every URL in it is built from the site's configured base URL. It declares
 * only what the installation offers: streaming when `a2a.server.streaming` is
 * on (A2A-OPS2), push notifications when `a2a.server.push` is on, no
 * extended card; one bearer scheme; the profile selector extension, not required; and
 * one skill per public publication profile that can be served now: its
 * handler is available here, and its configuration is switched on and keeps
 * a result under the current privacy policy (ADR-282). A profile that cannot is left out rather
 * than advertised and then refused.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aAgentCardBuilder
{
    private const SCHEME = 'bearer';

    public function __construct(
        private A2aSkillProfileRepository $profiles,
        private A2aDomainHandlerRegistry $handlers,
        private RunPrivacyLevelResolver $privacy,
        private LlmConfigurationRepository $configurations,
        private A2aServerSettings $settings,
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
                'streaming'         => $this->settings->streamingEnabled(),
                'pushNotifications' => $this->settings->pushEnabled(),
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
            'skills'               => array_map(static fn (A2aSkillProfile $profile): array => $profile->toAgentSkill(), array_values(array_filter($this->profiles->findPublic(), $this->servable(...)))),
        ];
    }

    private function servable(A2aSkillProfile $profile): bool
    {
        $configuration = $this->configurations->findByUid($profile->configurationUid);

        return $this->handlers->get($profile->handler) instanceof A2aDomainHandlerInterface
            && $configuration instanceof LlmConfiguration && $configuration->isActive()
            && $this->privacy->forConfigurationAnswer($profile->configurationUid)->persistsContent();
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
