<?php

declare(strict_types=1);

namespace Yiisoft\Cache\File;

/**
 * Mock for the time() function
 */
function time(): int
{
    return MockHelper::$time ?? \time();
}

final class MockHelper
{
    /**
     * @var int|null The virtual time to be returned by mocked time() function. null means normal time() behavior.
     */
    public static ?int $time = null;
}
