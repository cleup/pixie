<?php

namespace Cleup\Pixie\Optimizers;

use RuntimeException;
use InvalidArgumentException;

class Gifsicle
{
    /**
     * Path to gifsicle executable
     *
     * @var string
     */
    private $binaryPath;

    /**
     * File path
     *
     * @var string
     */
    private $path;

    /**
     * Lossy
     *
     * @var int
     */
    private $lossy = 0;

    /**
     * Temporary directory
     *
     * @var string
     */
    private $frameTempDir;

    /**
     * Output path
     *
     * @var string
     */
    private $outputPath;

    /**
     * Constructor
     *
     * @param string|null $path Input file path
     * @param string|null $outputPath Output file path
     * @param string|null $binaryPath Path to gifsicle executable
     */
    public function __construct(
        ?string $path = null,
        ?string $outputPath = null,
        ?string $binaryPath = null
    ) {
        if ($path !== null) {
            $this->path = $path;
        }

        if ($outputPath !== null) {
            $this->outputPath = $outputPath;
        }

        $this->binaryPath = $binaryPath ?? $this->detectBinaryPath();

        if (!$this->isInstalled()) {
            throw new RuntimeException(
                'GIFsicle not found in the system. Install it or specify the path manually.'
            );
        }
    }

    /**
     * Get path
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Set path
     *
     * @param string $path Path to set
     * @return self
     */
    public function setPath(string $path): self
    {
        $this->path = $path;
        return $this;
    }

    /**
     * Set output path
     *
     * @param string $path Output path
     * @return self
     */
    public function setOutputPath(string $path): self
    {
        $this->outputPath = $path;
        return $this;
    }

    /**
     * Get output path
     *
     * @return string
     */
    public function getOutputPath(): string
    {
        return $this->outputPath;
    }

    /**
     * Set a lossy value
     *
     * @param string $value Lossy value
     * @return self
     */
    public function setLossy(int $value): self
    {
        $this->lossy = $value;
        return $this;
    }

    /**
     * Get a lossy value
     *
     * @return int
     */
    public function getLossy(): int
    {
        return $this->lossy;
    }

    /**
     * Detect path to gifsicle executable
     *
     * @return string Path to executable
     */
    private function detectBinaryPath(): string
    {
        $possiblePaths = [
            'gifsicle',
            '/usr/bin/gifsicle',
            '/usr/local/bin/gifsicle',
            'C:\\Program Files\\gifsicle\\gifsicle.exe',
            'C:\\gifsicle\\gifsicle.exe',
        ];

        foreach ($possiblePaths as $path) {
            if ($this->testBinary($path)) {
                return $path;
            }
        }

        return 'gifsicle';
    }

