<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Application Actions are pure use cases. They must never reference HTTP
 * concerns (Request, Response, Resources, status codes) — those live in
 * Presentation.
 */
final class ActionsAreHttpFreeTest extends TestCase
{
    private const ACTIONS_DIRS = [
        __DIR__.'/../../../app/Modules/Commerce/Application/Actions',
        __DIR__.'/../../../app/Modules/RepeatNumberBingo/Application/Actions',
    ];

    private const FORBIDDEN = [
        'Illuminate\\Http\\Request',
        'Illuminate\\Http\\JsonResponse',
        'Illuminate\\Http\\Response',
        'Illuminate\\Http\\Resources\\',
        'Illuminate\\Http\\UploadedFile',
        'Symfony\\Component\\HttpFoundation\\Request',
        'Symfony\\Component\\HttpFoundation\\Response',
        'Illuminate\\Support\\Facades\\Storage',
        'Illuminate\\Filesystem\\',
    ];

    public function test_no_action_references_http_namespaces(): void
    {
        $offenders = [];

        foreach (self::ACTIONS_DIRS as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                foreach (self::FORBIDDEN as $needle) {
                    if (str_contains($contents, $needle)) {
                        $offenders[] = basename((string) $file->getRealPath()).' references '.$needle;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Application Actions must remain HTTP-free. Offenders: '.implode('; ', $offenders),
        );
    }
}
