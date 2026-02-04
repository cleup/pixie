<?php

namespace Cleup\Pixie;

use Cleup\Pixie\Interfaces\DriverInterface;
use Cleup\Pixie\Exceptions\ImageException;
use Cleup\Pixie\Optimizers\Gifsicle;

abstract class Driver implements DriverInterface
{
    protected $width;
    protected $height;
    protected $type;
    protected $mimeType;
    protected $isAnimated = false;
    protected bool $upscale = false;
    protected bool $isGifsicle = false;
    protected ?Gifsicle $gifsicle = null;

    public function __construct()
    {
        // Initialize gifsicle if available
        if ($this->checkGifsicleAvailability()) {
            try {
                $this->gifsicle = new Gifsicle();
            } catch (\RuntimeException $e) {
                $this->gifsicle = null;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function upscale(bool $enabled = true): self
    {
        $this->upscale = $enabled;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function isUpscale(): bool
    {
        return $this->upscale;
    }

    /**
     * {@inheritdoc}
     */
    public function useGifsicle(bool $enabled = true): self
    {
        $this->isGifsicle = $enabled;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setGifsicleLossy(int $value): self
    {
        if ($value > 0)
            $this->gifsicle->setLossy($value);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getGifsicleLossy(): int
    {
        return $this->gifsicle->getLossy();
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabledGifsicle(): bool
    {
        return $this->isGifsicle;
    }

    /**
     * {@inheritdoc}
     */
    public function isGifsicleAvailable(): bool
    {
        return $this->gifsicle !== null &&
            $this->gifsicle->isInstalled();
    }

    /**
     * {@inheritdoc}
     */
    public function setGifsiclePath(string $path): bool
    {
        try {
            $this->gifsicle = new Gifsicle(
                null,
                null,
                $path
            );

            return true;
        } catch (\RuntimeException $e) {
            $this->gifsicle = null;

            return false;
        }
    }

    /**
     * Check if gifsicle is available
     *
     * @return bool
     */
    protected function checkGifsicleAvailability(): bool
    {
        return (new Gifsicle())->isInstalled();
    }

    /**
     * {@inheritdoc}
     */
    public function getGifsicle(): ?Gifsicle
    {
        return $this->gifsicle;
    }

    /**
     * Optimize GIF using gifsicle if available
     *
     * @param string $inputFile Input file path
     * @param string $outputFile Output file path
     * @param int|null $quality Quality level
     * @return bool
     */
    protected function optimizeGifWithGifsicle(
        string $inputFile,
        string $outputFile,
        ?int $quality = null
    ): bool {
        if (!$this->gifsicle) {
            return false;
        }

        $quality = $this->normalizeQuality($quality, 'gif');

        return $this->gifsicle->optimizeWithQuality(
            $inputFile,
            $outputFile,
            $quality
        );
    }

    /**
     * Save GIF using optimal method
     *
     * @param string $path Output file path
     * @param int|null $quality Quality level
     * @return bool
     */
    protected function saveGifOptimally(string $path, ?int $quality = null): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $useGifsicle = $this->isGifsicle || (
            $this->gifsicle !== null && $this->gifsicle->isInstalled()
        );

        if (!$useGifsicle) {
            return $this->saveGifDirect($path, $quality);
        }

        $tempInput = tempnam(sys_get_temp_dir(), 'gif_input_');
        if (!$tempInput || !$this->saveGifDirect($tempInput, $quality)) {
            if ($tempInput && file_exists($tempInput)) {
                @unlink($tempInput);
            }

            return $this->saveGifDirect($path, $quality);
        }

        try {
            $result = $this->optimizeGifWithGifsicle(
                $tempInput,
                $path,
                $quality
            );
        } catch (\Exception $e) {
            $result = false;
        }

        if (file_exists($tempInput)) {
            @unlink($tempInput);
        }

        return $result ?: $this->saveGifDirect($path, $quality);
    }

    /**
     * Direct GIF save without gifsicle
     *
     * @param string $path Output file path
     * @param int|null $quality Quality level
     * @return bool
     */
    abstract protected function saveGifDirect(
        string $path,
        ?int $quality = null
    ): bool;

    /**
     * {@inheritdoc}
     */
    public function getWidth(): int
    {
        return $this->width;
    }

    /**
     * {@inheritdoc}
     */
    public function getHeight(): int
    {
        return $this->height;
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * {@inheritdoc}
     */
    public function getMimeType(): string
    {
        return $this->mimeType ?: $this->getMimeTypeFromType($this->type);
    }

    /**
     * {@inheritdoc}
     */
    public function isAnimated(): bool
    {
        return $this->isAnimated;
    }

    /**
     * Calculate position coordinates
     *
     * @param string $position Position name
     * @param int $canvasWidth Canvas width
     * @param int $canvasHeight Canvas height
     * @param int $objectWidth Object width
     * @param int $objectHeight Object height
     * @param int $offsetX Horizontal offset
     * @param int $offsetY Vertical offset
     * @return array
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
     *
     * @param string $path File path
     * @return string
     * @throws ImageException When file not found or unsupported format
     */
    protected function getMimeTypeFromFile(string $path): string
    {
        if (!file_exists($path)) {
            throw ImageException::fileNotFound($path);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $path);

        if (!$mimeType || !$this->isSupportedMimeType($mimeType)) {
            throw ImageException::unsupportedFormat($mimeType);
        }

        return $mimeType;
    }

    /**
     * Get MIME type from string data
     *
     * @param string $data Image data
     * @return string
     * @throws ImageException When unsupported format
     */
    protected function getMimeTypeFromString(string $data): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_buffer($finfo, $data);

        if (!$mimeType || !$this->isSupportedMimeType($mimeType)) {
            throw ImageException::unsupportedFormat($mimeType);
        }

        return $mimeType;
    }

    /**
     * Get image type from MIME type
     *
     * @param string $mimeType MIME type
     * @return string
     * @throws ImageException When unsupported format
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
     *
     * @param string $type Image type
     * @return string
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
     *
     * @param string $mimeType MIME type to check
     * @return bool
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
     *
     * @param int|null $quality Quality value
     * @param string $format Image format
     * @return int
     */
    protected function normalizeQuality(?int $quality, string $format): int
    {
        if ($quality === null) {
            return match ($format) {
                'png' => 90,
                'gif' => 95,
                default => 95
            };
        }

        return max(0, min(100, $quality));
    }

    /**
     * Convert hex color to RGB
     *
     * @param string $hex Hex color code
     * @return array
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

    /**
     * {@inheritdoc}
     */
    public function calculateRealColors(?int $quality = 95, int $max = 256): int
    {
        return (int)($max * ($quality ?? 95)) / 100;
    }

    /**
     * Convert quality to PNG compression level
     *
     * @param int $quality Quality level
     * @return int
     */
    protected function getPngQuality(int $quality): int
    {
        return (int) ((100 - $quality) / 100 * 9);
    }

    /**
     * Create temporary file path
     *
     * @return string
     */
    protected function createTempFilePath(): string
    {
        return tempnam(sys_get_temp_dir(), 'gif-temp-');
    }

    /**
     * Get temporary file content and optionally delete file
     *
     * @param string $path File path
     * @param bool $deleteAfter Delete file after reading
     * @return string
     */
    protected function getTempFile(string $path, bool $deleteAfter = true): string
    {
        $data = file_get_contents($path);

        if ($deleteAfter) {
            @unlink($path);
        }

        ob_end_clean();

        return $data;
    }

    /**
     * Destructor
     */
    abstract public function __destruct();
}
