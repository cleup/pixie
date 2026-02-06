<?php

namespace Cleup\Pixie\Drivers\GD;

use Cleup\Pixie\Driver;
use Cleup\Pixie\Exceptions\ImageException;
use GdImage;

class GDDriver extends Driver
{
    /** 
     * @var ?GdImage Main GD image resource
     */
    private ?GdImage $image = null;

    /**
     * @var array Individual frames for animated GIFs
     */
    private array $frames = [];

    /**
     * @var array GIF-specific metadata and frame data
     */
    private array $gif = [];

    /**
     * {@inheritdoc}
     */
    public function loadFromPath(string $path): void
    {
        if (!file_exists($path)) {
            throw ImageException::fileNotFound($path);
        }

        $mimeType = $this->getMimeTypeFromFile($path);
        $extension = $this->getExtensionFromMimeType($mimeType);

        $this->setPath($path);
        $this->setMimeType($mimeType);
        $this->setExtension($extension);

        // Для WEBP файлов сначала проверяем, не анимированный ли он
        if ($extension === 'webp') {
            if ($this->isAnimatedWebp($path)) {
                throw new ImageException(
                    'Animated WEBP files are not supported by GD driver'
                );
            }
        }

        $imageResource = null;

        switch ($extension) {
            case 'gif':
                $imageResource = imagecreatefromgif($path);
                break;
            case 'jpeg':
            case 'jpg':
                $imageResource = imagecreatefromjpeg($path);
                break;
            case 'png':
                $imageResource = imagecreatefrompng($path);
                break;
            case 'webp':
                $imageResource = @imagecreatefromwebp($path);
                break;
            case 'bmp':
                $imageResource = imagecreatefrombmp($path);
                break;
            default:
                throw ImageException::unsupportedFormat($extension);
        }

        if (!$imageResource || !($imageResource instanceof \GdImage)) {
            if (empty($this->getPath())) {
                throw ImageException::invalidInput();
            } else {
                throw ImageException::invalidImage(
                    $this->getPath()
                );
            }
        }

        $this->image = $imageResource;

        $this->setWidth(
            imagesx($this->image)
        );

        $this->setHeight(
            imagesy($this->image)
        );

        $this->preserveTransparency();
    }

    /**
     * Check if WEBP file is animated
     * 
     * @param string $path Path to WEBP file
     * @return bool
     */
    private function isAnimatedWebp(string $path): bool
    {
        $contents = file_get_contents($path);
        if (!$contents) {
            return false;
        }

        // Check for animated WEBP format
        // Animated WEBP has 'ANIM' chunk
        $pattern = '/ANIM|ANMF/';
        return preg_match($pattern, $contents) === 1;
    }

    /**
     * Load all GIF frames
     * 
     * @param string $path GIF file path
     */
    private function loadGifFrames(string $path): void
    {
        if (!$this->getGifsicle()) {
            return;
        }

        try {
            $tempFile = $this->createTempFilePath();

            $this->gif = $this
                ->getGifsicle()
                ->getInfo($path);

            copy($path, $tempFile);

            $this->getGifsicle()->setPath($tempFile);

            $frameFiles = $this
                ->getGifsicle()
                ->extractFrames($tempFile);

            foreach ($frameFiles as $frameFile) {
                $frame = imagecreatefromgif($frameFile);
                if ($frame) {
                    $this->frames[] = $frame;
                }
                @unlink($frameFile);
            }

            @unlink($tempFile);
        } catch (\Exception $e) {
        }
    }

    /**
     * {@inheritdoc}
     */
    public function loadFromString(string $data): void
    {
        $tempFile = $this->createTempFilePath();
        file_put_contents($tempFile, $data);
        $this->loadFromPath($tempFile);
    }

    /**
     * {@inheritdoc}
     */
    public function save(
        string $path,
        ?int $quality = null,
        ?string $format = null
    ): bool {
        $format = strtolower($format ?: $this->getExtension());

        // Create directory if doesn't exist
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Special handling for animated GIF with gifsicle
        if (
            $format === 'gif' &&
            $this->isEnabledGifsicle() &&
            $this->getGifsicle()
        ) {
            if (empty($this->gif))
                $this->loadGifFrames(
                    $this->getPath()
                );

            return $this->saveWithGifsicle($path, $quality);
        }

        // Normal save for single frame images
        return $this->saveImage(
            $path,
            $quality,
            $format
        );
    }

