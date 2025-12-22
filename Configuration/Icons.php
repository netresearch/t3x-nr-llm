<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'module-nrllm' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/module-nrllm.svg',
    ],
    'nrllm-provider-openai' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/provider-openai.svg',
    ],
    'nrllm-provider-claude' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/provider-claude.svg',
    ],
    'nrllm-provider-gemini' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/provider-gemini.svg',
    ],
];
