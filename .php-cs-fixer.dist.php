<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * PHP-CS-Fixer Configuration - TYPO3 LLM Extension.
 *
 * The rule set, the header and the finder come from the shared factory of
 * netresearch/typo3-ci-workflows. The extra rules below are the ones this
 * repository enforced before that the shared set leaves off; each of them
 * only adds a constraint, none relaxes a shared one.
 */

use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$createConfig = require __DIR__ . '/.Build/vendor/netresearch/typo3-ci-workflows/config/php-cs-fixer/config.php';

return $createConfig(
    "Copyright (c) 2025-2026 Netresearch DTT GmbH\nSPDX-License-Identifier: GPL-2.0-or-later",
    __DIR__,
    [
        // From @PER-CS:risky and @PHP8x0Migration:risky
        'combine_nested_dirname'               => true,
        'get_class_to_class_keyword'           => true,
        'implode_call'                         => true,
        'modernize_strpos'                     => true,
        'no_php4_constructor'                  => true,
        'no_trailing_whitespace_in_string'     => true,
        'no_unneeded_final_method'             => true,
        'no_unreachable_default_argument_value' => true,
        'non_printable_character'              => true,
        'pow_to_exponentiation'                => true,
        'random_api_migration'                 => ['replacements' => [
            'mt_getrandmax' => 'getrandmax',
            'mt_rand'       => 'random_int',
            'mt_srand'      => 'srand',
            'rand'          => 'random_int',
        ]],
        'use_arrow_functions'                  => true,
        'void_return'                          => true,

        // From @PHPUnit10x0Migration:risky, with the targets that set uses
        'php_unit_assert_new_names'              => true,
        'php_unit_data_provider_static'          => ['force' => true],
        'php_unit_dedicate_assert_internal_type' => ['target' => '7.5'],
        'php_unit_expectation'                   => ['target' => '8.4'],
        'php_unit_mock'                          => ['target' => '5.5'],
        'php_unit_namespaced'                    => ['target' => '6.0'],
        'php_unit_no_expectation_annotation'     => ['target' => '4.3'],

        // Repository rules the shared set does not enable
        'no_superfluous_phpdoc_tags' => [
            'allow_mixed'       => true,
            'remove_inheritdoc' => true,
        ],
        'php_unit_construct'                     => true,
        'php_unit_dedicate_assert'               => true,
        'php_unit_mock_short_will_return'        => true,
        'php_unit_set_up_tear_down_visibility'   => true,
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
        'phpdoc_line_span'                       => ['const' => 'single', 'property' => 'single'],
        'phpdoc_no_empty_return'                 => true,

        // The shared set removes blank lines only between imports; this
        // repository also removed consecutive blank lines everywhere.
        'no_extra_blank_lines' => ['tokens' => ['extra', 'use']],
    ],
    // Rendered documentation (docs.typo3.org render container output).
    ['Documentation-GENERATED-temp'],
)->setParallelConfig(ParallelConfigFactory::detect());
