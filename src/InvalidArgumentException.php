<?php

declare(strict_types=1);

namespace Yiisoft\Cache\File;

use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;
use RuntimeException;

final class InvalidArgumentException extends RuntimeException implements PsrInvalidArgumentException {}
