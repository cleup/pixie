<?php

namespace Cleup\Pixie\Exceptions;

class ImageException extends \Exception
{
    public static function fileNotFound(string $path): self
    {
        return new self("Image file not found: {$path}");
    }

    public static function invalidImage(string $path): self
    {
        return new self("Invalid image file: {$path}");
    }

    public static function unsupportedFormat(string $format): self
    {
        return new self("Unsupported image format: {$format}");
    }

    public static function operationFailed(string $operation): self
    {
        return new self("Image operation failed: {$operation}");
    }

    public static function animationPreservationFailed(): self
    {
        return new self("Failed to preserve animation");
    }

    public static function directoryNotFound(string $path): self
    {
        return new self("The directory was not found or is not writable: {$path}");
    }
}
