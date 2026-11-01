<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Vision;

use Netresearch\NrLlm\Domain\DTO\CapabilitySet;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * What a vision editor action adds to its run (ADR-235): the image, as a message
 * of its own, and the instructions for the fields it fills.
 *
 * The image travels in a second user message, not as a part list on the first.
 * Input guardrails read string content only, so a part list would take the
 * editor's note past them.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class VisionActionBuilder
{
    /** @var list<string> */
    private const SENDABLE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public function __construct(
        private FalStorageGate $storageGate,
        private ResourceFactory $resourceFactory,
        private ImageDownscalerInterface $downscaler,
        private VisionSettings $settings,
    ) {}

    /**
     * Whether this action can run on this configuration: at least one of its
     * fields is switched on, and the model takes images. A model that declares
     * no capabilities passes, as it does in model selection (ADR-138); so does
     * a configuration that selects its model by criteria.
     */
    public function isAvailable(EditorAction $action, LlmConfiguration $configuration): bool
    {
        if ($this->enabledFields($action) === []) {
            return false;
        }

        $capabilities = $configuration->getLlmModel()?->getCapabilitySet();

        return !$capabilities instanceof CapabilitySet || $capabilities->isEmpty() || $capabilities->has(ModelCapability::VISION);
    }

    /**
     * The message that carries the image of file `$fileUid`, or null when it
     * may not or cannot be sent. Null for every reason alike: a file that does
     * not exist, lies outside the user's mounts, lies in an excluded folder, or
     * cannot be scaled. A caller must not be able to tell which, because a
     * caller's answer reaches the person who named the uid.
     *
     * @return array{role: string, content: list<array<string, mixed>>}|null
     */
    public function imageMessage(int $fileUid, BackendUserAuthentication $user): ?array
    {
        $file = $this->sendableFile($fileUid, $user);
        if (!$file instanceof File) {
            return null;
        }

        $dataUri = $this->downscaler->dataUri($file, $this->settings->maxImageEdge());
        if ($dataUri === null) {
            return null;
        }

        return [
            'role'    => 'user',
            'content' => [['type' => 'image_url', 'image_url' => ['url' => $dataUri]]],
        ];
    }

    /**
     * The lines the prompt gains for the fields this action may fill. The file
     * name is left out on purpose: it is what an alternative text must not be
     * built from.
     *
     * @return list<string>
     */
    public function promptLines(EditorAction $action, ?BackendUserAuthentication $user): array
    {
        $lines = ['The image of this file follows in the next message. Describe only what it shows. If it cannot be read, say so and do not call the tool.'];

        $language = $user?->user['lang'] ?? '';
        if (is_string($language) && $language !== '' && $language !== 'default') {
            $lines[] = sprintf('Write in the language with the code "%s".', $language);
        }

        foreach ($this->enabledFields($action) as $field) {
            $lines[] = sprintf(
                'Field "%s": %s%s',
                $field->name,
                $field->prompt,
                $field->maxLength > 0 ? sprintf(' Use at most %d characters.', $field->targetLength()) : '',
            );
        }

        return $lines;
    }

    /**
     * @return list<VisionField>
     */
    private function enabledFields(EditorAction $action): array
    {
        $fields = [];
        foreach ($action->visionFields as $name) {
            $field = $this->settings->field($name);
            if ($field->enabled) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    private function sendableFile(int $fileUid, BackendUserAuthentication $user): ?File
    {
        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
            if ($file->isMissing() || !in_array(strtolower($file->getExtension()), self::SENDABLE_EXTENSIONS, true)) {
                return null;
            }

            if (!$this->storageGate->isFileAccessible($user, $file->getStorage()->getUid(), $file->getIdentifier())) {
                return null;
            }

            return $this->settings->isExcluded($file->getCombinedIdentifier()) ? null : $file;
        } catch (Throwable) {
            // A uid no file carries is an exception here and a null to the
            // caller, like every other reason.
            return null;
        }
    }
}
