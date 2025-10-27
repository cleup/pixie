<?php

namespace Cleup\Pixie\Drivers;

use Cleup\Pixie\Interfaces\DriverInterface;
use Cleup\Pixie\Exceptions\ImageException;
use GdImage;

class GDDriver implements DriverInterface
{
    private $image;
    private $width;
    private $height;
    private $type;
    private $mimeType;

    /**
     * {@inheritdoc}
     */
    public function loadFromPath(string $path): void
    {
        if (!file_exists($path)) {
            throw ImageException::fileNotFound($path);
        }

        $mimeType = $this->getMimeTypeFromFile($path);
        $this->mimeType = $mimeType;
        $this->type = $this->getTypeFromMimeType($mimeType);

        switch ($this->type) {
            case 'gif':
                $this->image = imagecreatefromgif($path);
                break;
            case 'jpeg':
            case 'jpg':
                $this->image = imagecreatefromjpeg($path);
                break;
            case 'png':
                $this->image = imagecreatefrompng($path);
                break;
            case 'webp':
                $this->image = imagecreatefromwebp($path);
                break;
            case 'bmp':
                $this->image = imagecreatefrombmp($path);
                break;
            default:
                throw ImageException::unsupportedFormat($this->type);
        }

        if (!$this->image) {
            throw ImageException::invalidImage($path);
        }

        $this->width = imagesx($this->image);
        $this->height = imagesy($this->image);
        $this->preserveTransparency();
    }

    /**
     * {@inheritdoc}
     */
    public function loadFromString(string $data): void
    {
        $this->image = imagecreatefromstring($data);

        if (!$this->image) {
            throw ImageException::invalidImage('string data');
        }

        $this->width = imagesx($this->image);
        $this->height = imagesy($this->image);
        $this->mimeType = $this->getMimeTypeFromString($data);
        $this->type = $this->getTypeFromMimeType($this->mimeType);

        $this->preserveTransparency();
    }

    /**
     * {@inheritdoc}
     */
    public function loadFromResource($resource): void
    {
        if (!is_resource($resource) || get_resource_type($resource) !== 'gd') {
            throw new \InvalidArgumentException('Invalid GD resource');
        }

        $this->image = $resource;

        if ($this->image instanceof GdImage) {
            $this->width = imagesx($this->image);
            $this->height = imagesy($this->image);
        }

        $this->mimeType = 'image/jpeg';
        $this->type = 'jpeg';
        $this->preserveTransparency();
    }

