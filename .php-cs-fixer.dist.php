<?php

declare(strict_types=1);

/**
 * PHP-CS-Fixer Configuration - TYPO3 LLM Extension
 * Based on TYPO3 Coding Standards
 *
 * @see https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/CodingGuidelines/PhpArchitecture/Index.html
 */

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = (new Finder())
    ->in(__DIR__)
    ->exclude([
        '.Build',
        '.ddev',
        'Documentation-GENERATED-temp',
        'var',
    ])
    ->notPath([
        'ext_emconf.php',  // Has different formatting requirements
    ]);

return (new Config())
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        '@PER-CS:risky' => true,
        '@PHP82Migration' => true,
        '@PHP80Migration:risky' => true,
        '@PHPUnit100Migration:risky' => true,

        // TYPO3-specific rules
        'array_syntax' => ['syntax' => 'short'],
        'blank_line_after_opening_tag' => true,
        'cast_spaces' => ['space' => 'none'],
        'concat_space' => ['spacing' => 'one'],
        'declare_strict_types' => true,
        'function_typehint_space' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'native_function_casing' => true,
        'no_alias_functions' => true,
        'no_blank_lines_after_phpdoc' => true,
        'no_empty_phpdoc' => true,
        'no_empty_statement' => true,
        'no_extra_blank_lines' => true,
        'no_leading_namespace_whitespace' => true,
        'no_singleline_whitespace_before_semicolons' => true,
        'no_superfluous_phpdoc_tags' => [
            'allow_mixed' => true,
            'remove_inheritdoc' => true,
        ],
        'no_trailing_comma_in_singleline' => true,
        'no_unused_imports' => true,
        'no_whitespace_in_blank_line' => true,
        'ordered_imports' => [
            'imports_order' => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ],
        'php_unit_construct' => true,
        'php_unit_dedicate_assert' => true,
        'php_unit_mock_short_will_return' => true,
        'php_unit_set_up_tear_down_visibility' => true,
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
        'phpdoc_align' => ['align' => 'vertical'],
        'phpdoc_line_span' => ['const' => 'single', 'property' => 'single'],
        'phpdoc_no_empty_return' => true,
        'phpdoc_order' => true,
        'phpdoc_scalar' => true,
        'phpdoc_separation' => true,
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_summary' => true,
        'phpdoc_trim' => true,
        'phpdoc_types' => true,
        'phpdoc_types_order' => ['null_adjustment' => 'always_last', 'sort_algorithm' => 'none'],
        'return_type_declaration' => ['space_before' => 'none'],
        'single_line_comment_style' => ['comment_types' => ['hash']],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'whitespace_after_comma_in_array' => true,
        'yoda_style' => ['equal' => false, 'identical' => false, 'less_and_greater' => false],
    ])
    ->setFinder($finder);
