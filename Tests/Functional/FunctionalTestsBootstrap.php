<?php

declare(strict_types=1);

/*
 * Bootstrap for functional tests.
 *
 * Initializes the TYPO3 testing framework environment for functional tests.
 */

// Load composer autoloader
$autoloadFile = dirname(__DIR__, 2) . '/.Build/vendor/autoload.php';
if (!file_exists($autoloadFile)) {
    throw new RuntimeException(
        'Autoload file not found. Run "composer install" first.',
        1730000001,
    );
}
require_once $autoloadFile;

// Initialize testing framework
$testbase = new \TYPO3\TestingFramework\Core\Testbase();
$testbase->defineOriginalRootPath();
/** @phpstan-ignore constant.notFound, binaryOp.invalid */
$testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/tests');
/** @phpstan-ignore constant.notFound, binaryOp.invalid */
$testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/transient');
