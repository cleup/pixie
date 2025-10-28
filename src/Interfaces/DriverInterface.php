<?php

namespace Cleup\Pixie\Interfaces;

interface DriverInterface
{
    /**
     * Set upscale mode
     * 
     * @param bool $enabled
     */
    public function upscale(bool $enabled = true): self;

    /**
     * Check if upscale is enabled
     */
    public function isUpscale(): bool;

    /**
     * Load image from file path
     * 
     * @param string $path Path to image file
     */
    public function loadFromPath(string $path): void;

    /**
     * Load image from binary data
     * 
     * @param string $data Binary image data
     */
    public function loadFromString(string $data): void;

    /**
     * Load image from resource
     * 
     * @param mixed $resource GD or Imagick resource
     */
    public function loadFromResource($resource): void;

    /**
     * Save image to file
     * 
     * @param string $path Save path
     * @param int|null $quality Image quality (0-100)
     * @param string|null $format Output format
     * @return bool Success status
     */
    public function save(string $path, ?int $quality = null, ?string $format = null): bool;

    /**
     * Get image as binary string
     * 
     * @param string|null $format Output format
     * @param int|null $quality Image quality
     * @return string Image binary data
     */
    public function getString(?string $format = null, ?int $quality = null): string;

    /**
     * Get underlying resource
     * 
     * @return mixed GD or Imagick resource
     */
    public function getResource();

    /**
     * Get image width
     * 
     * @return int Image width in pixels
     */
    public function getWidth(): int;

    /**
     * Get image height
     * 
     * @return int Image height in pixels
     */
    public function getHeight(): int;

    /**
     * Get image type
     * 
     * @return string Image type (jpg, png, gif, etc.)
     */
    public function getType(): string;

    /**
     * Get image MIME type
     * 
     * @return string MIME type
     */
    public function getMimeType(): string;

    /**
     * Check if image is animated
     * 
     * @return bool True if image is animated GIF
     */
    public function isAnimated(): bool;

    /**
     * Resize image
     * 
     * @param int $width Target width
     * @param int $height Target height
     * @param bool $preserveAspectRatio Maintain aspect ratio
     */
    public function resize(int $width, int $height, bool $preserveAspectRatio = true): void;

    /**
     * Resize canvas
     * 
     * @param int $width Canvas width
     * @param int $height Canvas height
     * @param string $position Image position on canvas
     */
    public function resizeCanvas(int $width, int $height, string $position = 'center'): void;

    /**
     * Crop image
     * 
     * @param int $x Start X coordinate
     * @param int $y Start Y coordinate
     * @param int $width Crop width
     * @param int $height Crop height
     */
    public function crop(int $x, int $y, int $width, int $height): void;

    /**
     * Fit image to dimensions
     * 
     * @param int $width Target width
     * @param int $height Target height
     */
    public function fit(int $width, int $height): void;

    /**
     * Rotate image
     * 
     * @param float $angle Rotation angle in degrees
     * @param string $backgroundColor Background color hex code
     */
    public function rotate(float $angle, string $backgroundColor = '#000000'): void;

    /**
     * Flip image
     * 
     * @param string $mode Flip mode (horizontal|vertical)
     */
    public function flip(string $mode = 'horizontal'): void;

    /**
     * Apply blur filter
     * 
     * @param int $amount Blur intensity
     */
    public function blur(int $amount = 1): void;

    /**
     * Apply sharpen filter
     * 
     * @param int $amount Sharpen intensity
     */
    public function sharpen(int $amount = 1): void;

    /**
     * Adjust brightness
     * 
     * @param int $level Brightness level (-100 to 100)
     */
    public function brightness(int $level): void;

    /**
     * Adjust contrast
     * 
     * @param int $level Contrast level (-100 to 100)
     */
    public function contrast(int $level): void;

    /**
     * Apply gamma correction
     * 
     * @param float $correction Gamma correction value
     */
    public function gamma(float $correction): void;

    /**
     * Colorize image
     * 
     * @param int $red Red component (-100 to 100)
     * @param int $green Green component (-100 to 100)
     * @param int $blue Blue component (-100 to 100)
     */
    public function colorize(int $red, int $green, int $blue): void;

    /**
     * Convert to greyscale
     */
    public function greyscale(): void;

    /**
     * Apply sepia filter
     */
    public function sepia(): void;

    /**
     * Pixelate image
     * 
     * @param int $size Pixel block size
     */
    public function pixelate(int $size): void;

    /**
     * Add watermark
     * 
     * @param mixed $watermark Image path or resource
     * @param string $position Watermark position
     * @param int $offsetX Horizontal offset
     * @param int $offsetY Vertical offset
     */
    public function watermark($watermark, string $position = 'bottom-right', int $offsetX = 10, int $offsetY = 10): void;

    /**
     * Remove EXIF data
     */
    public function stripExif(): void;

    /**
     * Clean up resources
     */
    public function destroy(): void;
}
