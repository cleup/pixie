<?php

namespace Cleup\Pixie\Drivers\Imagick;

use Imagick;
use ImagickPixel;
use Cleup\Pixie\Driver;
use Cleup\Pixie\Exceptions\DriverException;
use Cleup\Pixie\Exceptions\ImagickDriverException;
use Cleup\Pixie\Exceptions\ImageException;

class ImagickDriver extends Driver
{
    /** 
     * @var \Imagick 
     */
    private $image;

    /**
     * Constructor
     * @throws DriverException
     */
    public function __construct()
    {
        if (!extension_loaded('imagick',)) {
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
            $this->image = new Imagick();
            $this->mimeType = $this->getMimeTypeFromFile($path);
            $this->type = $this->getTypeFromMimeType($this->mimeType);
            $this->image->setBackgroundColor(new \ImagickPixel('white'));
            $this->image->readImage($path);
            $this->fixBlackImageIssue();
            $this->isAnimated = $this->image->getNumberImages() > 1;
            $this->width = $this->image->getImageWidth();
            $this->height = $this->image->getImageHeight();
            $this->image->setImageBackgroundColor(new \ImagickPixel('transparent'));

            if (in_array($this->type, ['jpeg', 'jpg'])) {
                $this->image->setImageColorspace(Imagick::COLORSPACE_SRGB);
            }
        } catch (ImagickDriverException $e) {
            throw ImageException::invalidImage($path);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function loadFromString(string $data): void
    {
        try {
            $this->image = new Imagick();
            $this->mimeType = $this->getMimeTypeFromString($data);
            $this->type = $this->getTypeFromMimeType($this->mimeType);
            $this->image->readImageBlob($data);
            $this->fixBlackImageIssue();
            $this->isAnimated = $this->image->getNumberImages() > 1;
            $this->width = $this->image->getImageWidth();
            $this->height = $this->image->getImageHeight();
        } catch (ImagickDriverException $e) {
            throw ImageException::invalidImage('string data');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function loadFromResource($resource): void
    {
        if (!$resource instanceof Imagick) {
            throw new \InvalidArgumentException('Invalid Imagick resource');
        }

        $this->image = $resource;
        $this->type = strtolower($this->image->getImageFormat());
        $this->mimeType = $this->getMimeTypeFromType($this->type);
        $this->isAnimated = $this->image->getNumberImages() > 1;
        $this->width = $this->image->getImageWidth();
        $this->height = $this->image->getImageHeight();
    }

    /**
     * {@inheritdoc}
     */
    public function save(string $path, ?int $quality = null, ?string $format = null): bool
    {
        $format = $format ?: $this->type;
        $format = strtolower($format);
        $quality = $this->normalizeQuality($quality, $format);

        try {
            $image = $this->prepareImageForSave($format, $quality);

            if ($this->isAnimated && $format === 'gif') {
                return $image->writeImages($path, true);
            }

            return $image->writeImage($path);
        } catch (ImagickDriverException $e) {
            dd($e);
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

            if ($this->isAnimated && $format === 'gif') {
                $image = $image->deconstructImages();
            }

            return $image->getImagesBlob();
        } catch (ImagickDriverException $e) {
            throw ImageException::operationFailed('get string');
        }
    }

    /**
     * Execute operation with coalesced images for animated GIFs
     */
    private function withCoalesced(callable $operation): void
    {
        $wasCoalesced = false;
        if ($this->isAnimated) {
            $this->image = $this->image->coalesceImages();
            $wasCoalesced = true;
        }

        $operation();

        if ($wasCoalesced) {
            $this->image = $this->image->deconstructImages();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getResource()
    {
        return $this->image;
    }

    public function resize(int $width, int $height, bool $preserveAspectRatio = true): void
    {
        $this->withCoalesced(function () use ($width, $height, $preserveAspectRatio) {
            try {
                if (!$this->isUpscale()) {
                    $width = min($width, $this->width);
                    $height = min($height, $this->height);
                }

                if ($this->isAnimated) {
                    foreach ($this->image as $frame) {
                        if ($preserveAspectRatio) {
                            $frame->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1, true);
                        } else {
                            $frame->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);
                        }
                    }
                } else {
                    if ($preserveAspectRatio) {
                        $this->image->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1, true);
                    } else {
                        $this->image->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);
                    }
                }

                $this->width = $this->image->getImageWidth();
                $this->height = $this->image->getImageHeight();
            } catch (ImagickDriverException $e) {
                throw ImageException::operationFailed('resize');
            }
        });
    }

    /**
     * Fit image to dimensions
     */
    public function fit(int $width, int $height): void
    {
        $this->withCoalesced(function () use ($width, $height) {
            $ratio = $this->width / $this->height;
            $targetRatio = $width / $height;

            if ($ratio > $targetRatio) {
                $newHeight = $height;
                $newWidth = (int) round($height * $ratio);
            } else {
                $newWidth = $width;
                $newHeight = (int) round($width / $ratio);
            }

            if (!$this->isUpscale()) {
                $newWidth = min($newWidth, $this->width);
                $newHeight = min($newHeight, $this->height);
            }

            $this->resize($newWidth, $newHeight, false);
            $x = (int) max(0, ($newWidth - $width) / 2);
            $y = (int) max(0, ($newHeight - $height) / 2);
            $this->crop($x, $y, $width, $height);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function resizeCanvas(int $width, int $height, string $position = 'center'): void
    {
        $this->withCoalesced(function () use ($width, $height, $position) {
            try {
                if ($this->isAnimated) {
                    foreach ($this->image as $frame) {
                        $this->resizeFrameCanvas($frame, $width, $height, $position);
                    }
                } else {
                    $this->resizeFrameCanvas($this->image, $width, $height, $position);
                }

                $this->width = $width;
                $this->height = $height;
            } catch (ImagickDriverException $e) {
                throw ImageException::operationFailed('resize canvas');
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function crop(int $x, int $y, int $width, int $height): void
    {
        $this->withCoalesced(function () use ($x, $y, $width, $height) {
            try {
                if ($this->isAnimated) {
                    foreach ($this->image as $frame) {
                        $frame->cropImage($width, $height, $x, $y);
                        $frame->setImagePage(0, 0, 0, 0);
                    }
                } else {
                    $this->image->cropImage($width, $height, $x, $y);
                    $this->image->setImagePage(0, 0, 0, 0);
                }

                $this->width = $width;
                $this->height = $height;
            } catch (ImagickDriverException $e) {
                throw ImageException::operationFailed('crop');
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function rotate(float $angle, string $backgroundColor = '#000000'): void
    {
        $this->withCoalesced(function () use ($angle, $backgroundColor) {
            try {
                if ($this->isAnimated) {
                    foreach ($this->image as $frame) {

                        $frame->rotateImage(new ImagickPixel($backgroundColor), $angle);
                    }
                } else {
                    $this->image->rotateImage(new ImagickPixel($backgroundColor), $angle);
                }

                $this->width = $this->image->getImageWidth();
                $this->height = $this->image->getImageHeight();
            } catch (ImagickDriverException $e) {
                throw ImageException::operationFailed('rotate');
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function flip(string $mode = 'horizontal'): void
    {
        $this->withCoalesced(function () use ($mode) {
            try {
                if ($this->isAnimated) {
                    foreach ($this->image as $frame) {
                        if ($mode === 'horizontal') {
                            $frame->flopImage();
                        } else {
                            $frame->flipImage();
                        }
                    }
                } else {
                    if ($mode === 'horizontal') {
                        $this->image->flopImage();
                    } else {
                        $this->image->flipImage();
                    }
                }
            } catch (ImagickDriverException $e) {
                throw ImageException::operationFailed('flip');
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function blur(int $amount = 1): void
    {
        $this->withCoalesced(function () use ($amount) {
            try {
                if ($this->isAnimated) {
                    foreach ($this->image as $frame) {
                        $frame->gaussianBlurImage(0.8 * $amount, 0.6 * $amount);
                    }
                } else {
                    $this->image->gaussianBlurImage(0.8 * $amount, 0.6 * $amount);
                }
            } catch (ImagickDriverException $e) {
                throw ImageException::operationFailed('blur');
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function sharpen(int $amount = 1): void
    {
        $this->withCoalesced(function () use ($amount) {
            try {
                if ($this->isAnimated) {
                    foreach ($this->image as $frame) {
                        $frame->sharpenImage(0, $amount);
                    }
                } else {
                    $this->image->sharpenImage(0, $amount);
                }
            } catch (ImagickDriverException $e) {
                throw ImageException::operationFailed('sharpen');
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function brightness(int $level): void
    {
        $this->withCoalesced(function () use ($level) {
            try {
                if ($this->isAnimated) {
                    foreach ($this->image as $frame) {
                        $frame->modulateImage(100 + $level, 100, 100);
                    }
                } else {
                    $this->image->modulateImage(100 + $level, 100, 100);
                }
            } catch (ImagickDriverException $e) {
                throw ImageException::operationFailed('brightness');
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function contrast(int $level): void
    {
        $this->withCoalesced(function () use ($level) {
            try {
                if ($this->isAnimated) {
                    foreach ($this->image as $frame) {
                        $frame->sigmoidalContrastImage(true, $level / 10, 0);
                    }
                } else {
                    $this->image->sigmoidalContrastImage(true, $level / 10, 0);
                }
            } catch (ImagickDriverException $e) {
                throw ImageException::operationFailed('contrast');
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function gamma(float $correction): void
    {
        $this->withCoalesced(function () use ($correction) {
            try {
                if ($this->isAnimated) {
                    foreach ($this->image as $frame) {
                        $frame->gammaImage($correction);
                    }
                } else {
                    $this->image->gammaImage($correction);
                }
            } catch (ImagickDriverException $e) {
                throw ImageException::operationFailed('gamma');
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function colorize(int $red, int $green, int $blue): void
    {
        $this->withCoalesced(function () use ($red, $green, $blue) {
            try {
                if ($this->isAnimated) {
                    foreach ($this->image as $frame) {
                        $frame->colorizeImage("rgb($red,$green,$blue)", 1.0);
                    }
                } else {
                    $this->image->colorizeImage("rgb($red,$green,$blue)", 1.0);
                }
            } catch (ImagickDriverException $e) {
                throw ImageException::operationFailed('colorize');
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function greyscale(): void
    {
        $this->withCoalesced(function () {
            try {
                if ($this->isAnimated) {
                    foreach ($this->image as $frame) {
                        $frame->setImageType(Imagick::IMGTYPE_GRAYSCALE);
                    }
                } else {
                    $this->image->setImageType(Imagick::IMGTYPE_GRAYSCALE);
                }
            } catch (ImagickDriverException $e) {
                throw ImageException::operationFailed('greyscale');
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function sepia(): void
    {
        $this->withCoalesced(function () {
            try {
                if ($this->isAnimated) {
                    foreach ($this->image as $frame) {
                        $frame->sepiaToneImage(80);
                    }
                } else {
                    $this->image->sepiaToneImage(80);
                }
            } catch (ImagickDriverException $e) {
                throw ImageException::operationFailed('sepia');
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function pixelate(int $size): void
    {
        $this->withCoalesced(function () use ($size) {
            try {
                if ($this->isAnimated) {
                    foreach ($this->image as $frame) {
                        $frame->scaleImage(max(1, $this->width / $size), max(1, $this->height / $size));
                        $frame->scaleImage($this->width, $this->height);
                    }
                } else {
                    $this->image->scaleImage(max(1, $this->width / $size), max(1, $this->height / $size));
                    $this->image->scaleImage($this->width, $this->height);
                }
            } catch (ImagickDriverException $e) {
                throw ImageException::operationFailed('pixelate');
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function watermark($watermark, string $position = 'bottom-right', int $offsetX = 10, int $offsetY = 10): void
    {
        $this->withCoalesced(function () use ($watermark, $position, $offsetX, $offsetY) {
            try {
                if (is_string($watermark)) {
                    $watermarkImage = new Imagick($watermark);
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
                    foreach ($this->image as $frame) {
                        $frame->compositeImage($watermarkImage, Imagick::COMPOSITE_OVER, $x, $y);
                    }
                } else {
                    $this->image->compositeImage($watermarkImage, Imagick::COMPOSITE_OVER, $x, $y);
                }
            } catch (ImagickDriverException $e) {
                throw ImageException::operationFailed('watermark');
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function stripExif(): void
    {
        try {
            $this->image->stripImage();
        } catch (ImagickDriverException $e) {
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
            $format = strtolower($this->image->getImageFormat());

            if (in_array($format, ['jpeg', 'jpg'])) {
                $this->image->setImageColorspace(Imagick::COLORSPACE_SRGB);
                $this->image->setImageType(Imagick::IMGTYPE_TRUECOLOR);
                $this->image->setImageCompression(Imagick::COMPRESSION_JPEG);
                $this->image->setImageCompressionQuality(95);
            }
        } catch (ImagickDriverException $e) {
        }
    }

    /**
     * Prepare image for saving with optimal settings
     */
    private function prepareImageForSave(string $format, int $quality): Imagick
    {
        $image = clone $this->image;

        if ($format === 'webp') {
            return $this->processWebp($image, $quality);
        }

        if ($format === 'gif') {
            return $this->processGif($image, $quality);
        }

        if ($format !== $this->type) {
            $image->setImageFormat($format);
        }

        $this->setImageQuality($image, $quality, $format);

        switch ($format) {
            case 'jpeg':
            case 'jpg':
                $image->setImageCompression(Imagick::COMPRESSION_JPEG);
                $image->setInterlaceScheme(Imagick::INTERLACE_PLANE);
                break;
            case 'png':
                $image->setImageCompression(Imagick::COMPRESSION_ZIP);
                break;
        }

        return $image;
    }

    /**
     * Process GIF with optimization
     */
    private function processGif(Imagick $image, int $quality): Imagick
    {
        $optimized = clone $image;
        $optimized->setImageFormat('gif');

        if ($this->isAnimated) {
            return $this->optimizeAnimatedGif($optimized, $quality);
        } else {
            return $this->optimizeStaticGif($optimized, $quality);
        }
    }

    /**
     * Optimize static GIF without changing appearance
     */
    private function optimizeStaticGif(Imagick $image, int $quality): Imagick
    {
        $optimized = clone $image;

        if ($quality < 100) {
            $this->applyLosslessGifOptimization($optimized, $quality);
        }

        $optimized->setImageCompression(Imagick::COMPRESSION_LZW);

        return $optimized;
    }

    /**
     * Optimize animated GIF without changing appearance
     */
    private function optimizeAnimatedGif(Imagick $image, int $quality): Imagick
    {
        if ($quality == 100) {
            return $image;
        }

        $image = $image->coalesceImages();

        foreach ($image as $frame) {
            $this->applyLosslessGifOptimization($frame, $quality);
            $frame->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
            $frame->setBackgroundColor(new ImagickPixel('transparent'));
        }

        $image = $image->deconstructImages();
        $image->setImageCompression(Imagick::COMPRESSION_LZW);
        $image->optimizeImageLayers();

        return $image;
    }

    /**
     * Apply lossless GIF optimization
     */
    private function applyLosslessGifOptimization(Imagick $image, int $quality): void
    {
        $image->setImageType(Imagick::IMGTYPE_PALETTE);
        $image->stripImage();

        if ($quality < 100) {
            $colors = (int) (256 * ($quality / 100));
            $colors = max(16, min(256, $colors));
            $image->quantizeImage($colors, Imagick::COLORSPACE_RGB, 0, false, false);
        }

        try {
            $image->remapImage($image, Imagick::DITHERMETHOD_NO);
        } catch (\Exception $e) {
        }
    }




    /**
     * Process WEBP with forced quality application
     */
    private function processWebp(Imagick $image, int $quality): Imagick
    {
        $webpImage = new Imagick();
        $image->setImageFormat('png');
        $pngBlob = $image->getImageBlob();
        $webpImage->readImageBlob($pngBlob);
        $webpImage->setImageFormat('WEBP');
        $webpImage->setImageCompressionQuality($quality);
        $webpImage->setImageCompression(Imagick::COMPRESSION_JPEG);
        $webpImage->setOption('webp:lossless', 'false');

        if ($quality <= 30) {
            $webpImage->setOption('webp:method', '6');
        } elseif ($quality <= 70) {
            $webpImage->setOption('webp:method', '5');
        } else {
            $webpImage->setOption('webp:method', '4');
        }

        $webpBlob = $webpImage->getImageBlob();
        $finalImage = new Imagick();
        $finalImage->readImageBlob($webpBlob);
        $finalImage->setImageFormat('WEBP');
        $finalImage->setImageCompressionQuality($quality);
        $webpImage->destroy();

        return $finalImage;
    }

    /**
     * Set image quality with optimal settings
     */
    private function setImageQuality(Imagick $image, int $quality, string $format): void
    {
        if (in_array($format, ['jpeg', 'jpg', 'webp'])) {
            $image->setImageCompressionQuality($quality);
        } elseif ($format === 'png') {
            $compression = (int) ((100 - $quality) / 100 * 9);
            $image->setImageCompressionQuality($compression);
        }
    }

    /**
     * Resize canvas for single frame
     */
    private function resizeFrameCanvas(Imagick $frame, int $width, int $height, string $position): void
    {
        list($x, $y) = $this->calculatePosition($position, $width, $height);

        $frame->extentImage($width, $height, -$x, -$y);
    }
}
