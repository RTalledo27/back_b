<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Architectural guard rails. The only allowed cross-module direction is
 * Commerce -> RepeatNumberBingo. The reverse is forbidden.
 */
final class ModuleBoundariesTest extends TestCase
{
    private const APP_DIR = __DIR__.'/../../../app';

    /**
     * @return iterable<SplFileInfo>
     */
    private static function phpFilesUnder(string $relativePath): iterable
    {
        $absolutePath = self::APP_DIR.'/'.$relativePath;

        if (! is_dir($absolutePath)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolutePath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file;
            }
        }
    }

    public function test_repeat_number_bingo_does_not_import_commerce(): void
    {
        $offenders = [];

        foreach (self::phpFilesUnder('Modules/RepeatNumberBingo') as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match('/\bApp\\\\Modules\\\\Commerce\\\\/', $contents) === 1) {
                $offenders[] = str_replace(self::APP_DIR.'/', '', (string) $file->getRealPath());
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'RepeatNumberBingo files must not reference App\\Modules\\Commerce. Offenders: '
            .implode(', ', $offenders)
        );
    }

    public function test_game_entry_source_does_not_mention_commerce(): void
    {
        $path = self::APP_DIR.'/Modules/RepeatNumberBingo/Domain/Models/GameEntry.php';

        $this->assertFileExists($path);

        $contents = (string) file_get_contents($path);

        $this->assertDoesNotMatchRegularExpression(
            '/\bApp\\\\Modules\\\\Commerce\\\\/',
            $contents,
            'GameEntry must not import any Commerce namespace.'
        );
    }
}
