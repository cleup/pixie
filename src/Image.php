<?php

namespace Cleup\Pixie;

use Cleup\Pixie\Interfaces\ImageInterface;
use Cleup\Pixie\Interfaces\DriverInterface;
use Cleup\Pixie\Drivers\GDDriver;
use Cleup\Pixie\Drivers\ImagickDriver;
use Cleup\Pixie\Exceptions\DriverException;
use Cleup\Pixie\Exceptions\InvalidConfigException;

/**
 * Main image manipulation class
 * Provides fluent interface for image operations with quality preservation
 */
class Image implements ImageInterface
{
    private DriverInterface $driver;
    private string $driverName;

    /**
     * Constructor
     * @param string $driver Driver name (auto|gd|imagick)
     */
    public function __construct(string $driver = 'auto')
    {
        $this->driverName = $this->resolveDriver($driver);
        $this->driver = $this->createDriver($this->driverName);
    }

    /**
     * {@inheritdoc}
     */
    public function load(string $path): self
    {
        $this->driver->loadFromPath($path);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function loadFromString(string $data): self
    {
        $this->driver->loadFromString($data);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function save(string $path, ?int $quality = null, ?string $format = null): bool
    {
        return $this->driver->save($path, $quality, $format);
    }

    /**
     * {@inheritdoc}
     */
    public function toString(?string $format = null, ?int $quality = null): string
    {
        return $this->driver->getString($format, $quality);
    }

    /**
     * {@inheritdoc}
     */
    public function output(?string $format = null, ?int $quality = null): void
    {
        $format = $format ?: $this->getType();
        $data = $this->toString($format, $quality);

        header('Content-Type: ' . $this->getMimeType($format));
        header('Content-Length: ' . strlen($data));
        echo $data;
    }

    /**
     * {@inheritdoc}
     */
    public function getWidth(): int
    {
        return $this->driver->getWidth();
    }

    /**
     * {@inheritdoc}
     */
    public function getHeight(): int
    {
        return $this->driver->getHeight();
    }

    /**
     * {@inheritdoc}
     */
    public function getAspectRatio(): float
    {
        return $this->getWidth() / $this->getHeight();
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return $this->driver->getType();
    }

    /**
     * {@inheritdoc}
     */
    public function isAnimated(): bool
    {
        return $this->driver->isAnimated();
    }

    /**
     * {@inheritdoc}
     */
    public function resize(int $width, ?int $height = null, bool $preserveAspectRatio = true): self
    {
        $height = $height ?? (int) round($width / $this->getAspectRatio());
        $this->driver->resize($width, $height, $preserveAspectRatio);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function resizeToWidth(int $width, bool $upscale = false): self
    {
        if (!$upscale && $width > $this->getWidth()) {
            return $this;
        }

        $height = (int) round($width / $this->getAspectRatio());
        $this->driver->resize($width, $height, false);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function resizeToHeight(int $height, bool $upscale = false): self
    {
        if (!$upscale && $height > $this->getHeight()) {
            return $this;
        }

        $width = (int) round($height * $this->getAspectRatio());
        $this->driver->resize($width, $height, false);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function resizeToFit(int $maxWidth, int $maxHeight, bool $upscale = false): self
    {
        $ratio = $this->getAspectRatio();
        
        $width = $maxWidth;
        $height = (int) round($maxWidth / $ratio);
        
        if ($height > $maxHeight) {
            $height = $maxHeight;
            $width = (int) round($maxHeight * $ratio);
        }

        if (!$upscale) {
            $width = min($width, $this->getWidth());
            $height = min($height, $this->getHeight());
        }

        $this->driver->resize($width, $height, false);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function resizeToFill(int $width, int $height, bool $upscale = false): self
    {
        $this->driver->fit($width, $height, $upscale);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function scale(float $ratio): self
    {
        $width = (int) round($this->getWidth() * $ratio);
        $height = (int) round($this->getHeight() * $ratio);
        $this->driver->resize($width, $height, false);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function crop(int $x, int $y, int $width, int $height): self
    {
        $this->driver->crop($x, $y, $width, $height);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function fit(int $width, int $height, bool $upscale = false): self
    {
        $this->driver->fit($width, $height, $upscale);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function rotate(float $angle, string $backgroundColor = '#000000'): self
    {
        $this->driver->rotate($angle, $backgroundColor);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function flip(string $mode = 'horizontal'): self
    {
        $this->driver->flip($mode);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function flipHorizontal(): self
    {
        return $this->flip('horizontal');
    }

    /**
     * {@inheritdoc}
     */
    public function flipVertical(): self
    {
        return $this->flip('vertical');
    }

    /**
     * {@inheritdoc}
     */
    public function blur(int $amount = 1): self
    {
        $this->driver->blur($amount);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function sharpen(int $amount = 1): self
    {
        $this->driver->sharpen($amount);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function brightness(int $level): self
    {
        $this->driver->brightness($level);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function contrast(int $level): self
    {
        $this->driver->contrast($level);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function gamma(float $correction): self
    {
        $this->driver->gamma($correction);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function colorize(int $red, int $green, int $blue): self
    {
        $this->driver->colorize($red, $green, $blue);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function greyscale(): self
    {
        $this->driver->greyscale();
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function sepia(): self
    {
        $this->driver->sepia();
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function pixelate(int $size): self
    {
        $this->driver->pixelate($size);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function invert(): self
    {
        if ($this->driverName === 'imagick') {
            $this->getDriver()->getResource()->negateImage(false);
        } else {
            imagefilter($this->getDriver()->getResource(), IMG_FILTER_NEGATE);
        }
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function watermark($watermark, string $position = 'bottom-right', int $offsetX = 10, int $offsetY = 10): self
    {
        $this->driver->watermark($watermark, $position, $offsetX, $offsetY);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function stripExif(): self
    {
        $this->driver->stripExif();
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getDriver(): DriverInterface
    {
        return $this->driver;
    }

    /**
     * Resolve driver name
     */
    private function resolveDriver(string $driver): string
    {
        if ($driver === 'auto') {
            if (extension_loaded('imagick')) {
                return 'imagick';
            } elseif (extension_loaded('gd')) {
                return 'gd';
            } else {
                throw DriverException::notAvailable('GD or Imagick');
            }
        }

        if (!in_array($driver, ['gd', 'imagick'])) {
            throw InvalidConfigException::invalidDriver($driver);
        }

        if ($driver === 'imagick' && !extension_loaded('imagick')) {
            throw DriverException::notAvailable('Imagick');
        }

        if ($driver === 'gd' && !extension_loaded('gd')) {
            throw DriverException::notAvailable('GD');
        }

        return $driver;
    }

    /**
     * Create driver instance
     */
    private function createDriver(string $driverName): DriverInterface
    {
        return match ($driverName) {
            'gd' => new GDDriver(),
            'imagick' => new ImagickDriver(),
            default => throw InvalidConfigException::invalidDriver($driverName),
        };
    }

    /**
     * Get MIME type for format
     */
    private function getMimeType(string $format): string
    {
        return match (strtolower($format)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            default => 'application/octet-stream',
        };
    }
}