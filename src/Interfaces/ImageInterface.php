<?php

namespace Cleup\Pixie\Interfaces;

interface ImageInterface
{
    /**
     * Constructor
     * 
     * @param string $driver Driver name (auto|gd|imagick)
     */
    public function __construct(string $driver = 'auto');

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
     * Load image from file
     * 
     * @param string $path Image file path
     * @return self
     */
    public function load(string $path): self;

    /**
     * Load image from binary data
     * 
     * @param string $data Binary image data
     * @return self
     */
    public function loadFromString(string $data): self;

    /**
     * Save image to file
     * 
     * @param string $path Save path
     * @param int|null $quality Image quality
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
    public function toString(?string $format = null, ?int $quality = null): string;

    /**
     * Output image to browser
     * 
     * @param string|null $format Output format
     * @param int|null $quality Image quality
     */
    public function output(?string $format = null, ?int $quality = null): void;

    /**
     * Get image width
     * 
     * @return int Image width
     */
    public function getWidth(): int;

    /**
     * Get image height
     * 
     * @return int Image height
     */
    public function getHeight(): int;

    /**
     * Get aspect ratio
     * 
     * @return float Width/height ratio
     */
    public function getAspectRatio(): float;

    /**
     * Get image type
     * 
     * @return string Image type
     */
    public function getType(): string;

    /**
     * Get image MIME type
     * 
     * @return string MIME type
     */
    public function getMimeType(): string;

    /**
     * Check if animated
     * 
     * @return bool True for animated GIF
     */
    public function isAnimated(): bool;

    /**
     * Resize image
     *
     * @param int $width Target width
     * @param int|null $height Target height (optional, calculated from aspect ratio if not provided)
     * @param bool $preserveAspectRatio Whether to preserve aspect ratio
     * @param bool $upscale Whether to allow upscaling (increasing image size)
     */
    public function resize(int $width, ?int $height = null, bool $preserveAspectRatio = true, bool $upscale = false): self;

    /**
     * Resize to specific width
     * 
     * @param int $width Target width
     * @param bool $upscale Allow upscaling
     * @return self
     */
    public function resizeToWidth(int $width, bool $upscale = false): self;

    /**
     * Resize to specific height
     * 
     * @param int $height Target height
     * @param bool $upscale Allow upscaling
     * @return self
     */
    public function resizeToHeight(int $height, bool $upscale = false): self;

    /**
     * Resize to fit within dimensions
     * 
     * @param int $maxWidth Maximum width
     * @param int $maxHeight Maximum height
     * @param bool $upscale Allow upscaling
     * @return self
     */
    public function resizeToFit(int $maxWidth, int $maxHeight, bool $upscale = false): self;

    /**
     * Resize to fill dimensions
     * 
     * @param int $width Target width
     * @param int $height Target height
     * @param bool $upscale Allow upscaling
     * @return self
     */
    public function resizeToFill(int $width, int $height, bool $upscale = false): self;

    /**
     * Scale image by ratio
     *
     * @param float $ratio Scale ratio
     * @param bool $upscale Whether to allow upscaling
     */
    public function scale(float $ratio, bool $upscale = false): self;

    /**
     * Crop image
     * 
     * @param int $x Start X coordinate
     * @param int $y Start Y coordinate
     * @param int $width Crop width
     * @param int $height Crop height
     * @return self
     */
    public function crop(int $x, int $y, int $width, int $height): self;

    /**
     * Fit image to dimensions
     * 
     * @param int $width Target width
     * @param int $height Target height
     * @param bool $upscale Allow upscaling
     * @return self
     */
    public function fit(int $width, int $height, bool $upscale = false): self;

    /**
     * Rotate image
     * 
     * @param float $angle Rotation angle
     * @param string $backgroundColor Background color
     * @return self
     */
    public function rotate(float $angle, string $backgroundColor = '#000000'): self;

    /**
     * Flip image
     * 
     * @param string $mode Flip direction
     * @return self
     */
    public function flip(string $mode = 'horizontal'): self;

    /**
     * Flip horizontally
     * 
     * @return self
     */
    public function flipHorizontal(): self;

    /**
     * Flip vertically
     * 
     * @return self
     */
    public function flipVertical(): self;

    /**
     * Apply blur filter
     * 
     * @param int $amount Blur intensity
     * @return self
     */
    public function blur(int $amount = 1): self;

    /**
     * Apply sharpen filter
     * 
     * @param int $amount Sharpen intensity
     * @return self
     */
    public function sharpen(int $amount = 1): self;

    /**
     * Adjust brightness
     * 
     * @param int $level Brightness level
     * @return self
     */
    public function brightness(int $level): self;

    /**
     * Adjust contrast
     * 
     * @param int $level Contrast level
     * @return self
     */
    public function contrast(int $level): self;

    /**
     * Apply gamma correction
     * 
     * @param float $correction Gamma correction
     * @return self
     */
    public function gamma(float $correction): self;

    /**
     * Colorize image
     * 
     * @param int $red Red component
     * @param int $green Green component
     * @param int $blue Blue component
     * @return self
     */
    public function colorize(int $red, int $green, int $blue): self;

    /**
     * Convert to greyscale
     * 
     * @return self
     */
    public function greyscale(): self;

    /**
     * Apply sepia filter
     * 
     * @return self
     */
    public function sepia(): self;

    /**
     * Pixelate image
     * 
     * @param int $size Pixel size
     * @return self
     */
    public function pixelate(int $size): self;

    /**
     * Invert colors
     * 
     * @return self
     */
    public function invert(): self;

    /**
     * Add watermark
     * 
     * @param mixed $watermark Image path or resource
     * @param string $position Watermark position
     * @param int $offsetX Horizontal offset
     * @param int $offsetY Vertical offset
     * @return self
     */
    public function watermark($watermark, string $position = 'bottom-right', int $offsetX = 10, int $offsetY = 10): self;

    /**
     * Remove EXIF data
     * 
     * @return self
     */
    public function stripExif(): self;

    /**
     * Get driver instance
     * 
     * @return DriverInterface Driver instance
     */
    public function getDriver(): DriverInterface;
}
