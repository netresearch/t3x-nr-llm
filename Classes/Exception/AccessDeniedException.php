<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Exception;

use RuntimeException;

/**
 * Exception thrown when access to an LLM configuration is denied.
 */
class AccessDeniedException extends RuntimeException {}