    /**
     * Save animated GIF with Gifsicle
     * 
     * @param string $path Output file path
     * @param int|null $quality Quality level
     * @return bool
     */
    private function saveWithGifsicle(
        string $path,
        ?int $quality = null
    ): bool {
        if (empty($this->frames)) {
            return false;
        }

        try {
            $tempDir = sys_get_temp_dir() . '/gif-temp-' . uniqid();
            mkdir($tempDir, 0755, true);
            $frameFiles = [];

            foreach ($this->frames as $i => $frame) {
                $frameFile = $tempDir . '/frame_' . sprintf('%03d', $i) . '.gif';
                imagegif($frame, $frameFile);
                $frameFiles[] = $frameFile;
            }

            if (empty($frameFiles)) {
                return false;
            }

            $tempOutput = $tempDir . '/output.gif';
            $framesWithOptions = [];

            if (
                !empty($this->gif['delays']) &&
                count($this->gif['delays']) === count($frameFiles)
            ) {
                foreach ($frameFiles as $key => $file) {
                    $framesWithOptions[] = [
                        'path' => $file,
                        'delay' => intval(($this->gif['delays'][$key] ?? 0) * 100)
                    ];
                }
            }

            $this->getGifsicle()->optimize(null, $tempOutput, [
                'optimizationLevel' => 3,
                'colors' => $this->calculateRealColors($quality),
                'merge' => !empty($framesWithOptions)
                    ? $framesWithOptions
                    : $frameFiles,
                'loopcount' => $this->gif['loopCount'] ?? null,
                'careful' => true,
            ]);

            if (!@copy($tempOutput, $path)) {
                throw ImageException::directoryNotFound(dirname($path));
            }

            // Cleanup on error
            foreach ($frameFiles as $frameFile) {
                @unlink($frameFile);
            }

            @unlink($tempOutput);
            @rmdir($tempDir);

            return true;
        } catch (\Exception $e) {
            return $this->saveOptimizedGifSingle($path, $quality);
        }
    }

    /**
     * Optimize frame with quality settings
     * 
     * @param GdImage $frame GD image resource
     * @param int|null $quality Quality level
     * @return GdImage Optimized frame
     */
    private function optimizeFrameWithQuality($frame, ?int $quality)
    {
        $width = imagesx($frame);
        $height = imagesy($frame);

        $optimized = imagecreatetruecolor($width, $height);

        // Preserve transparency
        imagealphablending($optimized, false);
        imagesavealpha($optimized, true);

        // Copy frame
        imagecopy($optimized, $frame, 0, 0, 0, 0, $width, $height);

        // Determine color count based on quality
        $colorCount = $this->calculateRealColors($quality);

        // Convert to palette with optimized color count
        if (imageistruecolor($optimized)) {
            imagetruecolortopalette($optimized, true, $colorCount);
        } else {
            // Already palette image, reduce colors if needed
            $currentColors = imagecolorstotal($optimized);
            if ($currentColors > $colorCount) {
                imagetruecolortopalette($optimized, true, $colorCount);
            }
        }

        return $optimized;
    }

    /**
     * Save optimized single GIF
     * 
     * @param string $path Output file path
     * @param int|null $quality Quality level
     * @return bool
     */
    private function saveOptimizedGifSingle(
        string $path,
        ?int $quality = null
    ): bool {
        // Optimize the image with quality settings
        $optimized = $this->optimizeFrameWithQuality($this->image, $quality, true);
        $tempFile = $this->createTempFilePath();
        imagegif($optimized, $tempFile);

        // Apply additional optimization with gifsicle
        if ($this->getGifsicle()) {
            $tempOptimized = $tempFile . '_opt.gif';

            $options = [
                'optimizationLevel' => 3,
                'colors' => $this->calculateRealColors($quality),
                'no-comments' => true,
                'no-extensions' => true,
                'careful' => true,
            ];

            if ($this->getGifsicle()->optimize($tempFile, $tempOptimized, $options)) {
                if (!@copy($tempOptimized, $path)) {
                    throw ImageException::directoryNotFound(dirname($path));
                }
            } else {
                if (!@copy($tempFile, $path)) {
                    throw ImageException::directoryNotFound(dirname($path));
                }
            }
        } else {
            if (!@copy($tempFile, $path)) {
                throw ImageException::directoryNotFound(dirname($path));
            }
        }

        @unlink($tempFile);
        return true;
    }

