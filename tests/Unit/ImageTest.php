<?php

declare(strict_types=1);

namespace Cleup\Pixie\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Cleup\Pixie\Image;
use Cleup\Pixie\ImageManager;
use Cleup\Pixie\Exceptions\DriverException;

/**
 * Core tests for Cleup\Pixie image processing library
 */
class ImageTest extends TestCase
{
    private string $inputPath;
    private string $outputPath;
    private array $availableDrivers = [];

    protected function setUp(): void
    {
        $this->inputPath = TEST_INPUT_PATH;
        $this->outputPath = TEST_OUTPUT_PATH;

        // Determine available drivers
        $requestedDrivers = explode(',', TEST_DRIVERS);
        $this->availableDrivers = array_filter($requestedDrivers, function ($driver) {
            $driver = trim($driver);
            if ($driver === 'imagick') {
                return extension_loaded('imagick');
            }
            if ($driver === 'gd') {
                return extension_loaded('gd');
            }
            return false;
        });

        if (empty($this->availableDrivers)) {
            $this->markTestSkipped('No image processing drivers available (GD or Imagick)');
        }

        // Create output directories for each driver
        foreach ($this->availableDrivers as $driver) {
            $dir = $this->outputPath . '/' . $driver;
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    /**
     * Test 1: Basic image creation and driver initialization
     */
    public function testImageCreationAndBasicProperties(): void
    {
        $this->assertNotEmpty($this->availableDrivers, 'No drivers available for testing');

        foreach ($this->availableDrivers as $driver) {
            // Test manual driver selection
            $image = new Image($driver);
            $this->assertInstanceOf(Image::class, $image);

            // Verify driver interface
            $driverInstance = $image->getDriver();
            $this->assertInstanceOf(\Cleup\Pixie\Interfaces\DriverInterface::class, $driverInstance);
        }
    }

    /**
     * Test 2: Auto driver selection
     */
    public function testAutoDriverSelection(): void
    {
        try {
            $image = new Image('auto');
            $this->assertInstanceOf(Image::class, $image);
            $this->assertNotNull($image->getDriver());
        } catch (DriverException $e) {
            $this->markTestSkipped('No drivers available for auto selection');
        }
    }

    /**
     * Test 3: Load and save basic operations
     */
    public function testLoadAndSaveBasicOperations(): void
    {
        $testFile = TEST_IMAGE_JPG;
        $imagePath = $this->inputPath . '/' . $testFile;

        if (!file_exists($imagePath)) {
            $this->markTestSkipped("Test image not found: {$testFile}");
        }

        foreach ($this->availableDrivers as $driver) {
            // Load image
            $image = new Image($driver);
            $image->load($imagePath);

            // Test basic properties
            $this->assertGreaterThan(0, $image->getWidth());
            $this->assertGreaterThan(0, $image->getHeight());
            $this->assertIsString($image->getExtension());
            $this->assertIsString($image->getMimeType());
            $this->assertGreaterThan(0, $image->getAspectRatio());

            // Save to output
            $outputFile = $this->outputPath . "/{$driver}/basic-load-save.jpg";
            $result = $image->save($outputFile);
            $this->assertTrue($result);
            $this->assertFileExists($outputFile);
        }
    }

    /**
     * Test 4: Core resize operations
     */
    public function testCoreResizeOperations(): void
    {
        $imagePath = $this->inputPath . '/' . TEST_IMAGE_JPG;

        if (!file_exists($imagePath)) {
            $this->markTestSkipped("Test image not found");
        }

        foreach ($this->availableDrivers as $driver) {
            // Test basic resize
            $image = new Image($driver);
            $image->load($imagePath);
            $image->resize(300, 200);
            $this->assertLessThanOrEqual(300, $image->getWidth());
            $this->assertLessThanOrEqual(200, $image->getHeight());

            $output1 = $this->outputPath . "/{$driver}/resize-300x200.jpg";
            $image->save($output1);

            // Test resize to width
            $image2 = (new Image($driver))->load($imagePath);
            $image2->resizeToWidth(250);
            $this->assertEquals(250, $image2->getWidth());

            $output2 = $this->outputPath . "/{$driver}/resize-width-250.jpg";
            $image2->save($output2);

            // Test resize to fit
            $image3 = (new Image($driver))->load($imagePath);
            $image3->resizeToFit(150, 150);
            $this->assertLessThanOrEqual(150, $image3->getWidth());
            $this->assertLessThanOrEqual(150, $image3->getHeight());

            $output3 = $this->outputPath . "/{$driver}/resize-fit-150x150.jpg";
            $image3->save($output3);
        }
    }

    /**
     * Test 5: Crop operations
     */
    public function testCropOperations(): void
    {
        $imagePath = $this->inputPath . '/' . TEST_IMAGE_JPG;

        if (!file_exists($imagePath)) {
            $this->markTestSkipped("Test image not found");
        }

        foreach ($this->availableDrivers as $driver) {
            $image = new Image($driver);
            $image->load($imagePath);
            $width = $image->getWidth();
            $height = $image->getHeight();

            // Crop from center
            $cropSize = min(100, $width, $height);
            $cropX = (int)(($width - $cropSize) / 2);
            $cropY = (int)(($height - $cropSize) / 2);

            $image->crop($cropX, $cropY, $cropSize, $cropSize);
            $this->assertEquals($cropSize, $image->getWidth());
            $this->assertEquals($cropSize, $image->getHeight());

            $output = $this->outputPath . "/{$driver}/crop-center-{$cropSize}.jpg";
            $image->save($output);
        }
    }

    /**
     * Test 6: Fit operation
     */
    public function testFitOperation(): void
    {
        $imagePath = $this->inputPath . '/' . TEST_IMAGE_JPG;

        if (!file_exists($imagePath)) {
            $this->markTestSkipped("Test image not found");
        }

        foreach ($this->availableDrivers as $driver) {
            $image = new Image($driver);
            $image->load($imagePath);
            $image->fit(200, 200);

            $this->assertEquals(200, $image->getWidth());
            $this->assertEquals(200, $image->getHeight());

            $output = $this->outputPath . "/{$driver}/fit-200x200.jpg";
            $image->save($output);
        }
    }

    /**
     * Test 7: Image transformations
     */
    public function testImageTransformations(): void
    {
        $imagePath = $this->inputPath . '/' . TEST_IMAGE_JPG;

        if (!file_exists($imagePath)) {
            $this->markTestSkipped("Test image not found");
        }

        foreach ($this->availableDrivers as $driver) {
            // Test rotation
            $image = new Image($driver);
            $image->load($imagePath);
            $originalWidth = $image->getWidth();
            $originalHeight = $image->getHeight();

            $image->rotate(90);

            // For 90 degree rotation, dimensions should swap
            $this->assertEquals($originalHeight, $image->getWidth());
            $this->assertEquals($originalWidth, $image->getHeight());

            $output1 = $this->outputPath . "/{$driver}/rotate-90.jpg";
            $image->save($output1);

            // Test flip
            $image2 = (new Image($driver))->load($imagePath);
            $image2->flipHorizontal();

            $output2 = $this->outputPath . "/{$driver}/flip-horizontal.jpg";
            $image2->save($output2);
        }
    }

    /**
     * Test 8: Basic image filters
     */
    public function testBasicImageFilters(): void
    {
        $imagePath = $this->inputPath . '/' . TEST_IMAGE_JPG;

        if (!file_exists($imagePath)) {
            $this->markTestSkipped("Test image not found");
        }

        $filters = [
            ['method' => 'blur', 'params' => [2]],
            ['method' => 'brightness', 'params' => [20]],
            ['method' => 'contrast', 'params' => [20]],
            ['method' => 'greyscale', 'params' => []],
        ];

        foreach ($this->availableDrivers as $driver) {
            foreach ($filters as $index => $filter) {
                $image = new Image($driver);
                $image->load($imagePath);
                $image->resize(200, 150);

                call_user_func_array([$image, $filter['method']], $filter['params']);

                $output = $this->outputPath . "/{$driver}/filter-{$index}.jpg";
                $image->save($output);

                $this->assertTrue(file_exists($output), "Failed to save with {$driver} driver");
            }
        }
    }

    /**
     * Test 9: ImageManager functionality
     */
    public function testImageManagerFunctionality(): void
    {
        $imagePath = $this->inputPath . '/' . TEST_IMAGE_JPG;

        if (!file_exists($imagePath)) {
            $this->markTestSkipped("Test image not found");
        }

        foreach ($this->availableDrivers as $driver) {
            // Test createFromPath
            $image = ImageManager::createFromPath($imagePath, $driver);
            $this->assertInstanceOf(Image::class, $image);

            // Apply operations
            $image->resizeToFit(250, 250);

            $output = $this->outputPath . "/{$driver}/manager-resize-fit.jpg";
            $image->save($output);

            // Test getInfo
            $info = ImageManager::getInfo($imagePath);
            $this->assertIsArray($info);
            $this->assertArrayHasKey('width', $info);
            $this->assertArrayHasKey('height', $info);

            // Test available drivers
            $drivers = ImageManager::getAvailableDrivers();
            $this->assertIsArray($drivers);
            $this->assertContains($driver, $drivers);
        }
    }

    /**
     * Test 10: GIF processing (both GD and Imagick)
     */
    public function testGifProcessing(): void
    {
        $gifPath = $this->inputPath . '/' . TEST_IMAGE_GIF;

        if (!file_exists($gifPath)) {
            $this->markTestSkipped("Test GIF not found");
        }

        foreach ($this->availableDrivers as $driver) {
            // Test basic GIF processing
            $image = new Image($driver);
            $image->load($gifPath);
            $image->resize(150, 150);

            $output = $this->outputPath . "/{$driver}/gif-resized.gif";
            $result = $image->save($output);

            $this->assertTrue($result, "Failed to save GIF with {$driver} driver");
            $this->assertFileExists($output);
        }
    }

    /**
     * Test 11: Animated GIF processing
     */
    public function testAnimatedGifProcessing(): void
    {
        $animatedGifPath = $this->inputPath . '/' . TEST_IMAGE_GIF_ANIMATED;

        if (!file_exists($animatedGifPath)) {
            $this->markTestSkipped("Animated GIF not found");
        }

        foreach ($this->availableDrivers as $driver) {
            // Imagick has better animated GIF support
            $image = new Image($driver);
            $image->load($animatedGifPath);


            // Test with gifsicle
            $image->useGifsicle(true);
            if ($image->isAvailableGifsicle()) {
                $image->setGifsicleLossy(30);
            }

            $image->resize(200, 200);
            // $image->flipHorizontal();
            $image->rotate(-45);
            $output = $this->outputPath . "/{$driver}/animated-gif-with-gifsicle.gif";
            $result = $image->save($output);

            $this->assertTrue($result, "Failed to save animated GIF with Imagick+gifsicle");

            // Test without gifsicle
            $image2 = new Image($driver);
            $image2->useGifsicle(false)
                ->load($animatedGifPath)
                ->rotate(-45)
                ->resize(200, 200);

            $output2 = $this->outputPath . "/{$driver}/animated-gif-no-gifsicle.gif";
            $result2 = $image2->save($output2);
            $this->assertTrue($result2, "Failed to save animated GIF without gifsicle");
        }
    }

    /**
     * Test 12: Combined operations
     */
    public function testCombinedOperations(): void
    {
        $imagePath = $this->inputPath . '/' . TEST_IMAGE_JPG;

        if (!file_exists($imagePath)) {
            $this->markTestSkipped("Test image not found");
        }

        foreach ($this->availableDrivers as $driver) {
            $image = new Image($driver);
            $image->load($imagePath);

            // Chain of operations
            $image->resize(400, 300)
                ->crop(50, 50, 300, 200)
                ->fit(150, 150)
                ->brightness(10)
                ->contrast(5)
                ->blur(1);

            $output = $this->outputPath . "/{$driver}/combined-operations.jpg";
            $result = $image->save($output);

            $this->assertTrue($result);
            $this->assertEquals(150, $image->getWidth());
            $this->assertEquals(150, $image->getHeight());
        }
    }

    /**
     * Test 13: toString method
     */
    public function testToStringMethod(): void
    {
        $imagePath = $this->inputPath . '/' . TEST_IMAGE_JPG;

        if (!file_exists($imagePath)) {
            $this->markTestSkipped("Test image not found");
        }

        foreach ($this->availableDrivers as $driver) {
            $image = new Image($driver);
            $image->load($imagePath);
            $image->resize(200, 150);

            // Test toString with JPEG
            $jpegString = $image->toString('jpg', 85);
            $this->assertIsString($jpegString);
            $this->assertNotEmpty($jpegString);

            // Save string to file
            $output = $this->outputPath . "/{$driver}/from-string.jpg";
            file_put_contents($output, $jpegString);
            $this->assertFileExists($output);

            // Test toString with PNG
            $pngString = $image->toString('png');
            $this->assertIsString($pngString);
            $this->assertNotEmpty($pngString);

            // Strings should be different
            $this->assertNotEquals($jpegString, $pngString);
        }
    }
}