    /**
     * {@inheritdoc}
     */
    public function save(string $path, ?int $quality = null, ?string $format = null): bool
    {
        $format = $format ?: $this->type;
        $format = strtolower($format);
        $quality = $this->normalizeQuality($quality, $format);
        $this->preserveTransparency();

        switch ($format) {
            case 'jpg':
            case 'jpeg':
                return imagejpeg($this->image, $path, $quality);
            case 'png':
                return imagepng($this->image, $path, $this->getPngQuality($quality));
            case 'gif':
                return imagegif($this->image, $path);
            case 'webp':
                return imagewebp($this->image, $path, $quality);
            case 'bmp':
                return imagebmp($this->image, $path);
            default:
                throw ImageException::unsupportedFormat($format);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getString(?string $format = null, ?int $quality = null): string
    {
        $format = $format ?: $this->type;
        $quality = $this->normalizeQuality($quality, $format);
        $this->preserveTransparency();

        ob_start();

        switch ($format) {
            case 'jpg':
            case 'jpeg':
                imagejpeg($this->image, null, $quality);
                break;
            case 'png':
                imagepng($this->image, null, $this->getPngQuality($quality));
                break;
            case 'gif':
                imagegif($this->image, null);
                break;
            case 'webp':
                imagewebp($this->image, null, $quality);
                break;
            case 'bmp':
                imagebmp($this->image, null);
                break;
            default:
                throw ImageException::unsupportedFormat($format);
        }

        return ob_get_clean();
    }

    /**
     * {@inheritdoc}
     */
    public function getResource()
    {
        return $this->image;
    }

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
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function resize(int $width, int $height, bool $preserveAspectRatio = true, bool $upscale = false): void
    {
        if (!$upscale) {
            $width = min($width, $this->width);
            $height = min($height, $this->height);
        }

        if ($preserveAspectRatio) {
            $ratio = min($width / $this->width, $height / $this->height);
            $newWidth = (int) round($this->width * $ratio);
            $newHeight = (int) round($this->height * $ratio);
        } else {
            $newWidth = $width;
            $newHeight = $height;
        }

        $newImage = imagecreatetruecolor($newWidth, $newHeight);
        $this->preserveTransparency($newImage);

        imagecopyresampled(
            $newImage,
            $this->image,
            0,
            0,
            0,
            0,
            $newWidth,
            $newHeight,
            $this->width,
            $this->height
        );

        imagedestroy($this->image);
        $this->image = $newImage;
        $this->width = $newWidth;
        $this->height = $newHeight;
    }

    /**
     * {@inheritdoc}
     */
    public function resizeCanvas(int $width, int $height, string $position = 'center'): void
    {
        $newImage = imagecreatetruecolor($width, $height);
        $this->preserveTransparency($newImage, true);

        list($x, $y) = $this->calculatePosition($position, $width, $height);
        imagecopy(
            $newImage,
            $this->image,
            $x,
            $y,
            0,
            0,
            $this->width,
            $this->height
        );
        imagedestroy($this->image);

        $this->image = $newImage;
        $this->width = $width;
        $this->height = $height;
    }

    /**
     * {@inheritdoc}
     */
    public function crop(int $x, int $y, int $width, int $height): void
    {
        $newImage = imagecreatetruecolor($width, $height);
        $this->preserveTransparency($newImage);

        imagecopy(
            $newImage,
            $this->image,
            0,
            0,
            $x,
            $y,
            $width,
            $height
        );

        imagedestroy($this->image);
        $this->image = $newImage;
        $this->width = $width;
        $this->height = $height;
    }

    /**
     * {@inheritdoc}
     */
    public function fit(int $width, int $height, bool $upscale = false): void
    {
        $ratio = $this->width / $this->height;
        $targetRatio = $width / $height;

        if ($ratio > $targetRatio) {
            $newHeight = $height;
            $newWidth = (int) round($height * $ratio);
        } else {
            $newWidth = $width;
            $newHeight = (int) round($width / $ratio);
        }

        if (!$upscale) {
            $newWidth = min($newWidth, $this->width);
            $newHeight = min($newHeight, $this->height);
        }

        $this->resize($newWidth, $newHeight, false, $upscale);
        $x = (int) max(0, ($newWidth - $width) / 2);
        $y = (int) max(0, ($newHeight - $height) / 2);
        $this->crop($x, $y, $width, $height);
    }

    /**
     * {@inheritdoc}
     */
    public function rotate(float $angle, string $backgroundColor = '#000000'): void
    {
        $rgb = $this->hexToRgb($backgroundColor);
        $bgColor = imagecolorallocate($this->image, $rgb[0], $rgb[1], $rgb[2]);
        $this->image = imagerotate($this->image, $angle, $bgColor);
        $this->width = imagesx($this->image);
        $this->height = imagesy($this->image);
        imagecolordeallocate($this->image, $bgColor);
        $this->preserveTransparency();
    }

    /**
     * {@inheritdoc}
     */
    public function flip(string $mode = 'horizontal'): void
    {
        $newImage = imagecreatetruecolor($this->width, $this->height);
        $this->preserveTransparency($newImage);

        switch ($mode) {
            case 'horizontal':
                for ($x = 0; $x < $this->width; $x++) {
                    imagecopy(
                        $newImage,
                        $this->image,
                        $x,
                        0,
                        $this->width - $x - 1,
                        0,
                        1,
                        $this->height
                    );
                }
                break;
            case 'vertical':
                for ($y = 0; $y < $this->height; $y++) {
                    imagecopy(
                        $newImage,
                        $this->image,
                        0,
                        $y,
                        0,
                        $this->height - $y - 1,
                        $this->width,
                        1
                    );
                }
                break;
            default:
                throw new \InvalidArgumentException("Invalid flip mode: {$mode}");
        }

        imagedestroy($this->image);
        $this->image = $newImage;
    }

    /**
     * {@inheritdoc}
     */
    public function blur(int $amount = 1): void
    {
        for ($i = 0; $i < $amount; $i++) {
            imagefilter($this->image, IMG_FILTER_GAUSSIAN_BLUR);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function sharpen(int $amount = 1): void
    {
        $matrix = [
            [-1, -1, -1],
            [-1, 16, -1],
            [-1, -1, -1]
        ];

        imageconvolution($this->image, $matrix, 8, 0);
    }

    /**
     * {@inheritdoc}
     */
    public function brightness(int $level): void
    {
        imagefilter($this->image, IMG_FILTER_BRIGHTNESS, $level);
    }

    /**
     * {@inheritdoc}
     */
    public function contrast(int $level): void
    {
        imagefilter($this->image, IMG_FILTER_CONTRAST, $level);
    }

    /**
     * {@inheritdoc}
     */
    public function gamma(float $correction): void
    {
        imagegammacorrect($this->image, 1.0, $correction);
    }

    /**
     * {@inheritdoc}
     */
    public function colorize(int $red, int $green, int $blue): void
    {
        imagefilter($this->image, IMG_FILTER_COLORIZE, $red, $green, $blue);
    }

    /**
     * {@inheritdoc}
     */
    public function greyscale(): void
    {
        imagefilter($this->image, IMG_FILTER_GRAYSCALE);
    }

    /**
     * {@inheritdoc}
     */
    public function sepia(): void
    {
        imagefilter($this->image, IMG_FILTER_GRAYSCALE);
        imagefilter($this->image, IMG_FILTER_COLORIZE, 100, 50, 0);
    }

    /**
     * {@inheritdoc}
     */
    public function pixelate(int $size): void
    {
        imagefilter($this->image, IMG_FILTER_PIXELATE, $size, true);
    }

    /**
     * {@inheritdoc}
     */
    public function watermark($watermark, string $position = 'bottom-right', int $offsetX = 10, int $offsetY = 10): void
    {
        if (is_string($watermark)) {
            $wmDriver = new self();
            $wmDriver->loadFromPath($watermark);
            $watermark = $wmDriver->getResource();
            $wmWidth = $wmDriver->getWidth();
            $wmHeight = $wmDriver->getHeight();
            $wmDriver->destroy();
        } else {
            $wmWidth = imagesx($watermark);
            $wmHeight = imagesy($watermark);
        }

        list($x, $y) = $this->calculatePosition(
            $position,
            $this->width,
            $this->height,
            $wmWidth,
            $wmHeight,
            $offsetX,
            $offsetY
        );

        imagealphablending($this->image, true);
        imagecopy(
            $this->image,
            $watermark,
            $x,
            $y,
            0,
            0,
            $wmWidth,
            $wmHeight
        );

        $this->preserveTransparency();
    }

    /**
     * {@inheritdoc}
     */
    public function stripExif(): void {}

    /**
     * {@inheritdoc}
     */
    public function destroy(): void
    {
        if ($this->image) {
            imagedestroy($this->image);
            $this->image = null;
        }
    }

    public function __destruct()
    {
        $this->destroy();
    }

    /**
     * Preserve transparency for PNG and GIF
     */
    private function preserveTransparency($image = null, bool $fillTransparent = false): void
    {
        $target = $image ?: $this->image;

        if (in_array($this->type, ['png', 'gif'])) {
            imagealphablending($target, false);
            imagesavealpha($target, true);

            if ($fillTransparent && $image !== null) {
                $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
                imagefill($target, 0, 0, $transparent);
            }
        } else {
            imagealphablending($target, true);
        }
    }

    /**
     * Get MIME type from file using finfo
     */
    private function getMimeTypeFromFile(string $path): string
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
    private function getMimeTypeFromString(string $data): string
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
    private function getTypeFromMimeType(string $mimeType): string
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
    private function getMimeTypeFromType(string $type): string
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
    private function isSupportedMimeType(string $mimeType): bool
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
     * Normalize quality value
     */
    private function normalizeQuality(?int $quality, string $format): int
    {
        if ($quality === null) {
            return $format === 'png' ? 9 : 95;
        }

        if ($format === 'png') {
            return max(0, min(9, (int) round(($quality / 100) * 9)));
        }

        return max(0, min(100, $quality));
    }

    /**
     * Convert quality to PNG compression level
     */
    private function getPngQuality(int $quality): int
    {
        return 9 - $quality;
    }

    /**
     * Calculate position coordinates
     */
    private function calculatePosition(
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
     * Convert hex color to RGB
     */
    private function hexToRgb(string $hex): array
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
}