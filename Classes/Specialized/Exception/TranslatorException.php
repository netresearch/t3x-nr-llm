<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Exception;

/**
 * Exception thrown when a translation operation fails.
 *
 * This covers errors from specialized translators like DeepL,
 * as well as LLM-based translation failures.
 */
final class TranslatorException extends SpecializedServiceException {}
