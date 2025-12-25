<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Exception;

use RuntimeException;

/**
 * Exception thrown when an LLM configuration cannot be found.
 */
class ConfigurationNotFoundException extends RuntimeException {}
