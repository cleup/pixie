<?php

namespace Cleup\Pixie;

use Cleup\Pixie\Interfaces\DriverInterface;
use Cleup\Pixie\Exceptions\ImageException;

abstract class Driver implements DriverInterface
{
    protected $width;
    protected $height;
    protected $type;
    protected $mimeType;
    protected $isAnimated = false;
    protected bool $upscale = false;

    /**
     * Set upscale mode
     * 
     * @param bool $enabled
     */
    public function upscale(bool $enabled = true): self
    {
        $this->upscale = $enabled;
        return $this;
    }

    /**
     * Check if upscale is enabled
     */
    public function isUpscale(): bool
    {
        return $this->upscale;
    }

    /**
     * Get image width
     */
    public function getWidth(): int
    {
        return $this->width;
    }

    /**
     * Get image height
     */
    public function getHeight(): int
    {
        return $this->height;
    }

    /**
     * Get image type
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get image MIME type
     */
    public function getMimeType(): string
    {
        return $this->mimeType ?: $this->getMimeTypeFromType($this->type);
    }

    /**
     * Check if image is animated
     */
    public function isAnimated(): bool
    {
        return $this->isAnimated;
    }

    /**
     * Calculate position coordinates
     */
    protected function calculatePosition(
        string $position,
        int $canvasWidth,
        int $canvasHeight,
        int $objectWidth = 0,
        int $objectHeight = 0,
        int $offsetX = 0,
        int $offsetY = 0
    ): array {
        switch ($position) {
            case 'top-left':
                return [$offsetX, $offsetY];
            case 'top':
                return [($canvasWidth - $objectWidth) / 2, $offsetY];
            case 'top-right':
                return [$canvasWidth - $objectWidth - $offsetX, $offsetY];
            case 'left':
                return [$offsetX, ($canvasHeight - $objectHeight) / 2];
            case 'center':
                return [($canvasWidth - $objectWidth) / 2, ($canvasHeight - $objectHeight) / 2];
            case 'right':
                return [$canvasWidth - $objectWidth - $offsetX, ($canvasHeight - $objectHeight) / 2];
            case 'bottom-left':
                return [$offsetX, $canvasHeight - $objectHeight - $offsetY];
            case 'bottom':
                return [($canvasWidth - $objectWidth) / 2, $canvasHeight - $objectHeight - $offsetY];
            case 'bottom-right':
                return [$canvasWidth - $objectWidth - $offsetX, $canvasHeight - $objectHeight - $offsetY];
            default:
                return [0, 0];
        }
    }

    /**
     * Get MIME type from file using finfo
     */
    protected function getMimeTypeFromFile(string $path): string
    {
        if (!file_exists($path)) {
            throw ImageException::fileNotFound($path);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $path);
        finfo_close($finfo);

        if (!$mimeType || !$this->isSupportedMimeType($mimeType)) {
            throw ImageException::unsupportedFormat($mimeType);
        }

        return $mimeType;
    }

    /**
     * Get MIME type from string data
     */
    protected function getMimeTypeFromString(string $data): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_buffer($finfo, $data);
        finfo_close($finfo);

        if (!$mimeType || !$this->isSupportedMimeType($mimeType)) {
            throw ImageException::unsupportedFormat($mimeType);
        }

        return $mimeType;
    }

    /**
     * Get image type from MIME type
     */
    protected function getTypeFromMimeType(string $mimeType): string
    {
        $mimeToType = [
            'image/jpeg' => 'jpeg',
            'image/jpg' => 'jpeg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/x-ms-bmp' => 'bmp',
        ];

        return $mimeToType[$mimeType] ?? throw ImageException::unsupportedFormat($mimeType);
    }

    /**
     * Get MIME type from image type
     */
    protected function getMimeTypeFromType(string $type): string
    {
        $typeToMime = [
            'jpeg' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
        ];

        return $typeToMime[$type] ?? 'image/jpeg';
    }

    /**
     * Check if MIME type is supported
     */
    protected function isSupportedMimeType(string $mimeType): bool
    {
        $supportedMimeTypes = [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/bmp',
            'image/x-ms-bmp',
        ];

        return in_array($mimeType, $supportedMimeTypes);
    }

    /**
     * Normalize quality value with optimized defaults
     */
    protected function normalizeQuality(?int $quality, string $format): int
    {
        if ($quality === null) {
            return match ($format) {
                'png' => 6,
                'gif' => 90,
                default => 85
            };
        }

        if ($format === 'png') {
            return max(0, min(9, $quality));
        }

        return max(0, min(100, $quality));
    }

    /**
     * Convert hex color to RGB
     */
    protected function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2))
        ];
    }

    abstract public function __destruct();
}
