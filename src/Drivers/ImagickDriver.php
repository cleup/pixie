<?php

namespace Cleup\Pixie\Drivers;

use Cleup\Pixie\Interfaces\DriverInterface;
use Cleup\Pixie\Exceptions\DriverException;
use Cleup\Pixie\Exceptions\ImageException;

/**
 * Imagick driver for high-quality image manipulation
 * Provides excellent quality preservation and animation support
 */
class ImagickDriver implements DriverInterface
{
    private \Imagick $image;
    private int $width;
    private int $height;
    private string $type;
    private bool $isAnimated = false;

    /**
     * Constructor
     * @throws DriverException
     */
    public function __construct()
    {
        if (!extension_loaded('imagick')) {
            throw DriverException::notAvailable('Imagick');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function loadFromPath(string $path): void
    {
        if (!file_exists($path)) {
            throw ImageException::fileNotFound($path);
        }

        try {
            $this->image = new \Imagick();

            // Устанавливаем правильный цветовой профиль перед загрузкой
            $this->image->setBackgroundColor(new \ImagickPixel('white'));

            // Читаем изображение с правильными настройками
            $this->image->readImage($path);

            // Исправляем проблему с черными JPG изображениями
            $this->fixBlackImageIssue();

            $this->type = strtolower($this->image->getImageFormat());
            $this->isAnimated = $this->image->getNumberImages() > 1;

            $this->width = $this->image->getImageWidth();
            $this->height = $this->image->getImageHeight();

            // Оптимизация для работы с изображениями
            $this->image->setImageBackgroundColor(new \ImagickPixel('transparent'));

            // Для JPG изображений устанавливаем правильный цветовой профиль
            if (in_array($this->type, ['jpeg', 'jpg'])) {
                $this->image->setImageColorspace(\Imagick::COLORSPACE_SRGB);
            }
        } catch (\ImagickException $e) {
            throw ImageException::invalidImage($path);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function loadFromString(string $data): void
    {
        try {
            $this->image = new \Imagick();
            $this->image->readImageBlob($data);

            // Исправляем проблему с черными изображениями
            $this->fixBlackImageIssue();

            $this->type = strtolower($this->image->getImageFormat());
            $this->isAnimated = $this->image->getNumberImages() > 1;

            $this->width = $this->image->getImageWidth();
            $this->height = $this->image->getImageHeight();
        } catch (\ImagickException $e) {
            throw ImageException::invalidImage('string data');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function loadFromResource($resource): void
    {
        if (!$resource instanceof \Imagick) {
            throw new \InvalidArgumentException('Invalid Imagick resource');
        }

        $this->image = $resource;
        $this->type = strtolower($this->image->getImageFormat());
        $this->isAnimated = $this->image->getNumberImages() > 1;
        $this->width = $this->image->getImageWidth();
        $this->height = $this->image->getImageHeight();
    }

    /**
     * {@inheritdoc}
     */
    public function save(string $path, ?int $quality = null, ?string $format = null): bool
    {
        $format = $format ?: pathinfo($path, PATHINFO_EXTENSION) ?: $this->type;
        $format = strtolower($format);

        $quality = $this->normalizeQuality($quality, $format);

        try {
            $image = $this->prepareImageForSave($format, $quality);

            // Для анимированных GIF сохраняем все кадры
            if ($this->isAnimated && $format === 'gif') {
                $image = $image->deconstructImages();
                return $image->writeImages($path, true);
            }

            return $image->writeImage($path);
        } catch (\ImagickException $e) {
            throw ImageException::operationFailed('save');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getString(?string $format = null, ?int $quality = null): string
    {
        $format = $format ?: $this->type;
        $quality = $this->normalizeQuality($quality, $format);

        try {
            $image = $this->prepareImageForSave($format, $quality);

            // Для анимированных GIF
            if ($this->isAnimated && $format === 'gif') {
                $image = $image->deconstructImages();
            }

            return $image->getImagesBlob();
        } catch (\ImagickException $e) {
            throw ImageException::operationFailed('get string');
        }
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
    public function isAnimated(): bool
    {
        return $this->isAnimated;
    }

    /**
     * {@inheritdoc}
     */
    public function resize(int $width, int $height, bool $preserveAspectRatio = true): void
    {
        try {
            if ($this->isAnimated) {
                $this->image = $this->image->coalesceImages();

                foreach ($this->image as $frame) {
                    if ($preserveAspectRatio) {
                        $frame->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1, true);
                    } else {
                        $frame->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1);
                    }
                }

                $this->image = $this->image->deconstructImages();
            } else {
                if ($preserveAspectRatio) {
                    $this->image->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1, true);
                } else {
                    $this->image->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1);
                }
            }

            $this->width = $this->image->getImageWidth();
            $this->height = $this->image->getImageHeight();
        } catch (\ImagickException $e) {
            throw ImageException::operationFailed('resize');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function resizeCanvas(int $width, int $height, string $position = 'center'): void
    {
        try {
            if ($this->isAnimated) {
                $this->image = $this->image->coalesceImages();

                foreach ($this->image as $frame) {
                    $this->resizeFrameCanvas($frame, $width, $height, $position);
                }

                $this->image = $this->image->deconstructImages();
            } else {
                $this->resizeFrameCanvas($this->image, $width, $height, $position);
            }

            $this->width = $width;
            $this->height = $height;
        } catch (\ImagickException $e) {
            throw ImageException::operationFailed('resize canvas');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function crop(int $x, int $y, int $width, int $height): void
    {
        try {
            if ($this->isAnimated) {
                $this->image = $this->image->coalesceImages();

                foreach ($this->image as $frame) {
                    $frame->cropImage($width, $height, $x, $y);
                    $frame->setImagePage(0, 0, 0, 0);
                }

                $this->image = $this->image->deconstructImages();
            } else {
                $this->image->cropImage($width, $height, $x, $y);
                $this->image->setImagePage(0, 0, 0, 0);
            }

            $this->width = $width;
            $this->height = $height;
        } catch (\ImagickException $e) {
            throw ImageException::operationFailed('crop');
        }
    }
    /**
     * {@inheritdoc}
     */
    public function fit(int $width, int $height, bool $upscale = false): void
    {
        $ratio = $this->width / $this->height;
        $targetRatio = $width / $height;

        // Определяем размеры для обрезки
        if ($ratio > $targetRatio) {
            // Ширина изображения больше целевой - обрезаем по ширине
            $newHeight = $height;
            $newWidth = (int) round($height * $ratio);
        } else {
            // Высота изображения больше целевой - обрезаем по высоте
            $newWidth = $width;
            $newHeight = (int) round($width / $ratio);
        }

        if (!$upscale) {
            $newWidth = min($newWidth, $this->width);
            $newHeight = min($newHeight, $this->height);
        }

        try {
            if ($this->isAnimated) {
                $this->image = $this->image->coalesceImages();

                foreach ($this->image as $frame) {
                    // Изменяем размер
                    $frame->resizeImage($newWidth, $newHeight, \Imagick::FILTER_LANCZOS, 1);

                    // Обрезаем до точных размеров
                    $x = (int) max(0, ($newWidth - $width) / 2);
                    $y = (int) max(0, ($newHeight - $height) / 2);

                    $frame->cropImage($width, $height, $x, $y);
                    $frame->setImagePage(0, 0, 0, 0);
                }

                $this->image = $this->image->deconstructImages();
            } else {
                // Изменяем размер
                $this->image->resizeImage($newWidth, $newHeight, \Imagick::FILTER_LANCZOS, 1);

                // Обрезаем до точных размеров
                $x = (int) max(0, ($newWidth - $width) / 2);
                $y = (int) max(0, ($newHeight - $height) / 2);

                $this->image->cropImage($width, $height, $x, $y);
                $this->image->setImagePage(0, 0, 0, 0);
            }

            $this->width = $width;
            $this->height = $height;
        } catch (\Throwable $e) {
            throw ImageException::operationFailed('fit');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function rotate(float $angle, string $backgroundColor = '#000000'): void
    {
        try {
            if ($this->isAnimated) {
                $this->image = $this->image->coalesceImages();

                foreach ($this->image as $frame) {
                    $frame->rotateImage(new \ImagickPixel($backgroundColor), $angle);
                }

                $this->image = $this->image->deconstructImages();
            } else {
                $this->image->rotateImage(new \ImagickPixel($backgroundColor), $angle);
            }

            $this->width = $this->image->getImageWidth();
            $this->height = $this->image->getImageHeight();
        } catch (\ImagickException $e) {
            throw ImageException::operationFailed('rotate');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function flip(string $mode = 'horizontal'): void
    {
        try {
            if ($this->isAnimated) {
                $this->image = $this->image->coalesceImages();

                foreach ($this->image as $frame) {
                    if ($mode === 'horizontal') {
                        $frame->flopImage();
                    } else {
                        $frame->flipImage();
                    }
                }

                $this->image = $this->image->deconstructImages();
            } else {
                if ($mode === 'horizontal') {
                    $this->image->flopImage();
                } else {
                    $this->image->flipImage();
                }
            }
        } catch (\ImagickException $e) {
            throw ImageException::operationFailed('flip');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function blur(int $amount = 1): void
    {
        try {
            if ($this->isAnimated) {
                $this->image = $this->image->coalesceImages();

                foreach ($this->image as $frame) {
                    $frame->gaussianBlurImage(0.8 * $amount, 0.6 * $amount);
                }

                $this->image = $this->image->deconstructImages();
            } else {
                $this->image->gaussianBlurImage(0.8 * $amount, 0.6 * $amount);
            }
        } catch (\ImagickException $e) {
            throw ImageException::operationFailed('blur');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function sharpen(int $amount = 1): void
    {
        try {
            if ($this->isAnimated) {
                $this->image = $this->image->coalesceImages();

                foreach ($this->image as $frame) {
                    $frame->sharpenImage(0, $amount);
                }

                $this->image = $this->image->deconstructImages();
            } else {
                $this->image->sharpenImage(0, $amount);
            }
        } catch (\ImagickException $e) {
            throw ImageException::operationFailed('sharpen');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function brightness(int $level): void
    {
        try {
            if ($this->isAnimated) {
                $this->image = $this->image->coalesceImages();

                foreach ($this->image as $frame) {
                    $frame->modulateImage(100 + $level, 100, 100);
                }

                $this->image = $this->image->deconstructImages();
            } else {
                $this->image->modulateImage(100 + $level, 100, 100);
            }
        } catch (\ImagickException $e) {
            throw ImageException::operationFailed('brightness');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function contrast(int $level): void
    {
        try {
            if ($this->isAnimated) {
                $this->image = $this->image->coalesceImages();

                foreach ($this->image as $frame) {
                    $frame->sigmoidalContrastImage(true, $level / 10, 0);
                }

                $this->image = $this->image->deconstructImages();
            } else {
                $this->image->sigmoidalContrastImage(true, $level / 10, 0);
            }
        } catch (\ImagickException $e) {
            throw ImageException::operationFailed('contrast');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function gamma(float $correction): void
    {
        try {
            if ($this->isAnimated) {
                $this->image = $this->image->coalesceImages();

                foreach ($this->image as $frame) {
                    $frame->gammaImage($correction);
                }

                $this->image = $this->image->deconstructImages();
            } else {
                $this->image->gammaImage($correction);
            }
        } catch (\ImagickException $e) {
            throw ImageException::operationFailed('gamma');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function colorize(int $red, int $green, int $blue): void
    {
        try {
            if ($this->isAnimated) {
                $this->image = $this->image->coalesceImages();

                foreach ($this->image as $frame) {
                    $frame->colorizeImage("rgb($red,$green,$blue)", 1.0);
                }

                $this->image = $this->image->deconstructImages();
            } else {
                $this->image->colorizeImage("rgb($red,$green,$blue)", 1.0);
            }
        } catch (\ImagickException $e) {
            throw ImageException::operationFailed('colorize');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function greyscale(): void
    {
        try {
            if ($this->isAnimated) {
                $this->image = $this->image->coalesceImages();

                foreach ($this->image as $frame) {
                    $frame->setImageType(\Imagick::IMGTYPE_GRAYSCALE);
                }

                $this->image = $this->image->deconstructImages();
            } else {
                $this->image->setImageType(\Imagick::IMGTYPE_GRAYSCALE);
            }
        } catch (\ImagickException $e) {
            throw ImageException::operationFailed('greyscale');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function sepia(): void
    {
        try {
            if ($this->isAnimated) {
                $this->image = $this->image->coalesceImages();

                foreach ($this->image as $frame) {
                    $frame->sepiaToneImage(80);
                }

                $this->image = $this->image->deconstructImages();
            } else {
                $this->image->sepiaToneImage(80);
            }
        } catch (\ImagickException $e) {
            throw ImageException::operationFailed('sepia');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function pixelate(int $size): void
    {
        try {
            if ($this->isAnimated) {
                $this->image = $this->image->coalesceImages();

                foreach ($this->image as $frame) {
                    $frame->scaleImage(max(1, $this->width / $size), max(1, $this->height / $size));
                    $frame->scaleImage($this->width, $this->height);
                }

                $this->image = $this->image->deconstructImages();
            } else {
                $this->image->scaleImage(max(1, $this->width / $size), max(1, $this->height / $size));
                $this->image->scaleImage($this->width, $this->height);
            }
        } catch (\ImagickException $e) {
            throw ImageException::operationFailed('pixelate');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function watermark($watermark, string $position = 'bottom-right', int $offsetX = 10, int $offsetY = 10): void
    {
        try {
            if (is_string($watermark)) {
                $watermarkImage = new \Imagick($watermark);
            } else {
                $watermarkImage = $watermark;
            }

            $wmWidth = $watermarkImage->getImageWidth();
            $wmHeight = $watermarkImage->getImageHeight();

            list($x, $y) = $this->calculatePosition(
                $position,
                $this->width,
                $this->height,
                $wmWidth,
                $wmHeight,
                $offsetX,
                $offsetY
            );

            if ($this->isAnimated) {
                $this->image = $this->image->coalesceImages();

                foreach ($this->image as $frame) {
                    $frame->compositeImage($watermarkImage, \Imagick::COMPOSITE_OVER, $x, $y);
                }

                $this->image = $this->image->deconstructImages();
            } else {
                $this->image->compositeImage($watermarkImage, \Imagick::COMPOSITE_OVER, $x, $y);
            }
        } catch (\ImagickException $e) {
            throw ImageException::operationFailed('watermark');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stripExif(): void
    {
        try {
            $this->image->stripImage();
        } catch (\ImagickException $e) {
            throw ImageException::operationFailed('strip exif');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function destroy(): void
    {
        if (isset($this->image)) {
            $this->image->clear();
            $this->image->destroy();
        }
    }

    public function __destruct()
    {
        $this->destroy();
    }

    /**
     * Fix black image issue with JPG files
     */
    private function fixBlackImageIssue(): void
    {
        try {
            // Проверяем, является ли изображение JPG и имеет ли проблему с черным цветом
            $format = strtolower($this->image->getImageFormat());

            if (in_array($format, ['jpeg', 'jpg'])) {
                // Устанавливаем правильный цветовой профиль
                $this->image->setImageColorspace(\Imagick::COLORSPACE_SRGB);

                // Убеждаемся, что изображение имеет правильный тип
                $this->image->setImageType(\Imagick::IMGTYPE_TRUECOLOR);

                // Устанавливаем формат сжатия
                $this->image->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $this->image->setImageCompressionQuality(95);
            }
        } catch (\ImagickException $e) {
            // Игнорируем ошибки при исправлении
        }
    }

    /**
     * Prepare image for saving with optimal settings
     */
    private function prepareImageForSave(string $format, int $quality): \Imagick
    {
        $image = clone $this->image;

        // Установка формата
        if ($format !== $this->type) {
            $image->setImageFormat($format);
        }

        // Установка качества с оптимальными настройками
        $this->setImageQuality($image, $quality, $format);

        // Оптимизация для разных форматов
        switch ($format) {
            case 'jpeg':
            case 'jpg':
                $image->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $image->setInterlaceScheme(\Imagick::INTERLACE_PLANE);
                break;
            case 'png':
                $image->setImageCompression(\Imagick::COMPRESSION_ZIP);
                break;
            case 'webp':
                $image->setImageCompression(\Imagick::COMPRESSION_WEBP);
                break;
            case 'gif':
                $image->setImageCompression(\Imagick::COMPRESSION_LZW);
                break;
        }

        return $image;
    }

    /**
     * Set image quality with optimal settings
     */
    private function setImageQuality(\Imagick $image, int $quality, string $format): void
    {
        if (in_array($format, ['jpeg', 'jpg', 'webp'])) {
            $image->setImageCompressionQuality($quality);
        } elseif ($format === 'png') {
            // Для PNG используем сжатие вместо качества
            $compression = (int) ((100 - $quality) / 100 * 9);
            $image->setImageCompressionQuality($compression);
        }
    }

    /**
     * Resize canvas for single frame
     */
    private function resizeFrameCanvas(\Imagick $frame, int $width, int $height, string $position): void
    {
        list($x, $y) = $this->calculatePosition($position, $width, $height);

        $frame->extentImage($width, $height, -$x, -$y);
    }

    /**
     * Normalize quality value
     */
    private function normalizeQuality(?int $quality, string $format): int
    {
        if ($quality === null) {
            return 95;
        }

        return max(0, min(100, $quality));
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
}
