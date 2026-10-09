.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _api-mcp-delegation:

=============================
Delegated MCP authentication
=============================

An MCP server can select ``delegated`` authentication (ADR-217). The runtime
exchanges the explicitly mapped initiating actor's Vault credential for a
Bearer token restricted to the server's audience and requested scopes. Existing
servers retain ``legacy`` authentication after the schema update.

Server configuration
====================

Set the server's authentication mode, delegation profile, audience and
space-separated scopes. Catalogue import and connection checks require a
separate discovery credential. Tool execution requires an initiating actor;
it cannot use the discovery credential or the server's legacy credential.

Profiles and explicit identity mappings live in the installation configuration.
Store every credential in nr-vault and use its canonical reference here.
Subject, client-secret and delegated discovery references accept UUIDv7 or an
ASCII alias with 3 to 255 characters. An alias starts with a letter and contains
only letters, digits and underscores. Controls, reference wrappers and other
UUID versions are refused before contacting the identity provider. These bounds
also apply to :php:`McpSubjectCredential` and
:php:`McpDelegationProfile::$clientSecretIdentifier`.

.. code-block:: php
   :caption: Installation configuration with an explicit actor grant

   $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm']
       ['mcpDelegationProfiles']['company'] = [
           'tokenEndpoint' => 'https://identity.example.org/oauth/token',
           'clientId' => 'nr-llm',
           'allowedAudiences' => ['mcp-api'],
           'allowedScopes' => ['records.read', 'records.write'],
           'backendUsers' => [
               7 => [
                   'enabled' => true,
                   'credentialIdentifier' => $subjectCredentialUuid,
                   'allowedAudiences' => ['mcp-api'],
                   'allowedScopes' => ['records.read'],
               ],
           ],
       ];

A service actor uses an explicit ``serviceAccounts[name]`` entry with the same
grant fields. Administrator status does not create an identity mapping. The
requested audience and scopes must fit both the profile and the actor's grant.
Disabled, missing or revoked mappings refuse execution.

The default resolver reads these mappings on every new operation and renewal.
An installation can bind :php:`McpSubjectResolverInterface` to its own resolver.
The resolver receives :php:`AiActorContext`, :php:`McpDelegationProfile`, the
audience and scopes; it returns :php:`McpSubjectCredential` containing a Vault
reference and its allowed audience and scope sets. It must never infer identity
from the ambient backend user, the approver or request headers.

Vault capability
================

A public client uses the existing Vault ``BodyField`` injection for the
``subject_token``. A confidential client additionally configures
``clientSecretIdentifier`` and uses ``client_secret_post``. That combination
requires the additive
``AdditionalSecretHttpClientInterface::withAdditionalBodyField()`` capability
from `nr-vault PR 409 <https://github.com/netresearch/t3x-nr-vault/pull/409>`__.
Until that capability is installed, confidential exchange fails before the
identity provider is contacted. Existing authentication and public clients
remain usable with the current stable Vault dependency.

The extension never retrieves subject or client secrets. Vault injects them
through its secured, audited HTTP transport. Configure the Vault outbound host
policy for both the identity provider and MCP endpoint, and grant the execution
identity the Vault permissions required to use the configured references and
create, use and delete temporary credentials.

Operation lifetime
==================

Each operation opens its own :php:`McpCredentialSessionInterface`. The session
pins the actor, profile, server, audience and scopes. Exchange, initialization,
confirmation and tool calls spend the same operation deadline. Cancellation
prevents subsequent legs and is forwarded to the supported Vault transport.

The exchanged token is stored under a random expiring Vault identifier. Every
MCP leg checks freshness; an expired token triggers a fresh exchange with the
original actor. The operation deletes temporary identifiers in ``finally``;
Vault expiry bounds their lifetime after a process crash. A discovery session
keeps its configured reference and never deletes that operator-owned secret.

Queue and resume payloads carry actor identity and server references. They
carry no subject, access or refresh tokens. Approval and human-input resumes
resolve credentials afresh for the initiating actor. Authentication failures
expose stable reason codes without identity-provider response bodies or token
values.

Persisted actor binding
=======================

New queued and suspended payloads retain the initiating actor and bind it to
the run UUID within the authenticated state envelope. Backend UIDs must match
the run owner; service actors keep their name and owner zero. Approval and
input resumes validate the binding before and after the claim. Older states
without this binding retain their stored backend owner. An explicitly malformed
actor or a state copied from another new run is refused before MCP execution.
