<?php

declare(strict_types=1);

namespace Cleup\Pixie\Tests\Unit\Drivers;

use PHPUnit\Framework\TestCase;
use Cleup\Pixie\Drivers\Imagick\ImagickDriver;

class ImagickDriverTest extends TestCase
{
    public function testImagickDriverCreation(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension not available');
        }

        $driver = new ImagickDriver();
        $this->assertInstanceOf(ImagickDriver::class, $driver);
    }

    public function testImagickGifsicle(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension not available');
        }

        $driver = new ImagickDriver();

        // Test gifsicle methods
        $driver->useGifsicle(true);
        $driver->setGifsicleLossy(30);

        $this->assertEquals(30, $driver->getGifsicleLossy());
    }
}
