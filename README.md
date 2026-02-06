# Pixie - Modern PHP Image Manipulation Library

A powerful, flexible, and easy-to-use image manipulation library for PHP with support for both GD and Imagick drivers. Perfect for handling image processing tasks with excellent quality preservation and animation support.

## Features

- 🖼️ Dual Driver Support - Choose between GD or Imagick based on your needs
- 🎞️ Animation Support - Full animated GIF support
- 🎯 High Quality - Excellent quality preservation with optimized algorithms
- 📐 Multiple Operations - Resize, crop, rotate, flip, filters, and more
- 💧 Transparency Support - Full alpha channel support for PNG and GIF
- 🚀 Performance Optimized - Efficient memory usage and processing

## Installation

```bash
composer require cleup/pixie
```

## Requirements

- PHP 8.1 or higher
- GD extension (for GD driver)
- Imagick extension (for Imagick driver - recommended for advanced features)
- Gifsicle for better optimization of GIF images

## Recommendation

If you plan to work with GIF images and want to optimize them or add animation support, it is recommended that you install Gifsicle. This tool can help you work with GIFs more efficiently and add animations where they are not currently supported, such as in conjunction with the GD library.

```bash
#  Ubuntu
sudo apt-get install gifsicle

# Centos
sudo yum install gifsicle
```

## Quick Start

#### Using ImageManager (Recommended)

```php
<?php
use Cleup\Pixie\ImageManager;

// Create from file with auto driver detection
$image = ImageManager::createFromPath('input.jpg');

// To optimize GIF performance, use Gifsicle
$image
    // Gifsicle will be available if installed
    ->useGifsicle()
    // If you want to get a minimum file size, you can select a value from 1 to 100.
    // The higher this value, the smaller the file size will be, but the frame quality may deteriorate.
    ->setGifsicleLossy(80);

// Allow upscaling - we do not recommend enabling it without need.
$image->upcale(true); // Default = false

// Resize and save
$image->resize(800, 600)
      ->save('output.jpg', 90);


// Create thumbnail
$image->fit(200, 200)
      ->greyscale()
      ->save('/path/to/thumbnail.jpg');
```

### Using Image Class Directly

```php
<?php

use Cleup\Pixie\Image;

// With specific driver
$image = new Image('imagick'); // or 'gd'
$image->load('input.png')
      ->resize(400, 300)
      ->save('/path/to/output.webp', 85, 'webp');
```

### Driver Comparison

##### GD Driver

- ✅ Built-in PHP extension
- ✅ Good performance for basic operations
- ✅ Lower memory usage
- ❌ Does not support animated GIFs without Gifsicle
- ❌ Lower quality for some operations

##### Imagick Driver (Recommended)

- ✅ Excellent quality output
- ✅ Full animated GIF support
- ✅ Advanced image processing features
- ❌ Requires separate extension
- ❌ Higher memory usage

For most production applications, especially those that require support for animated GIF files or high-quality data output, we recommend using the Imagick driver in combination with the Gifsicle utility for optimal results.

## Basic Usage

### Loading Images

```php
// From file
$image = ImageManager::createFromPath('/path/to/photo.jpg');

// From binary data
$data = file_get_contents('/path/to/photo.jpg');
$image = ImageManager::createFromString($data);

// From URL (with error handling)
try {
    // ✅ Correct
    $image = ImageManager::createFromString(
        file_get_contents('https://example.com/image.jpg')
    );

    // ❌ Incorrect
    $image = ImageManager::createFromPath('https://example.com/image.jpg');
} catch (Cleup\Pixie\Exceptions\ImageException $e) {
    echo "Error loading image: " . $e->getMessage();
}
```

### Saving Images

```php
// Save with default quality
$image->save('output.jpg');

// Save with specific quality and format
$image->save('output.webp', 85, 'webp');

// Output directly to browser
$image->output('jpeg', 90);

// Get as binary string
$binaryData = $image->toString('png', 100);
```

## Image Operations

### Resizing

