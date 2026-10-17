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

function random_int(int $min, int $max): int
{
    return MockHelper::$randomInt ?? \random_int($min, $max);
}

final class MockHelper
{
    public static ?int $randomInt = null;

    /**
     * @var int|null The virtual time to be returned by mocked time() function. null means normal time() behavior.
     */
    public static ?int $time = null;
}
