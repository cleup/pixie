<?php

declare(strict_types=1);

define('PROJECT_ROOT', dirname(__DIR__));
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/config.php';
require_once PROJECT_ROOT . '/vendor/autoload.php';

$requiredDirs = [
    TEST_INPUT_PATH,
    TEST_OUTPUT_PATH,
];

foreach ($requiredDirs as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException("Failed to create directory: {$dir}");
    }
}

$requiredImages = [
    'TEST_IMAGE_JPG',
    'TEST_IMAGE_PNG',
    'TEST_IMAGE_GIF',
    'TEST_IMAGE_GIF_ANIMATED',
];

foreach ($requiredImages as $imageVar) {
    $imagePath = TEST_INPUT_PATH . '/' . constant($imageVar);
    if (!file_exists($imagePath)) {
        echo "Warning: Test image not found: {$imagePath}\n";
        echo "Please place test images in: {" . TEST_INPUT_PATH . "}/\n";
    }
}

function cleanupOutputDirectory(): void
{
    if (CLEANUP_OUTPUT === 'true') {
        $outputPath = TEST_OUTPUT_PATH;
        if (is_dir($outputPath)) {
            $files = glob($outputPath . '/*/*.*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}

register_shutdown_function('cleanupOutputDirectory');