    /**
     * Test if gifsicle is available at the specified path
     *
     * @param string $binaryPath Path to test
     * @return bool
     */
    private function testBinary(string $binaryPath): bool
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return file_exists($binaryPath) && is_executable($binaryPath);
        }

        $output = [];
        $returnVar = null;

        exec("which $binaryPath 2>/dev/null", $output, $returnVar);

        return $returnVar === 0 && !empty($output);
    }

    /**
     * Check if gifsicle is installed in the system
     *
     * @return bool
     */
    public function isInstalled(): bool
    {
        $command = escapeshellcmd($this->binaryPath) . ' --version 2>&1';
        $output = [];
        $returnVar = null;

        exec($command, $output, $returnVar);

        return $returnVar === 0 && !empty($output);
    }

    /**
     * Get current path to executable
     *
     * @return string
     */
    public function getBinaryPath(): string
    {
        return $this->binaryPath;
    }

    /**
     * Set new path to executable
     *
     * @param string $binaryPath New path
     * @return self
     * @throws RuntimeException If gifsicle not found at new path
     */
    public function setBinaryPath(string $binaryPath): self
    {
        $oldPath = $this->binaryPath;
        $this->binaryPath = $binaryPath;

        if (!$this->isInstalled()) {
            $this->binaryPath = $oldPath;
            throw new RuntimeException(
                "GIFsicle not found at specified path: $binaryPath"
            );
        }

        return $this;
    }

    /**
     * Optimize GIF file
     *
     * @param string|null $inputFile Path to source file
     * @param string $outputFile Path for saving optimized file
     * @param array $options Optimization options
     * @return bool Operation success
     * @throws InvalidArgumentException If input file doesn't exist
     */
    public function optimize(
        ?string $inputFile = null,
        string $outputFile,
        array $options = []
    ): bool {
        $defaultOptions = [
            'optimizationLevel' => 3,
            'colors' => null,
            'lossy' => $this->getLossy() > 0 ? $this->getLossy() : null,
            'no-comments' => true,
            'no-extensions' => true,
            'no-warnings' => true,
            'careful' => false,
            'resize' => null,
            'scale' => null,
            'delay' => null,
            'loopcount' => null,
            'no-background' => false,
            'transparent' => null,
        ];

        $options = array_merge($defaultOptions, $options);
        $command = $this->buildCommand($inputFile, $outputFile, $options);
        $output = [];
        $returnVar = null;

        exec($command, $output, $returnVar);

        return $returnVar === 0 && file_exists($outputFile);
    }

    /**
     * Build command for executing gifsicle
     *
     * @param string|null $inputFile Input file
     * @param string $outputFile Output file
     * @param array $options Options
     * @return string Command string
     */
    public function buildCommand(
        ?string $inputFile,
        string $outputFile,
        array $options
    ): string {
        $command = [
            escapeshellcmd($this->binaryPath),
        ];

        if ($options['optimizationLevel'] >= 1 && $options['optimizationLevel'] <= 3) {
            $command[] = '-O' . (int)$options['optimizationLevel'];
        }

        if (isset($options['colors']) && is_numeric($options['colors'])) {
            $command[] = '--colors=' . (int)$options['colors'];
        }

        if (isset($options['lossy']) && is_numeric($options['lossy'])) {
            $command[] = '--lossy=' . (int)$options['lossy'];
        }

        if (isset($options['no-comments'])) {
            $command[] = '--no-comments';
        }

        if (isset($options['no-extensions'])) {
            $command[] = '--no-extensions';
        }

        if (isset($options['no-warnings'])) {
            $command[] = '--no-warnings';
        }

        if (isset($options['careful'])) {
            $command[] = '--careful';
        }

        if (isset($options['resize'])) {
            $command[] = '--resize=' . escapeshellarg($options['resize']);
        }

        if (isset($options['scale'])) {
            $command[] = '--scale=' . escapeshellarg($options['scale']);
        }

        if (isset($options['delay'])) {
            $command[] = '--delay=' . (int)$options['delay'];
        }

        if (isset($options['loopcount'])) {
            $command[] = '--loopcount=' . (int)$options['loopcount'];
        }

        if (isset($options['no-background'])) {
            $command[] = '--no-background';
        }

        if (isset($options['transparent'])) {
            $command[] = '--transparent=' . escapeshellarg($options['transparent']);
        }

        if (isset($options['merge']) && is_array($options['merge'])) {
            $mergeWithOptions = ' ';

            foreach ($options['merge'] as $layer) {
                if (is_string($layer)) {
                    $mergeWithOptions .= ' ' . escapeshellarg($layer);
                } elseif (is_array($layer)) {
                    if (isset($layer['path'])) {
                        $path = escapeshellarg($layer['path']);
                        unset($layer['path']);

                        foreach ($layer as $optionKey => $optionValue) {
                            $mergeWithOptions .= ' --' . $optionKey . '=' . $optionValue;
                        }

                        $mergeWithOptions .= ' ' . $path;
                    }
                }
            }

            $command[] = $mergeWithOptions;
        }

        $command[] = '--output=' . escapeshellarg($outputFile);

        if (!is_null($inputFile)) {
            $command[] = escapeshellarg($inputFile);
        }

        $command[] = '2>&1';

        return implode(' ', $command);
    }

    /**
     * Get information about GIF file
     *
     * @param string $inputFile Path to file
     * @return array File information
     * @throws InvalidArgumentException If file doesn't exist
     */
    public function getInfo(string $inputFile): array
    {
        if (!file_exists($inputFile)) {
            throw new InvalidArgumentException("File doesn't exist: $inputFile");
        }

        $command = escapeshellcmd($this->binaryPath) . ' --info ' .
            escapeshellarg($inputFile) . ' 2>&1';

        $output = [];
        $returnVar = null;

        exec($command, $output, $returnVar);

        $info = [
            'frames' => 0,
            'size' => filesize($inputFile),
            'dimensions' => null,
            'comments' => false,
            'extensions' => false,
            'logicalScreen' => null,
            'delays' => [],
            'loopCount' => 0,
            'backgroundColor' => null,
            'hasTransparency' => false,
        ];

        foreach ($output as $line) {
            if (preg_match('/(\d+) images/', $line, $matches)) {
                $info['frames'] = (int)$matches[1];
            }

            if (preg_match('/logical screen (\d+)x(\d+)/', $line, $matches)) {
                $info['dimensions'] = [
                    'width' => (int)$matches[1],
                    'height' => (int)$matches[2],
                ];
            }

            if (strpos($line, 'comments: yes') !== false) {
                $info['comments'] = true;
            }

            if (strpos($line, 'application extensions: yes') !== false) {
                $info['extensions'] = true;
            }

            if (preg_match('/logical screen (\d+)x(\d+)/', $line, $matches)) {
                $info['logicalScreen'] = [
                    'width' => (int)$matches[1],
                    'height' => (int)$matches[2],
                ];
            }

            if (preg_match('/delay (\d+\.?\d*)s/', $line, $matches)) {
                $info['delays'][] = (float)$matches[1];
            }

            if (preg_match('/loop count (\d+)/', $line, $matches)) {
                $info['loopCount'] = (int)$matches[1];
            }

            if (preg_match('/background color (\d+)/', $line, $matches)) {
                $info['backgroundColor'] = (int)$matches[1];
            }

            if (strpos($line, 'transparent color') !== false) {
                $info['hasTransparency'] = true;
            }
        }

        return $info;
    }

    /**
     * Change delay between frames
     *
     * @param string $inputFile Input file
     * @param string $outputFile Output file
     * @param int $delay Delay in hundredths of a second (10 = 0.1s)
     * @return bool Operation success
     */
    public function changeDelay(
        string $inputFile,
        string $outputFile,
        int $delay
    ): bool {
        if (!file_exists($inputFile)) {
            throw new InvalidArgumentException("Input file doesn't exist: $inputFile");
        }

        if ($delay < 0) {
            throw new InvalidArgumentException("Delay cannot be negative");
        }

        $command = escapeshellcmd($this->binaryPath) .
            ' --delay=' . (int)$delay .
            ' --output=' . escapeshellarg($outputFile) .
            ' ' . escapeshellarg($inputFile) .
            ' 2>&1';

        $output = [];
        $returnVar = null;

        exec($command, $output, $returnVar);

        return $returnVar === 0 && file_exists($outputFile);
    }

    /**
     * Optimize GIF with recommended settings
     *
     * @param string $inputFile Input file
     * @param string $outputFile Output file
     * @param int $quality Quality level (0-100)
     * @return bool Operation success
     */
    public function optimizeWithQuality(
        string $inputFile,
        string $outputFile,
        int $quality = 95,
        int $maxColors = 256
    ): bool {
        $quality = max(0, min(100, $quality));

        $options = [
            'optimizationLevel' => 3,
            'no-comments' => true,
            'no-extensions' => true,
            'careful' => true,
        ];

        $colorQuality =  (int)($maxColors * ($quality ?? $quality)) / 100;
        $options['colors'] = $colorQuality;
        $options['lossy'] = $this->getLossy() > 0 ? $this->getLossy() : 100 - $quality;

        return $this->optimize($inputFile, $outputFile, $options);
    }

    /**
     * Get frame temporary directory
     *
     * @return string
     */
    private function getFrameTempDir(): string
    {
        $this->frameTempDir = $this->frameTempDir ??
            sys_get_temp_dir() . '/gif-frames-' . uniqid() . '-' . rand();

        return $this->frameTempDir;
    }

    /**
     * Extract frames from GIF
     *
     * @param string $inputFile Input GIF file
     * @return array List of extracted frames
     */
    public function extractFrames(string $inputFile): array
    {
        if (!file_exists($inputFile)) {
            throw new InvalidArgumentException("Input file doesn't exist: $inputFile");
        }

        $tempDir = $this->getFrameTempDir();

        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $filename = basename($inputFile);
        $absoluteInputFile = realpath($inputFile);
        $currentDir = getcwd();

        chdir($tempDir);

        $command = escapeshellcmd($this->binaryPath) .
            ' --explode' .
            ' ' . escapeshellarg($absoluteInputFile) .
            ' 2>&1';

        $output = [];
        $returnVar = null;

        exec($command, $output, $returnVar);
        chdir($currentDir);

        $extractedFrames = [];

        if ($handle = opendir($tempDir)) {
            $pattern = '/^' . preg_quote($filename, '/') . '\.\d{3}$/';

            while (false !== ($entry = readdir($handle))) {
                if (preg_match($pattern, $entry)) {
                    $extractedFrames[] = $tempDir . '/' . $entry;
                }
            }
            closedir($handle);
        }

        sort($extractedFrames);

        return $extractedFrames;
    }

    /**
     * Set loop count for GIF
     *
     * @param string $inputFile Input GIF file
     * @param string $outputFile Output GIF file
     * @param int $loopCount Loop count (0 = infinite)
     * @return bool Operation success
     */
    public function setLoopCount(
        string $inputFile,
        string $outputFile,
        int $loopCount = 0
    ): bool {
        return $this->optimize($inputFile, $outputFile, [
            'loopcount' => $loopCount,
            'optimizationLevel' => 1,
        ]);
    }

    /**
     * Remove metadata from GIF
     *
     * @param string $inputFile Input GIF file
     * @param string $outputFile Output GIF file
     * @return bool Operation success
     */
    public function stripMetadata(
        string $inputFile,
        string $outputFile
    ): bool {
        return $this->optimize($inputFile, $outputFile, [
            'no-comments' => true,
            'no-extensions' => true,
            'optimizationLevel' => 2,
        ]);
    }
}
