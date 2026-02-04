<?php

namespace Cleup\Pixie\Drivers\Imagick;

use Imagick;
use ImagickPixel;
use Cleup\Pixie\Driver;
use Cleup\Pixie\Exceptions\DriverException;
use Cleup\Pixie\Exceptions\ImageException;

class ImagickDriver extends Driver
{
    /** 
     * @var \Imagick 
     */
    private $image;

    /**
     * @var string Temporary file path for original GIF
     */
    private $tempOriginalPath;

    /**
     * Constructor
     * 
     * @throws DriverException When Imagick extension is not loaded
     */
    public function __construct()
    {
        parent::__construct();

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
            $this->image = new Imagick();
            $this->mimeType = $this->getMimeTypeFromFile($path);
            $this->type = $this->getTypeFromMimeType($this->mimeType);
            $this->image->setBackgroundColor(new ImagickPixel('white'));
            $this->image->readImage($path);
            $this->fixBlackImageIssue();
            $this->isAnimated = $this->image->getNumberImages() > 1;
            $this->width = $this->image->getImageWidth();
            $this->height = $this->image->getImageHeight();
            $this->image->setImageBackgroundColor(new ImagickPixel('transparent'));

            if (in_array($this->type, ['jpeg', 'jpg'])) {
                $this->image->setImageColorspace(Imagick::COLORSPACE_SRGB);
            }
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
            throw ImageException::invalidImage('string data');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function save(
        string $path,
        ?int $quality = null,
        ?string $format = null
    ): bool {
        $format = $format ?: $this->type;
        $format = strtolower($format);

        // Special handling for GIF with gifsicle
        if (
            $format === 'gif' &&
            $this->isGifsicle &&
            $this->gifsicle !== null
        ) {
            return $this->saveGifOptimally($path, $quality);
        }

        // Normal handling for other formats
        $quality = $this->normalizeQuality($quality, $format);

        try {
            $image = $this->prepareImageForSave($format, $quality);

            if ($this->isAnimated && $format === 'gif') {
                return $this->saveGifWithColorReduction($path, $quality);
            }

            return $image->writeImage($path);
        } catch (\Exception $e) {
            throw ImageException::operationFailed('save');
        }
    }

    /**
     * Direct GIF save without gifsicle
     * 
     * @param string $path Output file path
     * @param int|null $quality Quality level
     * @return bool
     */
    protected function saveGifDirect(string $path, ?int $quality = null): bool
    {
        return $this->saveGifWithColorReduction(
            $path,
            $this->normalizeQuality($quality, 'gif')
        );
    }

    /**
     * Save GIF with color reduction based on quality
     * 
     * @param string $path Output file path
     * @param int $quality Quality level (0-100)
     * @return bool Success status
     */
    private function saveGifWithColorReduction(string $path, int $quality): bool
    {
        $image = clone $this->image;

        try {
            $this->removeAllMetadataReal($image);

            if (!$this->isAnimated) {
                $currentColors = $image->getImageColors();
                $colors = $this->calculateRealColors($quality, $currentColors);

                if ($currentColors > $colors) {
                    $image->quantizeImage($colors, Imagick::COLORSPACE_SRGB, 0, false, false);
                }
            } else {
                // For animated GIFs - common palette
                $image = $image->coalesceImages();
                $this->removeAllMetadataReal($image);
                $allFrames = new Imagick();

                foreach ($image as $frame) {
                    $allFrames->addImage($frame->getImage());
                }

                $currentColors = $image->getImageColors();
                $colors = $this->calculateRealColors($quality, $currentColors);
                $colors = $colors >  $currentColors ? $currentColors : $colors;
                $allFrames->quantizeImage($colors, Imagick::COLORSPACE_SRGB, 0, false, false);

                // Apply common palette
                foreach ($image as $frame) {
                    $frame->remapImage($allFrames, Imagick::DITHERMETHOD_NO);
                    $frame->setCompressionQuality($quality);
                }

                $image->deconstructImages();
                $allFrames->destroy();

                $image->setImageCompression(Imagick::COMPRESSION_LZW);
                $image->optimizeImageLayers();
                $this->removeAllMetadataReal($image);
            }

            // 4. Save
            if ($this->isAnimated) {
                return $image->writeImages($path, true);
            }

            return $image->writeImage($path);
        } catch (\Exception $e) {
            // Fallback
            return $this->saveGifSimple($path);
        }
    }

    /**
     * Remove all metadata from image
     * 
     * @param Imagick $image Image object
     */
    private function removeAllMetadataReal(Imagick $image): void
    {
        try {
            $image->stripImage();

            // Remove ALL profiles that may exist
            $profiles = $image->getImageProfiles('*', false);
            foreach ($profiles as $profile) {
                try {
                    $image->removeImageProfile($profile);
                } catch (\Exception $e) {
                    // Ignore
                }
            }

            // Clear properties
            $image->setImageProperty('comment', '');
            $image->setImageProperty('date:create', '');
            $image->setImageProperty('date:modify', '');
            $image->setImageProperty('software', '');
        } catch (\Exception $e) {
            $image->stripImage();
        }
    }

    /**
     * Simple GIF save without optimization
     * 
     * @param string $path Output file path
     * @return bool Success status
     */
    private function saveGifSimple(string $path): bool
    {
        $image = clone $this->image;

        if ($this->isAnimated) {
            return $image->writeImages($path, true);
        }

        return $image->writeImage($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getString(
        ?string $format = null,
        ?int $quality = null
    ): string {
        $format = $format ?: $this->type;
        $quality = $this->normalizeQuality($quality, $format);

        try {
            // Special handling for GIF with gifsicle
            if ($format === 'gif' && $this->isGifsicle && $this->gifsicle !== null) {
                $tempFile = $this->createTempFilePath();
                $this->saveGifOptimally($tempFile, $quality);

                return $this->getTempFile($tempFile);
            }

            $image = $this->prepareImageForSave($format, $quality);

            if ($this->isAnimated && $format === 'gif') {
                $image = $image->deconstructImages();
            }

            return $image->getImagesBlob();
        } catch (\Exception $e) {
            throw ImageException::operationFailed('get string');
        }
    }

    /**
     * Execute operation with coalesced images for animated GIFs
     * 
     * @param callable $operation Operation to execute
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

    /**
     * {@inheritdoc}
     */
    public function resize(
        int $width,
        int $height,
        bool $preserveAspectRatio = true,
        bool $upscale = false
    ): void {
        $this->withCoalesced(function () use (
            $width,
            $height,
            $preserveAspectRatio,
            $upscale
        ) {
            try {
                if (!$upscale && !$this->isUpscale()) {
                    $width = min($width, $this->width);
                    $height = min($height, $this->height);
                }

                if ($this->isAnimated) {
                    foreach ($this->image as $frame) {
                        if ($preserveAspectRatio) {
                            $frame->resizeImage(
                                $width,
                                $height,
                                Imagick::FILTER_LANCZOS,
                                1,
                                true
                            );
                        } else {
                            $frame->resizeImage(
                                $width,
                                $height,
                                Imagick::FILTER_LANCZOS,
                                1
                            );
                        }
                    }
                } else {
                    if ($preserveAspectRatio) {
                        $this->image->resizeImage(
                            $width,
                            $height,
                            Imagick::FILTER_LANCZOS,
                            1,
                            true
                        );
                    } else {
                        $this->image->resizeImage(
                            $width,
                            $height,
                            Imagick::FILTER_LANCZOS,
                            1
                        );
                    }
                }

                $this->width = $this->image->getImageWidth();
                $this->height = $this->image->getImageHeight();
            } catch (\Exception $e) {
                throw ImageException::operationFailed('resize');
            }
        });
    }

    /**
     * Fit image to dimensions
     * 
     * @param int $width Target width
     * @param int $height Target height
     * @param bool $upscale Whether to allow upscaling
     */
    public function fit(
        int $width,
        int $height,
        bool $upscale = false
    ): void {
        $this->withCoalesced(function () use ($width, $height, $upscale) {
            $ratio = $this->width / $this->height;
            $targetRatio = $width / $height;

            if ($ratio > $targetRatio) {
                $newHeight = $height;
                $newWidth = (int) round($height * $ratio);
            } else {
                $newWidth = $width;
                $newHeight = (int) round($width / $ratio);
            }

            if (!$upscale && !$this->isUpscale()) {
                $newWidth = min($newWidth, $this->width);
                $newHeight = min($newHeight, $this->height);
            }

            $this->resize($newWidth, $newHeight, false, $upscale);
            $x = (int) max(0, ($newWidth - $width) / 2);
            $y = (int) max(0, ($newHeight - $height) / 2);
            $this->crop($x, $y, $width, $height);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function resizeCanvas(
        int $width,
        int $height,
        string $position = 'center'
    ): void {
        $this->withCoalesced(function () use (
            $width,
            $height,
            $position
        ) {
            try {
                if ($this->isAnimated) {
                    foreach ($this->image as $frame) {
                        $this->resizeFrameCanvas(
                            $frame,
                            $width,
                            $height,
                            $position
                        );
                    }
                } else {
                    $this->resizeFrameCanvas(
                        $this->image,
                        $width,
                        $height,
                        $position
                    );
                }

                $this->width = $width;
                $this->height = $height;
            } catch (\Exception $e) {
                throw ImageException::operationFailed('resize canvas');
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function crop(
        int $x,
        int $y,
        int $width,
        int $height
    ): void {
        $this->withCoalesced(function () use (
            $x,
            $y,
            $width,
            $height
        ) {
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
            } catch (\Exception $e) {
                throw ImageException::operationFailed('crop');
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function rotate(
        float $angle,
        string $backgroundColor = '#000000'
    ): void {
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
            } catch (\Exception $e) {
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
            } catch (\Exception $e) {
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
            } catch (\Exception $e) {
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
            } catch (\Exception $e) {
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
            } catch (\Exception $e) {
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
            } catch (\Exception $e) {
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
            } catch (\Exception $e) {
                throw ImageException::operationFailed('gamma');
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function invert(): void
    {
        $this->withCoalesced(function () {
            try {
                if ($this->isAnimated) {
                    foreach ($this->image as $frame) {
                        $frame->negateImage(false);
                    }
                } else {
                    $this->image->negateImage(false);
                }
            } catch (\Exception $e) {
                throw ImageException::operationFailed('invert');
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
                $quantumRange = $this->image->getQuantumRange();
                $maxQuantum = $quantumRange['quantumRangeLong'];
                $rValue = ($red / 255.0) * $maxQuantum;
                $gValue = ($green / 255.0) * $maxQuantum;
                $bValue = ($blue / 255.0) * $maxQuantum;

                if ($this->isAnimated) {
                    foreach ($this->image as $frame) {
                        $frame->evaluateImage(
                            \Imagick::EVALUATE_ADD,
                            $rValue,
                            \Imagick::CHANNEL_RED
                        );
                        $frame->evaluateImage(
                            \Imagick::EVALUATE_ADD,
                            $gValue,
                            \Imagick::CHANNEL_GREEN
                        );
                        $frame->evaluateImage(
                            \Imagick::EVALUATE_ADD,
                            $bValue,
                            \Imagick::CHANNEL_BLUE
                        );
                    }
                } else {
                    $this->image->evaluateImage(
                        \Imagick::EVALUATE_ADD,
                        $rValue,
                        \Imagick::CHANNEL_RED
                    );
                    $this->image->evaluateImage(
                        \Imagick::EVALUATE_ADD,
                        $gValue,
                        \Imagick::CHANNEL_GREEN
                    );
                    $this->image->evaluateImage(
                        \Imagick::EVALUATE_ADD,
                        $bValue,
                        \Imagick::CHANNEL_BLUE
                    );
                }
            } catch (\Exception $e) {
                throw ImageException::operationFailed('colorize', $e);
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
            } catch (\Exception $e) {
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
            } catch (\Exception $e) {
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
                        $frame->scaleImage(
                            max(1, $this->width / $size),
                            max(1, $this->height / $size)
                        );
                        $frame->scaleImage(
                            $this->width,
                            $this->height
                        );
                    }
                } else {
                    $this->image->scaleImage(
                        max(1, $this->width / $size),
                        max(1, $this->height / $size)
                    );
                    $this->image->scaleImage(
                        $this->width,
                        $this->height
                    );
                }
            } catch (\Exception $e) {
                throw ImageException::operationFailed('pixelate');
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function watermark(
        $watermark,
        string $position = 'bottom-right',
        int $offsetX = 10,
        int $offsetY = 10
    ): void {
        $this->withCoalesced(function () use (
            $watermark,
            $position,
            $offsetX,
            $offsetY
        ) {
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
                        $frame->compositeImage(
                            $watermarkImage,
                            Imagick::COMPOSITE_OVER,
                            $x,
                            $y
                        );
                    }
                } else {
                    $this->image->compositeImage(
                        $watermarkImage,
                        Imagick::COMPOSITE_OVER,
                        $x,
                        $y
                    );
                }
            } catch (\Exception $e) {
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
        } catch (\Exception $e) {
            throw ImageException::operationFailed('strip exif');
        }
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
        } catch (\Exception $e) {
        }
    }

    /**
     * Prepare image for saving with optimal settings
     * 
     * @param string $format Output format
     * @param int $quality Quality level
     * @return Imagick Prepared image
     */
    private function prepareImageForSave(
        string $format,
        int $quality
    ): Imagick {
        $image = clone $this->image;

        switch ($format) {
            case 'webp':
                return $this->processWebp($image, $quality);
            case 'png':
                return $this->processPng($image, $quality);
            case 'gif':
                return $image;
            case 'bmp':
                return $this->processBmp($image);
            default:
                if ($format !== $this->type) {
                    $image->setImageFormat($format);
                }

                $this->setImageQuality($image, $quality, $format);

                // Optimization for JPEG
                if (in_array($format, ['jpeg', 'jpg'])) {
                    $image->setImageCompression(Imagick::COMPRESSION_JPEG);
                    $image->setInterlaceScheme(Imagick::INTERLACE_PLANE);
                    $image->stripImage();
                }

                return $image;
        }
    }

    /**
     * Process WEBP with forced quality application
     * 
     * @param Imagick $image Image object
     * @param int $quality Quality level
     * @return Imagick Processed image
     */
    private function processWebp(
        Imagick $image,
        int $quality
    ): Imagick {
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
     * Process PNG with proper compression
     * 
     * @param Imagick $image Image object
     * @param int $quality Quality level
     * @return Imagick Processed image
     */
    private function processPng(Imagick $image, int $quality): Imagick
    {
        $pngImage = clone $image;
        $pngImage->setImageFormat('PNG');
        $compressionLevel = $this->getPngQuality($quality);
        $pngImage->setImageCompressionQuality($compressionLevel);
        $pngImage->setImageCompression(Imagick::COMPRESSION_ZIP);
        $pngImage->stripImage();

        if ($pngImage->getImageType() === Imagick::IMGTYPE_TRUECOLOR) {
            $pngImage->setImageType(Imagick::IMGTYPE_PALETTE);
        }

        return $pngImage;
    }

    /**
     * Process BMP image
     * 
     * @param Imagick $image Image object
     * @return Imagick Processed image
     */
    private function processBmp(Imagick $image): Imagick
    {
        $bmpImage = clone $image;
        $bmpImage->setImageFormat('BMP');
        $bmpImage->setImageCompression(Imagick::COMPRESSION_RLE);
        $bmpImage->setImageDepth(8);

        if ($bmpImage->getImageType() === Imagick::IMGTYPE_TRUECOLOR) {
            $bmpImage->quantizeImage(256, Imagick::COLORSPACE_SRGB, 0, false, false);
        }

        $bmpImage->stripImage();

        return $bmpImage;
    }

    /**
     * Set image quality with optimal settings
     * 
     * @param Imagick $image Image object
     * @param int $quality Quality level
     * @param string $format Image format
     */
    private function setImageQuality(
        Imagick $image,
        int $quality,
        string $format
    ): void {
        if (in_array($format, ['jpeg', 'jpg', 'webp'])) {
            $image->setImageCompressionQuality($quality);
        } elseif ($format === 'png') {
            $compression = $this->getPngQuality($quality);
            $image->setImageCompressionQuality($compression);
        }
    }

    /**
     * Resize canvas for single frame
     * 
     * @param Imagick $frame Image frame
     * @param int $width Canvas width
     * @param int $height Canvas height
     * @param string $position Position
     */
    private function resizeFrameCanvas(
        Imagick $frame,
        int $width,
        int $height,
        string $position
    ): void {
        list($x, $y) = $this->calculatePosition($position, $width, $height);

        $frame->extentImage($width, $height, -$x, -$y);
    }
}
