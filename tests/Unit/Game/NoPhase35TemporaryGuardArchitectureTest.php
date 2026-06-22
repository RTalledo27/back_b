<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use PHPUnit\Framework\TestCase;

/**
 * Once Phase 3.6 implements the winner branch, the Phase 3.5 placeholder
 * guard ("Phase 3.5 temporary guard:") must NEVER reappear in app/.
 */
final class NoPhase35TemporaryGuardArchitectureTest extends TestCase
{
    public function test_phase_3_5_temporary_guard_text_does_not_exist_in_app(): void
    {
        $root = __DIR__.'/../../../app';
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $offenders = [];
        foreach ($iter as $f) {
            if (! $f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($f->getPathname()) ?: '';
            if (str_contains($content, 'Phase 3.5 temporary guard')) {
                $offenders[] = $f->getPathname();
            }
        }
        $this->assertSame([], $offenders, 'Phase 3.5 placeholder still present: '.implode(', ', $offenders));
    }
}