    /**
     * Save single image
     * 
     * @param string $path Output file path
     * @param int|null $quality Quality level
     * @param string $format Image format
     * @return bool
     */
    private function saveImage(
        string $path,
        ?int $quality,
        string $format
    ): bool {
        $quality = $this->normalizeQuality($quality, $format);
        $this->preserveTransparency();

        switch ($format) {
            case 'jpg':
            case 'jpeg':
                return imagejpeg($this->image, $path, $quality);
            case 'png':
                $compression = $this->getPngQuality($quality);
                return imagepng($this->image, $path, $compression);
            case 'gif':
                // Save optimized GIF with quality
                return $this->saveOptimizedGifSingle($path, $quality);
            case 'webp':
                return imagewebp($this->image, $path, $quality);
            case 'bmp':
                return imagebmp($this->image, $path);
            default:
                throw ImageException::unsupportedFormat($format);
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
        return $this->saveOptimizedGifSingle($path, $quality);
    }

    /**
     * {@inheritdoc}
     */
    public function getString(
        ?string $format = null,
        ?int $quality = null
    ): string {
        $format = $format ?: $this->getExtension();

        if (
            $format === 'gif' &&
            $this->isEnabledGifsicle() &&
            $this->getGifsicle()
        ) {
            if (empty($this->gif))
                $this->loadGifFrames(
                    $this->getPath()
                );

            if ($this->saveWithGifsicle(
                $this->getPath(),
                $quality
            )) {
                return $this->getTempFile(
                    $this->getPath(),
                    false
                );
            }
        }

        $quality = $this->normalizeQuality(
            $quality,
            $format
        );

        $this->preserveTransparency();

        ob_start();

        switch ($format) {
            case 'jpg':
            case 'jpeg':
                imagejpeg($this->image, null, $quality);
                break;
            case 'png':
                imagepng(
                    $this->image,
                    null,
                    $this->getPngQuality($quality)
                );
                break;
            case 'gif':
                $tempFile = $this->createTempFilePath();

                if (
                    $this->saveOptimizedGifSingle(
                        $tempFile,
                        $quality
                    )
                ) {
                    return $this->getTempFile($tempFile);
                }

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
     * Apply operation to all frames
     * 
     * @param callable $operation Operation to apply
     */
    private function applyToAllFrames(callable $operation): void
    {
        if (!$this->image) {
            throw ImageException::invalidImage(
                $this->getPath()
            );
        }

        if (
            $this->getExtension() === 'gif' &&
            empty($this->gif) &&
            $this->isEnabledGifsicle() &&
            $this->getGifsicle()
        ) {
            $this->loadGifFrames(
                $this->getPath()
            );
        }

        if (
            !empty($this->frames) &&
            is_array($this->frames) &&
            count($this->frames) > 0
        ) {
            foreach ($this->frames as &$frame) {
                if (
                    !$frame instanceof \GdImage
                    && !is_resource($frame)
                ) {
                    continue;
                }

                $operation($frame);
            }

            if (!empty($this->frames[0])) {
                $this->image = $this->frames[0];

                $this->setWidth(
                    imagesx($this->image)
                );

                $this->setHeight(
                    imagesy($this->image)
                );
            }
        } else {
            $operation($this->image);

            $this->setWidth(
                imagesx($this->image)
            );

            $this->setHeight(
                imagesy($this->image)
            );
        }
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
        $this->applyToAllFrames(function (&$frame) use (
            $width,
            $height,
            $preserveAspectRatio,
            $upscale
        ) {
            $this->applyResizeToFrame(
                $frame,
                $width,
                $height,
                $preserveAspectRatio,
                $upscale
            );
        });
    }

    /**
     * Apply resize to frame
     * 
     * @param GdImage $frame GD image resource
     * @param int $width Target width
     * @param int $height Target height
     * @param bool $preserveAspectRatio Whether to preserve aspect ratio
     * @param bool $upscale Whether to allow upscaling
     */
    private function applyResizeToFrame(
        &$frame,
        int $width,
        int $height,
        bool $preserveAspectRatio,
        bool $upscale
    ): void {
        $frameWidth = imagesx($frame);
        $frameHeight = imagesy($frame);

        if (!$upscale && !$this->isUpscale()) {
            $width = min($width, $frameWidth);
            $height = min($height, $frameHeight);
        }

        if ($preserveAspectRatio) {
            $ratio = min($width / $frameWidth, $height / $frameHeight);
            $newWidth = (int) round($frameWidth * $ratio);
            $newHeight = (int) round($frameHeight * $ratio);
        } else {
            $newWidth = $width;
            $newHeight = $height;
        }

        $newImage = imagecreatetruecolor($newWidth, $newHeight);
        $this->preserveTransparency($newImage);

        imagecopyresampled(
            $newImage,
            $frame,
            0,
            0,
            0,
            0,
            $newWidth,
            $newHeight,
            $frameWidth,
            $frameHeight
        );

        $frame = $newImage;
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
        $this->applyToAllFrames(function (&$frame) use (
            $width,
            $height,
            $upscale
        ) {
            $this->applyFitToFrame(
                $frame,
                $width,
                $height,
                $upscale
            );
        });
    }

    /**
     * Apply fit to frame
     * 
     * @param GdImage $frame GD image resource
     * @param int $width Target width
     * @param int $height Target height
     * @param bool $upscale Whether to allow upscaling
     */
    private function applyFitToFrame(
        &$frame,
        int $width,
        int $height,
        bool $upscale
    ): void {
        $frameWidth = imagesx($frame);
        $frameHeight = imagesy($frame);

        $ratio = $frameWidth / $frameHeight;
        $targetRatio = $width / $height;

        if ($ratio > $targetRatio) {
            $newHeight = $height;
            $newWidth = (int) round($height * $ratio);
        } else {
            $newWidth = $width;
            $newHeight = (int) round($width / $ratio);
        }

        if (!$upscale && !$this->isUpscale()) {
            $newWidth = min($newWidth, $frameWidth);
            $newHeight = min($newHeight, $frameHeight);
        }

        // Apply resize
        $this->applyResizeToFrame(
            $frame,
            $newWidth,
            $newHeight,
            false,
            $upscale
        );

        // Update dimensions after resize
        $frameWidth = imagesx($frame);
        $frameHeight = imagesy($frame);

        $x = (int) max(0, ($frameWidth - $width) / 2);
        $y = (int) max(0, ($frameHeight - $height) / 2);

        // Apply crop
        [$newWidth, $newHeight] =  $this->applyCropToFrame(
            $frame,
            $x,
            $y,
            $width,
            $height
        );

        $this->setWidth($newWidth);
        $this->setHeight($newHeight);
    }

    /**
     * {@inheritdoc}
     */
    public function resizeCanvas(
        int $width,
        int $height,
        string $position = 'center'
    ): void {
        $this->applyToAllFrames(function (&$frame) use (
            $width,
            $height,
            $position
        ) {
            $this->applyResizeCanvasToFrame(
                $frame,
                $width,
                $height,
                $position
            );
        });
    }

    /**
     * Apply resize canvas to frame
     * 
     * @param GdImage $frame GD image resource
     * @param int $width Canvas width
     * @param int $height Canvas height
     * @param string $position Position
     */
    private function applyResizeCanvasToFrame(
        &$frame,
        int $width,
        int $height,
        string $position
    ): void {
        $frameWidth = imagesx($frame);
        $frameHeight = imagesy($frame);

        $newImage = imagecreatetruecolor($width, $height);
        $this->preserveTransparency($newImage, true);

        list($x, $y) = $this->calculatePosition(
            $position,
            $width,
            $height,
            $frameWidth,
            $frameHeight
        );
        imagecopy(
            $newImage,
            $frame,
            $x,
            $y,
            0,
            0,
            $frameWidth,
            $frameHeight
        );
        $frame = $newImage;
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
        $newWidth = $width;
        $newHeight = $height;

        $this->applyToAllFrames(function (&$frame) use (
            $x,
            $y,
            $width,
            $height,
            &$newWidth,
            &$newHeight
        ) {
            $sizes = $this->applyCropToFrame($frame, $x, $y, $width, $height);
            $newWidth = $sizes[0];
            $newHeight = $sizes[1];
        });

        $this->setWidth($newWidth);
        $this->setHeight($newHeight);
    }

    /**
     * Apply crop to frame
     * 
     * @param GdImage $frame GD image resource
     * @param int $x Start X coordinate
     * @param int $y Start Y coordinate
     * @param int $width Crop width
     * @param int $height Crop height
     * @return array Новые размеры изображения [width, height]
     */
    private function applyCropToFrame(
        &$frame,
        int $x,
        int $y,
        int $width,
        int $height
    ): array {
        $srcWidth = imagesx($frame);
        $srcHeight = imagesy($frame);
        $srcX = max(0, $x);
        $srcY = max(0, $y);
        $srcEndX = min($srcWidth, $x + $width);
        $srcEndY = min($srcHeight, $y + $height);
        $newWidth = max(0, $srcEndX - $srcX);
        $newHeight = max(0, $srcEndY - $srcY);

        if ($newWidth <= 0 || $newHeight <= 0) {
            $newWidth = 1;
            $newHeight = 1;
            $newImage = imagecreatetruecolor($newWidth, $newHeight);

            $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
            imagefill($newImage, 0, 0, $transparent);
            imagesavealpha($newImage, true);

            if ($this->getExtension() === 'gif') {
                imagetruecolortopalette($newImage, true, 256);
            }

            $frame = $newImage;
            return [$newWidth, $newHeight];
        }

        if (function_exists('imagecrop')) {
            $rect = [
                'x' => $srcX,
                'y' => $srcY,
                'width' => $newWidth,
                'height' => $newHeight
            ];

            $cropped = imagecrop($frame, $rect);

            if ($cropped !== false) {
                $frame = $cropped;
                return [$newWidth, $newHeight];
            }
        }

        $newImage = imagecreatetruecolor($newWidth, $newHeight);
        $transparentIndex = imagecolortransparent($frame);

        if ($transparentIndex >= 0 && $this->getExtension() === 'gif') {
            $transparentColor = imagecolorsforindex($frame, $transparentIndex);
            $transparentIndexNew = imagecolorallocate(
                $newImage,
                $transparentColor['red'],
                $transparentColor['green'],
                $transparentColor['blue']
            );
            imagefill($newImage, 0, 0, $transparentIndexNew);
            imagecolortransparent($newImage, $transparentIndexNew);
        } else {
            $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
            imagefill($newImage, 0, 0, $transparent);
            imagesavealpha($newImage, true);
        }

        imagecopy($newImage, $frame, 0, 0, $srcX, $srcY, $newWidth, $newHeight);

        if ($this->getExtension() === 'gif') {
            imagetruecolortopalette($newImage, true, 256);
        }

        $frame = $newImage;

        return [$newWidth, $newHeight];
    }

    /**
     * {@inheritdoc}
     */
    public function rotate(
        float $angle,
        string $backgroundColor = 'transparent'
    ): void {
        $this->applyToAllFrames(function (&$frame) use (
            $angle,
            $backgroundColor
        ) {
            $angle = fmod($angle, 360);
            if ($angle < 0) $angle += 360;
            if ($angle == 0) return;

            $srcWidth = imagesx($frame);
            $srcHeight = imagesy($frame);
            $radians = deg2rad($angle);
            $cos = cos($radians);
            $sin = sin($radians);
            $newWidth = abs($srcWidth * $cos) + abs($srcHeight * $sin);
            $newHeight = abs($srcWidth * $sin) + abs($srcHeight * $cos);
            $rotated = imagecreatetruecolor((int)round($newWidth), (int)round($newHeight));

            if (strtolower($backgroundColor) === 'transparent') {
                imagesavealpha($rotated, true);
                $background = imagecolorallocatealpha($rotated, 0, 0, 0, 127);
                imagefill($rotated, 0, 0, $background);
            } else {
                $rgb = $this->hexToRgb($backgroundColor);
                $background = imagecolorallocate($rotated, $rgb[0], $rgb[1], $rgb[2]);
                imagefill($rotated, 0, 0, $background);
            }

            $transparentColor = imagecolortransparent($frame);
            if ($transparentColor == -1) {
                $transparentColor = $background;
            }

            $rotatedResource = imagerotate($frame, $angle, $transparentColor);
            $rotatedWidth = imagesx($rotatedResource);
            $rotatedHeight = imagesy($rotatedResource);
            $offsetX = (int)(($newWidth - $rotatedWidth) / 2);
            $offsetY = (int)(($newHeight - $rotatedHeight) / 2);

            imagecopy($rotated, $rotatedResource, $offsetX, $offsetY, 0, 0, $rotatedWidth, $rotatedHeight);

            if (strtolower($backgroundColor) === 'transparent') {
                imagesavealpha($rotated, true);
            }

            $frame = $rotated;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function flip(string $mode = 'horizontal'): void
    {
        $this->applyToAllFrames(function (&$frame) use ($mode) {
            $this->applyFlipToFrame($frame, $mode);
        });
    }

    /**
     * Apply flip to frame
     * 
     * @param GdImage $frame GD image resource
     * @param string $mode Flip mode
     */
    private function applyFlipToFrame(&$frame, string $mode): void
    {
        $frameWidth = imagesx($frame);
        $frameHeight = imagesy($frame);

        $newImage = imagecreatetruecolor($frameWidth, $frameHeight);
        $this->preserveTransparency($newImage);

        switch ($mode) {
            case 'horizontal':
                for ($x = 0; $x < $frameWidth; $x++) {
                    imagecopy(
                        $newImage,
                        $frame,
                        $x,
                        0,
                        $frameWidth - $x - 1,
                        0,
                        1,
                        $frameHeight
                    );
                }
                break;
            case 'vertical':
                for ($y = 0; $y < $frameHeight; $y++) {
                    imagecopy(
                        $newImage,
                        $frame,
                        0,
                        $y,
                        0,
                        $frameHeight - $y - 1,
                        $frameWidth,
                        1
                    );
                }
                break;
            default:
                throw new \InvalidArgumentException("Invalid flip mode: {$mode}");
        }

        $frame = $newImage;
    }

    /**
     * {@inheritdoc}
     */
    public function blur(int $amount = 1): void
    {
        $this->applyToAllFrames(function (&$frame) use ($amount) {
            for ($i = 0; $i < $amount; $i++) {
                imagefilter($frame, IMG_FILTER_GAUSSIAN_BLUR);
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function sharpen(int $amount = 1): void
    {
        $this->applyToAllFrames(function (&$frame) use ($amount) {

            $strength = $amount / 10;

            $matrix = [
                [-$strength, -$strength, -$strength],
                [-$strength, 8 * $strength + 1, -$strength],
                [-$strength, -$strength, -$strength]
            ];

            $sum = 0;
            foreach ($matrix as $row) {
                foreach ($row as $value) {
                    $sum += $value;
                }
            }

            if (abs($sum) < 0.001) $sum = 1;

            imageconvolution($frame, $matrix, $sum, 0);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function brightness(int $level): void
    {
        $this->applyToAllFrames(function (&$frame) use ($level) {
            imagefilter($frame, IMG_FILTER_BRIGHTNESS, $level);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function contrast(int $level): void
    {
        $this->applyToAllFrames(function (&$frame) use ($level) {
            imagefilter($frame, IMG_FILTER_CONTRAST, $level);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function gamma(float $correction): void
    {
        $this->applyToAllFrames(function (&$frame) use ($correction) {
            imagegammacorrect($frame, 1.0, $correction);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function invert(): void
    {
        $this->applyToAllFrames(function (&$frame) {
            imagefilter(
                $frame,
                IMG_FILTER_NEGATE
            );
        });
    }

    /**
     * {@inheritdoc}
     */
    public function colorize(int $red, int $green, int $blue): void
    {
        $this->applyToAllFrames(function (&$frame) use ($red, $green, $blue) {
            imagefilter($frame, IMG_FILTER_COLORIZE, $red, $green, $blue);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function greyscale(): void
    {
        $this->applyToAllFrames(function (&$frame) {
            imagefilter($frame, IMG_FILTER_GRAYSCALE);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function sepia(): void
    {
        $this->applyToAllFrames(function (&$frame) {
            $width = imagesx($frame);
            $height = imagesy($frame);

            $temp = imagecreatetruecolor($width, $height);
            imagealphablending($temp, false);
            imagesavealpha($temp, true);
            imagecopy($temp, $frame, 0, 0, 0, 0, $width, $height);

            $threshold = 0.8 * 255;

            for ($x = 0; $x < $width; $x++) {
                for ($y = 0; $y < $height; $y++) {
                    $color = imagecolorat($temp, $x, $y);

                    $a = ($color >> 24) & 0xFF;
                    $r = ($color >> 16) & 0xFF;
                    $g = ($color >> 8) & 0xFF;
                    $b = $color & 0xFF;

                    $intensity = ($r + $g + $b) / 3.0;
                    $sepiaR = min(255, $intensity * 1.07);
                    $sepiaG = min(255, $intensity * 0.74);
                    $sepiaB = min(255, $intensity * 0.43);

                    if ($intensity > $threshold) {
                        $factor = ($intensity - $threshold) / (255 - $threshold);
                        $sepiaR = $sepiaR * (1 - $factor) + $r * $factor;
                        $sepiaG = $sepiaG * (1 - $factor) + $g * $factor;
                        $sepiaB = $sepiaB * (1 - $factor) + $b * $factor;
                    }

                    $newColor = imagecolorallocatealpha(
                        $temp,
                        (int)$sepiaR,
                        (int)$sepiaG,
                        (int)$sepiaB,
                        $a
                    );

                    imagesetpixel($temp, $x, $y, $newColor);
                    imagecolordeallocate($temp, $newColor);
                }
            }

            imagecopy($frame, $temp, 0, 0, 0, 0, $width, $height);
            imagefilter($frame, IMG_FILTER_BRIGHTNESS, 5);
        });
    }
    /**
     * {@inheritdoc}
     */
    public function pixelate(int $size): void
    {
        $this->applyToAllFrames(function (&$frame) use ($size) {
            imagefilter($frame, IMG_FILTER_PIXELATE, $size, true);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function watermark($watermark, string $position = 'bottom-right', int $offsetX = 10, int $offsetY = 10): void
    {
        // Load watermark once
        if (is_string($watermark)) {
            $wmImage = imagecreatefromstring(
                file_get_contents($watermark)
            );
            $wmWidth = imagesx($wmImage);
            $wmHeight = imagesy($wmImage);
        } else {
            $wmImage = $watermark;
            $wmWidth = imagesx($wmImage);
            $wmHeight = imagesy($wmImage);
        }

        $this->applyToAllFrames(function (&$frame) use (
            $wmImage,
            $position,
            $offsetX,
            $offsetY,
            $wmWidth,
            $wmHeight
        ) {
            $this->applyWatermarkToFrame(
                $frame,
                $wmImage,
                $position,
                $offsetX,
                $offsetY,
                $wmWidth,
                $wmHeight
            );
        });
    }

    /**
     * Apply watermark to frame
     * 
     * @param GdImage $frame GD image resource
     * @param GdImage $wmImage Watermark image resource
     * @param string $position Position
     * @param int $offsetX Horizontal offset
     * @param int $offsetY Vertical offset
     * @param int $wmWidth Watermark width
     * @param int $wmHeight Watermark height
     */
    private function applyWatermarkToFrame(
        &$frame,
        $wmImage,
        string $position,
        int $offsetX,
        int $offsetY,
        int $wmWidth,
        int $wmHeight
    ): void {
        $frameWidth = imagesx($frame);
        $frameHeight = imagesy($frame);

        list($x, $y) = $this->calculatePosition(
            $position,
            $frameWidth,
            $frameHeight,
            $wmWidth,
            $wmHeight,
            $offsetX,
            $offsetY
        );

        imagealphablending($frame, true);
        imagecopy(
            $frame,
            $wmImage,
            $x,
            $y,
            0,
            0,
            $wmWidth,
            $wmHeight
        );
    }

    /**
     * {@inheritdoc}
     */
    public function stripExif(): void {}

    /**
     * Preserve transparency for PNG and GIF
     * 
     * @param GdImage|null $image GD image resource
     * @param bool $fillTransparent Whether to fill with transparent color
     */
    private function preserveTransparency(
        $image = null,
        bool $fillTransparent = false
    ): void {
        $target = $image ?: $this->image;

        if (
            in_array(
                $this->getExtension(),
                ['png', 'gif', 'webp']
            )
        ) {
            imagealphablending($target, false);
            imagesavealpha($target, true);

            if ($fillTransparent && $image !== null) {
                $transparent = imagecolorallocatealpha(
                    $target,
                    255,
                    255,
                    255,
                    127
                );
                imagefill($target, 0, 0, $transparent);
            }
        } else {
            imagealphablending($target, true);
        }
    }
}
