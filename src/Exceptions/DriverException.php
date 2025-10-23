<?php

namespace Cleup\Pixie\Exceptions;

class DriverException extends \Exception
{
    public static function notAvailable(string $driver): self
    {
        return new self("Image driver not available: {$driver}");
    }

    public static function methodNotSupported(string $method): self
    {
        return new self("Method not supported by current driver: {$method}");
    }
}
