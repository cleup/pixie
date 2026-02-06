<?php

declare(strict_types=1);

namespace Cleup\Pixie\Tests\Unit\Drivers;

use PHPUnit\Framework\TestCase;
use Cleup\Pixie\Drivers\GD\GDDriver;

class GDDriverTest extends TestCase
{
    public function testGdDriverCreation(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available');
        }
        
        $driver = new GDDriver();
        $this->assertInstanceOf(GDDriver::class, $driver);
    }
    
    public function testGdDriverMethods(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available');
        }
        
        $driver = new GDDriver();
        
        // Test empty driver state
        $this->assertEquals(0, $driver->getWidth());
        $this->assertEquals(0, $driver->getHeight());
    }
}