<?php

namespace Cleup\Pixie\Interfaces;

use Cleup\Pixie\Optimizers\Gifsicle;

interface DriverInterface
{
    /**
     * Enable/disable gifsicle mode
     *
     * @param bool $enabled Enable or disable gifsicle mode
     * @return self
     */
    public function useGifsicle(bool $enabled = true): self;

    /**
     * Set a lossy value
     *
     * @param string $value Lossy value
     * @return self
     */
    public function setGifsicleLossy(int $value): self;

    /**
     * Get a lossy value
     *
     * @return int
     */
    public function getGifsicleLossy(): int;

    /**
     * Check if gifsicle is forced
     *
     * @return bool
     */
    public function isEnabledGifsicle(): bool;

    /**
     * Check if GIFsicle is available
     *
     * @return bool
     */
    public function isGifsicleAvailable(): bool;

    /**
     * Set custom GIFsicle path
     *
     * @param string $path Path to gifsicle executable
     * @return bool
     */
    public function setGifsiclePath(string $path): bool;

    /**
     * Get GIFsicle instance
     *
     * @return Gifsicle|null
     */
    public function getGifsicle(): ?Gifsicle;

    /**
     * Set upscale mode
     *
     * @param bool $enabled Enable or disable upscale mode
     * @return self
     */
    public function upscale(bool $enabled = true): self;

    /**
     * Check if upscale is enabled
     *
     * @return bool
     */
    public function isUpscale(): bool;

    /**
     * Load image from file path
     *
     * @param string $path File path
     * @return void
     */
    public function loadFromPath(string $path): void;

    /**
     * Load image from binary data
     *
     * @param string $data Binary image data
     * @return void
     */
    public function loadFromString(string $data): void;

    /**
     * Save image to file
     *
     * @param string $path Save path
     * @param int|null $quality Image quality
     * @param string|null $format Output format
     * @return bool Success status
     */
    public function save(
        string $path,
        ?int $quality = null,
        ?string $format = null
    ): bool;

    /**
     * Get image as binary string
     *
     * @param string|null $format Output format
     * @param int|null $quality Image quality
     * @return string Image binary data
     */
    public function getString(
        ?string $format = null,
        ?int $quality = null
    ): string;

    /**
     * Get underlying resource
     *
     * @return mixed
     */
    public function getResource();

    /**
     * Get image width
     *
     * @return int
     */
    public function getWidth(): int;

    /**
     * Get image height
     *
     * @return int
     */
    public function getHeight(): int;

    /**
     * Get image type
     *
     * @return string
     */
    public function getType(): string;

    /**
     * Get image MIME type
     *
     * @return string
     */
    public function getMimeType(): string;

    /**
     * Check if image is animated
     *
     * @return bool
     */
    public function isAnimated(): bool;

    /**
     * Calculate real number of colors based on quality
     *
     * @param int|null $quality Quality level (0-99)
     * @param int $max Maximum number of colors
     * @return int Number of colors
     */
    public function calculateRealColors(
        ?int $quality = 95,
        int $max = 256
    ): int;

    /**
     * Resize image
     *
     * @param int $width Target width
     * @param int $height Target height
     * @param bool $preserveAspectRatio Whether to preserve aspect ratio
     * @param bool $upscale Whether to allow upscaling
     * @return void
     */
    public function resize(
        int $width,
        int $height,
        bool $preserveAspectRatio = true,
        bool $upscale = false
    ): void;

    /**
     * Resize canvas
     *
     * @param int $width Canvas width
     * @param int $height Canvas height
     * @param string $position Position
     * @return void
     */
    public function resizeCanvas(
        int $width,
        int $height,
        string $position = 'center'
    ): void;

    /**
     * Crop image
     *
     * @param int $x Start X coordinate
     * @param int $y Start Y coordinate
     * @param int $width Crop width
     * @param int $height Crop height
     * @return void
     */
    public function crop(
        int $x,
        int $y,
        int $width,
        int $height
    ): void;

    /**
     * Fit image to dimensions
     *
     * @param int $width Target width
     * @param int $height Target height
     * @param bool $upscale Allow upscaling
     * @return void
     */
    public function fit(
        int $width,
        int $height,
        bool $upscale = false
    ): void;

    /**
     * Rotate image
     *
     * @param float $angle Rotation angle
     * @param string $backgroundColor Background color
     * @return void
     */
    public function rotate(
        float $angle,
        string $backgroundColor = '#000000'
    ): void;

    /**
     * Flip image
     *
     * @param string $mode Flip direction
     * @return void
     */
    public function flip(string $mode = 'horizontal'): void;

    /**
     * Apply blur filter
     *
     * @param int $amount Blur intensity
     * @return void
     */
    public function blur(int $amount = 1): void;

    /**
     * Apply sharpen filter
     *
     * @param int $amount Sharpen intensity
     * @return void
     */
    public function sharpen(int $amount = 1): void;

    /**
     * Adjust brightness
     *
     * @param int $level Brightness level
     * @return void
     */
    public function brightness(int $level): void;

    /**
     * Adjust contrast
     *
     * @param int $level Contrast level
     * @return void
     */
    public function contrast(int $level): void;

    /**
     * Apply gamma correction
     *
     * @param float $correction Gamma correction
     * @return void
     */
    public function gamma(float $correction): void;

    /**
     * Invert colors
     *
     * @return self
     */
    public function invert(): void;

    /**
     * Colorize image
     *
     * @param int $red Red component
     * @param int $green Green component
     * @param int $blue Blue component
     * @return void
     */
    public function colorize(
        int $red,
        int $green,
        int $blue
    ): void;

    /**
     * Convert to greyscale
     *
     * @return void
     */
    public function greyscale(): void;

    /**
     * Apply sepia filter
     *
     * @return void
     */
    public function sepia(): void;

    /**
     * Pixelate image
     *
     * @param int $size Pixel size
     * @return void
     */
    public function pixelate(int $size): void;

    /**
     * Add watermark
     *
     * @param mixed $watermark Image path or resource
     * @param string $position Watermark position
     * @param int $offsetX Horizontal offset
     * @param int $offsetY Vertical offset
     * @return void
     */
    public function watermark(
        $watermark,
        string $position = 'bottom-right',
        int $offsetX = 10,
        int $offsetY = 10
    ): void;

    /**
     * Remove EXIF data
     *
     * @return void
     */
    public function stripExif(): void;
}
