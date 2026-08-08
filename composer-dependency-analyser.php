<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    ->disableComposerAutoloadPathScan()
    ->setFileExtensions(['php'])
    ->addPathToScan(__DIR__ . '/config', isDev: false)
    ->addPathToScan(__DIR__ . '/src', isDev: false)
    ->addPathToScan(__DIR__ . '/tests', isDev: true)
    // Loaded via `require_once` (not PSR-4 autoloaded), so the analyser cannot resolve it.
    ->ignoreUnknownClasses(['Yiisoft\Cache\File\MockHelper'])
    // Both extensions are used conditionally (guarded by function_exists()/extension_loaded()),
    // so they are optional at runtime and intentionally not declared as hard dependencies.
    ->ignoreErrorsOnExtension('ext-posix', [ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnExtension('ext-pcntl', [ErrorType::SHADOW_DEPENDENCY])
    // Used only as an optional DI factory type hint in config/di.php, not a hard runtime dependency.
    ->ignoreErrorsOnPackage('yiisoft/aliases', [ErrorType::DEV_DEPENDENCY_IN_PROD]);
