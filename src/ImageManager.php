<?php

namespace Cleup\Pixie;

class ImageManager
{
    /**
     * Create image instance from file path
     * 
     * @param string $path Image file path
     * @param string $driver Driver name
     * @return Image Image instance
     */
    public static function createFromPath(string $path, string $driver = 'auto'): Image
    {
        $image = new Image($driver);
        return $image->load($path);
    }

    /**
     * Create image instance from binary data
     * 
     * @param string $data Binary image data
     * @param string $driver Driver name
     * @return Image Image instance
     */
    public static function createFromString(string $data, string $driver = 'auto'): Image
    {
        $image = new Image($driver);
        return $image->loadFromString($data);
    }

    /**
     * Get image information
     * 
     * @param string $path Image file path
     * @return array Image information
     */
    public static function getInfo(string $path): array
    {
        $info = getimagesize($path);

        return [
            'width' => $info[0],
            'height' => $info[1],
            'type' => image_type_to_extension($info[2], false),
            'mime' => $info['mime'],
            'bits' => $info['bits'] ?? null,
            'channels' => $info['channels'] ?? null,
        ];
    }

    /**
     * Check if image format is supported by MIME type
     * 
     * @param string $path Image file path
     * @return bool True if format supported
     */
    public static function isSupportedFormat(string $path): bool
    {
        if (!file_exists($path)) {
            return false;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $path);
        finfo_close($finfo);

        $supportedMimeTypes = [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/bmp',
            'image/x-ms-bmp',
        ];

        return in_array($mimeType, $supportedMimeTypes);
    }

    /**
     * Get MIME type from file
     * 
     * @param string $path File path
     * @return string MIME type
     */
    public static function getMimeType(string $path): string
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("File not found: {$path}");
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $path);
        finfo_close($finfo);

        return $mimeType ?: 'application/octet-stream';
    }

    /**
     * Get available drivers
     * 
     * @return array Available drivers
     */
    public static function getAvailableDrivers(): array
    {
        $drivers = [];

        if (extension_loaded('gd')) {
            $drivers[] = 'gd';
        }

        if (extension_loaded('imagick')) {
            $drivers[] = 'imagick';
        }

        return $drivers;
    }

    /**
     * Check if driver is available
     * 
     * @param string $driver Driver name
     * @return bool True if driver available
     */
    public static function isDriverAvailable(string $driver): bool
    {
        return in_array($driver, self::getAvailableDrivers());
    }

    /**
     * Get recommended driver
     * 
     * @return string Recommended driver name
     */
    public static function getRecommendedDriver(): string
    {
        $drivers = self::getAvailableDrivers();

        if (in_array('imagick', $drivers)) {
            return 'imagick';
        }

        return $drivers[0] ?? 'auto';
    }
}