```php
// Basic resize
$image->resize(800, 600);

// Resize with aspect ratio preservation (default)
$image->resize(800, 600, true);

// Resize to specific width/height
$image->resizeToWidth(400);
$image->resizeToHeight(300);

// Scale by ratio
$image->scale(0.5); // 50% scale

// Fit within dimensions
$image->resizeToFit(1024, 768);

// Fill dimensions (crop to fit)
$image->resizeToFill(200, 200);
```

### Cropping

```php
// Crop with coordinates
$image->crop(100, 100, 400, 300);

// Fit and crop to exact dimensions
$image->fit(300, 300);
```

### Transformations

```php
// Rotation
$image->rotate(45); // 45 degrees
$image->rotate(90, '#FFFFFF'); // With background color

// Flipping
$image->flip('horizontal');
$image->flipHorizontal();
$image->flipVertical();

// Canvas operations
$image->resizeCanvas(1000, 800, 'center');
```

### Filters and Effects

```php
// Basic filters
$image->blur(2);
$image->sharpen(1);
$image->brightness(20);
$image->contrast(-10);
$image->gamma(1.2);

// Color effects
$image->greyscale();
$image->sepia();
$image->colorize(50, -20, 30);
$image->pixelate(10);
$image->invert();
```

### Utility Methods

```php
// Get image information
$width = $image->getWidth();
$height = $image->getHeight();
$ratio = $image->getAspectRatio();
$extension = $image->getExtension();
$isAnimated = $image->isAnimated(); // Imagick only

// Get driver instance
$driver = $image->getDriver();
```

## Error Handling

```php
use Cleup\Pixie\Exceptions\ImageException;
use Cleup\Pixie\Exceptions\DriverException;

try {
    $image = ImageManager::createFromPath('nonexistent.jpg');
    $image->resize(100, 100)->save('output.jpg');
} catch (ImageException $e) {
    echo "Image error: " . $e->getMessage();
} catch (DriverException $e) {
    echo "Driver error: " . $e->getMessage();
} catch (Exception $e) {
    echo "General error: " . $e->getMessage();
}
```

## API Reference

### ImageManager Static Methods

- `createFromPath(string $path, string $driver = 'auto'): Image`
- `createFromString(string $data, string $driver = 'auto'): Image`
- `getInfo(string $path): array`
- `isSupportedFormat(string $path): bool`
- `getAvailableDrivers(): array`
- `isDriverAvailable(string $driver): bool`
- `getRecommendedDriver(): string`

### Image Instance Methods

#### Loading & Saving

- `load(string $path): self`
- `loadFromString(string $data): self`
- `save(string $path, ?int $quality = null, ?string $format = null): bool`
- `toString(?string $format = null, ?int $quality = null): string`
- `output(?string $format = null, ?int $quality = null): void`

#### Information

- `getWidth(): int`
- `getHeight(): int`
- `getAspectRatio(): float`
- `getType(): string`
- `getMimeType(): string`
- `isAnimated(): bool`
- `getDriver(): DriverInterface`

#### Transformations

- `resize(int $width, ?int $height = null, bool $preserveAspectRatio = true): self`
- `resizeToWidth(int $width): self`
- `resizeToHeight(int $height): self`
- `resizeToFit(int $maxWidth, int $maxHeight): self`
- `resizeToFill(int $width, int $height): self`
- `scale(float $ratio): self`
- `crop(int $x, int $y, int $width, int $height): self`
- `fit(int $width, int $height): self`
- `rotate(float $angle, string $backgroundColor = 'transparent'): self`
- `flip(string $mode = 'horizontal'): self`
- `flipHorizontal(): self`
- `flipVertical(): self`

#### Filters & Effects

- `blur(int $amount = 1): self`
- `sharpen(int $amount = 1): self`
- `brightness(int $level): self`
- `contrast(int $level): self`
- `gamma(float $correction): self`
- `colorize(int $red, int $green, int $blue): self`
- `greyscale(): self`
- `sepia(): self`
- `pixelate(int $size): self`
- `invert(): self`
- `watermark($watermark, string $position = 'bottom-right', int $offsetX = 10, int $offsetY = 10): self`

## License

MIT License. See LICENSE file for details.
