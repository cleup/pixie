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
            $this->setPath($path);

            $this->setMimeType(
                $this->getMimeTypeFromFile($path)
            );

            $this->setExtension(
                $this->getTypeFromMimeType(
                    $this->getMimeType()
                )
            );

            $this->image->setBackgroundColor(
                new ImagickPixel('white')
            );

            $this->image->readImage($path);
            $this->fixBlackImageIssue();

            $this->setIsAnimated(
                $this->image->getNumberImages() > 1
            );

            $this->setWidth(
                $this->image->getImageWidth()
            );

            $this->setHeight(
                $this->image->getImageHeight()
            );

            $this->image->setImageBackgroundColor(
                new ImagickPixel('transparent')
            );

            if (in_array($this->getExtension(), ['jpeg', 'jpg'])) {
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

            $this->setMimeType(
                $this->getMimeTypeFromString($data)
            );

            $this->setExtension(
                $this->getTypeFromMimeType(
                    $this->getMimeType()
                )
            );

            $this->image->readImageBlob($data);
            $this->fixBlackImageIssue();

            $this->setIsAnimated(
                $this->image->getNumberImages() > 1
            );

            $this->setWidth(
                $this->image->getImageWidth()
            );

            $this->setHeight(
                $this->image->getImageHeight()
            );
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
        $format = $format ?: $this->getExtension();
        $format = strtolower($format);

        // Special handling for GIF with gifsicle
        if (
            $format === 'gif' &&
            $this->isEnabledGifsicle() &&
            $this->getGifsicle() !== null
        ) {
            return $this->saveGifOptimally($path, $quality);
        }

        // Normal handling for other formats
        $quality = $this->normalizeQuality($quality, $format);

        try {
            $image = $this->prepareImageForSave($format, $quality);

            if ($this->isAnimated() && $format === 'gif') {
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
    private function saveGifWithColorReduction(
        string $path,
        int $quality
    ): bool {
        $image = clone $this->image;

        try {
            $this->removeAllMetadataReal($image);

            if (!$this->isAnimated()) {
                $currentColors = $image->getImageColors();
                $colors = $this->calculateRealColors(
                    $quality,
                    $currentColors
                );

                if ($currentColors > $colors) {
                    $image->quantizeImage(
                        $colors,
                        Imagick::COLORSPACE_SRGB,
                        0,
                        false,
                        false
                    );
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
                $allFrames->quantizeImage(
                    $colors,
                    Imagick::COLORSPACE_SRGB,
                    0,
                    false,
                    false
                );

                // Apply common palette
                foreach ($image as $frame) {
                    $frame->remapImage(
                        $allFrames,
                        Imagick::DITHERMETHOD_NO
                    );
                    $frame->setCompressionQuality($quality);
                }

                $image->deconstructImages();
                $allFrames->destroy();
                $image->setImageCompression(Imagick::COMPRESSION_LZW);
                $image->optimizeImageLayers();
                $this->removeAllMetadataReal($image);
            }

            // 4. Save
            if ($this->isAnimated()) {
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

        if ($this->isAnimated()) {
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
        $format = $format ?: $this->getExtension();
        $quality = $this->normalizeQuality($quality, $format);

        try {
            // Special handling for GIF with gifsicle
            if (
                $format === 'gif' &&
                $this->isEnabledGifsicle() &&
                $this->getGifsicle() !== null
            ) {
                $tempFile = $this->createTempFilePath();
                $this->saveGifOptimally($tempFile, $quality);

                return $this->getTempFile($tempFile);
            }

            $image = $this->prepareImageForSave($format, $quality);

            if ($this->isAnimated() && $format === 'gif') {
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
        if (!$this->image) {
            if (empty($this->getPath())) {
                throw ImageException::invalidInput();
            } else {
                throw ImageException::invalidImage(
                    $this->getPath()
                );
            }
        }

        $wasCoalesced = false;

        if ($this->isAnimated()) {
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
        $this->image = $this->image->coalesceImages();

        try {
            if (!$upscale) {
                $origWidth = $this->image->getImageWidth();
                $origHeight = $this->image->getImageHeight();

                if ($width > $origWidth || $height > $origHeight) {
                    $width = min($width, $origWidth);
                    $height = min($height, $origHeight);
                }
            }

            do {
                $this->image->resizeImage(
                    $width,
                    $height,
                    Imagick::FILTER_LANCZOS,
                    1,
                    $preserveAspectRatio
                );

                $currentW = $this->image->getImageWidth();
                $currentH = $this->image->getImageHeight();

                $this->image->setImagePage($currentW, $currentH, 0, 0);
            } while ($this->image->nextImage());

            $this->image = $this->image->deconstructImages();

            $this->setWidth(
                $this->image->getImageWidth()
            );

            $this->setHeight(
                $this->image->getImageHeight()
            );
        } catch (ImageException $e) {
            throw ImageException::operationFailed('resize');
        }
    }


    /**
     * Fit image to dimensions (обрезает до указанных размеров)
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
            $currentWidth = $this->image->getImageWidth();
            $currentHeight = $this->image->getImageHeight();
            $scale = max($width / $currentWidth, $height / $currentHeight);

            if (!$upscale && $scale > 1.0) {
                $scale = 1.0;
            }

            $newWidth = (int) round($currentWidth * $scale);
            $newHeight = (int) round($currentHeight * $scale);
            $newWidth = max(1, $newWidth);
            $newHeight = max(1, $newHeight);

            $this->resize($newWidth, $newHeight, true, true);

            $x = max(0, (int) round(($newWidth - $width) / 2));
            $y = max(0, (int) round(($newHeight - $height) / 2));
            $cropWidth = min($width, $newWidth);
            $cropHeight = min($height, $newHeight);

            $this->crop($x, $y, $cropWidth, $cropHeight);
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
        $this->image = $this->image->coalesceImages();

        try {
            $this->image->setFirstIterator();
            do {
                $this->resizeFrameCanvas(
                    $this->image,
                    $width,
                    $height,
                    $position
                );

                $this->image->setImagePage($width, $height, 0, 0);
            } while ($this->image->nextImage());

            $this->image = $this->image->optimizeImageLayers();
            $this->setWidth($width);
            $this->setHeight($height);
        } catch (\Exception $e) {
            throw ImageException::operationFailed('resize canvas');
        }
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
                if ($this->isAnimated()) {
                    foreach ($this->image as $frame) {
                        $frame->cropImage($width, $height, $x, $y);
                        $frame->setImagePage(0, 0, 0, 0);
                    }
                } else {
                    $this->image->cropImage($width, $height, $x, $y);
                    $this->image->setImagePage(0, 0, 0, 0);
                }

                $this->setWidth($width);
                $this->setHeight($height);
            } catch (\Exception $e) {
                throw ImageException::operationFailed('crop');
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    /**
     * {@inheritdoc}
     */
    public function rotate(
        float $angle,
        string $backgroundColor = 'transparent'
    ): void {

        $this->withCoalesced(function () use ($angle, $backgroundColor) {
            try {
                $correctedAngle = -$angle;

                if (strtolower($backgroundColor) === 'transparent') {
                    $background = 'none';
                    $pixel = new ImagickPixel($background);
                } else {
                    $pixel = new ImagickPixel($backgroundColor);
                }

                if ($this->isAnimated()) {
                    foreach ($this->image as $frame) {
                        $frame->rotateImage($pixel, $correctedAngle);
                        $frame->setImagePage(
                            $frame->getImageWidth(),
                            $frame->getImageHeight(),
                            0,
                            0
                        );
                    }
                } else {
                    $this->image->rotateImage($pixel, $correctedAngle);
                }

                $this->setWidth(
                    $this->image->getImageWidth()
                );

                $this->setHeight(
                    $this->image->getImageHeight()
                );
            } catch (\Exception $e) {
                throw ImageException::operationFailed('rotate', $e);
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
                if ($this->isAnimated()) {
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
        if ($this->isAnimated()) {
            $this->applyBlurToAnimatedGif($amount);
        } else {
            $this->applyBlurToStaticImage($amount);
        }
    }

    /**
     * Apply blur to static image
     * 
     * @param int $amount
     */
    private function applyBlurToStaticImage(int $amount): void
    {
        try {
            $radius = min(10, max(1, $amount));
            $sigma = min(5, max(0.5, $amount * 0.7));

            $this->image->blurImage($radius, $sigma);
        } catch (\Exception $e) {
            throw ImageException::operationFailed('blur');
        }
    }

    /**
     * Apply blur to animated GIF
     * 
     * @param int $amount
     */
    private function applyBlurToAnimatedGif(int $amount): void
    {
        try {
            $image = clone $this->image;
            $image = $image->coalesceImages();

            foreach ($image as $frame) {
                $radius = min(5, max(1, $amount));
                $sigma = min(3, max(0.5, $amount * 0.5));

                // Применяем размытие к текущему кадру
                $frame->blurImage($radius, $sigma);

                // Обновляем границы кадра
                $frame->setImagePage(
                    $frame->getImageWidth(),
                    $frame->getImageHeight(),
                    0,
                    0
                );
            }

            $image = $image->deconstructImages();

            $this->image->destroy();
            $this->image = $image;

            $this->setWidth(
                $this->image->getImageWidth()
            );

            $this->setHeight(
                $this->image->getImageHeight()
            );
        } catch (\Exception $e) {
            throw ImageException::operationFailed('blur');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function sharpen(int $amount = 1): void
    {
        $this->withCoalesced(function () use ($amount) {
            try {
                if ($this->isAnimated()) {
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
                if ($this->isAnimated()) {
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
                if ($this->isAnimated()) {
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
                if ($this->isAnimated()) {
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
                if ($this->isAnimated()) {
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

                if ($this->isAnimated()) {
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
                if ($this->isAnimated()) {
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
                if ($this->isAnimated()) {
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
                if ($this->isAnimated()) {
                    foreach ($this->image as $frame) {
                        $frame->scaleImage(
                            max(1, $this->getWidth() / $size),
                            max(1, $this->getHeight() / $size)
                        );
                        $frame->scaleImage(
                            $this->getWidth(),
                            $this->getHeight()
                        );
                    }
                } else {
                    $this->image->scaleImage(
                        max(1, $this->getWidth() / $size),
                        max(1, $this->getHeight() / $size)
                    );
                    $this->image->scaleImage(
                        $this->getWidth(),
                        $this->getHeight()
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
                    $this->getWidth(),
                    $this->getHeight(),
                    $wmWidth,
                    $wmHeight,
                    $offsetX,
                    $offsetY
                );

                if ($this->isAnimated()) {
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
                if ($format !== $this->getExtension()) {
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
        $image->setImageFormat('WEBP');
        $image->setOption('webp:method', $this->getWebpMethod($quality));
        $image->setOption('webp:lossless', 'false');
        $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_OPTIMIZEPLUS);
        $image->setImageCompressionQuality($quality);

        return $image;
    }

    /**
     * Determine WEBP encoding method based on quality level
     * 
     * @param int $quality Image quality level (0-100)
     * @return int
     */
    private function getWebpMethod(int $quality): int
    {
        if ($quality <= 30) {
            return 6;
        } elseif ($quality <= 70) {
            return 5;
        }
        return 4;
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
            $bmpImage->quantizeImage(
                256,
                Imagick::COLORSPACE_SRGB,
                0,
                false,
                false
            );
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
        $frameWidth = $frame->getImageWidth();
        $frameHeight = $frame->getImageHeight();

        list($x, $y) = $this->calculatePosition(
            $position,
            $width,
            $height,
            $frameWidth,
            $frameHeight
        );

        $frame->extentImage(
            $width,
            $height,
            -(int)$x,
            -(int)$y
        );
    }
}
