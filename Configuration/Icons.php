<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    // Module icons
    'module-nrllm' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/module-nrllm.svg',
    ],
    'module-nrllm-provider' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/Provider.svg',
    ],
    'module-nrllm-model' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/Model.svg',
    ],
    'module-nrllm-wizard' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/Wizard.svg',
    ],

    // Provider type icons
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
    'nrllm-provider-openrouter' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/provider-openrouter.svg',
    ],
    'nrllm-provider-mistral' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/provider-mistral.svg',
    ],
    'nrllm-provider-groq' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/provider-groq.svg',
    ],
];
