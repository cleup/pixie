<?php

namespace Cleup\Pixie\Exceptions;

class InvalidConfigException extends \Exception
{
    public static function invalidDriver(string $driver): self
    {
        return new self("Invalid image driver: {$driver}");
    }
}
