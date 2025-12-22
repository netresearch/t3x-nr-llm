<?php

declare(strict_types=1);

use Netresearch\NrLlm\Controller\Backend\LlmModuleController;

return [
    'nrllm_test' => [
        'path' => '/nrllm/test',
        'target' => LlmModuleController::class . '::executeTestAction',
    ],
];
