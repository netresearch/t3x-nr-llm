<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Exception;

use RuntimeException;

/**
 * Exception thrown when a requested prompt template is not found.
 */
class PromptTemplateNotFoundException extends RuntimeException {}
