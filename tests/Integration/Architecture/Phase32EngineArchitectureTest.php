<?php

declare(strict_types=1);

namespace Tests\Integration\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Structural guards for Phase 3.2. These tests grep the source tree
 * directly — they neither hit the DB nor boot Laravel.
 */
final class Phase32EngineArchitectureTest extends TestCase
{
    private const ENGINE_ROOT = __DIR__.'/../../../app/Modules/RepeatNumberBingo';

    private const COMMERCE_ROOT = __DIR__.'/../../../app/Modules/Commerce';

    private const SUPPORT_ROOT = __DIR__.'/../../Support';

    private const HTTP_NAMESPACES = [
        'use Illuminate\\Http\\',
        'use Symfony\\Component\\HttpFoundation\\',
        'use Illuminate\\Foundation\\Http\\',
    ];

    /**
     * @return list<string>
     */
    private function phpFilesUnder(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    public function test_rnb_module_does_not_import_commerce(): void
    {
        $offenders = [];
        foreach ($this->phpFilesUnder(self::ENGINE_ROOT) as $file) {
            $content = file_get_contents($file) ?: '';
            if (str_contains($content, 'use App\\Modules\\Commerce\\')) {
                $offenders[] = $file;
            }
        }
        $this->assertSame([], $offenders, 'RNB classes must not import App\\Modules\\Commerce: '.implode(', ', $offenders));
    }

    public function test_rnb_actions_dtos_and_contracts_have_no_http_dependencies(): void
    {
        $targets = array_merge(
            $this->phpFilesUnder(self::ENGINE_ROOT.'/Application/Contracts'),
            $this->phpFilesUnder(self::ENGINE_ROOT.'/Application/DTOs'),
            $this->phpFilesUnder(self::ENGINE_ROOT.'/Application/Actions'),
            $this->phpFilesUnder(self::ENGINE_ROOT.'/Domain'),
        );
        $offenders = [];
        foreach ($targets as $file) {
            $content = file_get_contents($file) ?: '';
            foreach (self::HTTP_NAMESPACES as $needle) {
                if (str_contains($content, $needle)) {
                    $offenders[] = sprintf('%s contains "%s"', $file, $needle);
                    break;
                }
            }
        }
        $this->assertSame([], $offenders, "Application/Domain layers must not depend on HTTP types:\n".implode("\n", $offenders));
    }

    public function test_only_crypto_strategy_uses_random_int_inside_the_module(): void
    {
        $offenders = [];
        foreach ($this->phpFilesUnder(self::ENGINE_ROOT) as $file) {
            $content = file_get_contents($file) ?: '';
            if (! preg_match('/\brandom_int\s*\(/', $content)) {
                continue;
            }
            if (! str_ends_with($file, DIRECTORY_SEPARATOR.'CryptographicallySecureDrawNumberStrategy.php')) {
                $offenders[] = $file;
            }
        }
        $this->assertSame([], $offenders, 'Only CryptographicallySecureDrawNumberStrategy may use random_int(): '.implode(', ', $offenders));
    }

    public function test_no_rand_or_mt_rand_anywhere_in_the_module(): void
    {
        $offenders = [];
        foreach ($this->phpFilesUnder(self::ENGINE_ROOT) as $file) {
            $content = file_get_contents($file) ?: '';
            foreach (['/\brand\s*\(/', '/\bmt_rand\s*\(/', '/\bsrand\s*\(/', '/\bmt_srand\s*\(/'] as $pattern) {
                if (preg_match($pattern, $content)) {
                    $offenders[] = sprintf('%s matched %s', $file, $pattern);
                    break;
                }
            }
        }
        $this->assertSame([], $offenders, "Module must not call rand()/mt_rand()/srand():\n".implode("\n", $offenders));
    }

    public function test_deterministic_strategy_lives_only_under_tests_support(): void
    {
        // Must exist in tests/Support.
        $this->assertFileExists(self::SUPPORT_ROOT.'/DeterministicDrawNumberStrategy.php');

        // Must NOT appear anywhere under app/ (would mean someone moved it
        // into production code by accident).
        $appRoot = __DIR__.'/../../../app';
        $offenders = [];
        foreach ($this->phpFilesUnder($appRoot) as $file) {
            $content = file_get_contents($file) ?: '';
            if (
                str_contains($content, 'class DeterministicDrawNumberStrategy')
                || str_contains($content, 'Tests\\Support\\DeterministicDrawNumberStrategy')
            ) {
                $offenders[] = $file;
            }
        }
        $this->assertSame([], $offenders, 'DeterministicDrawNumberStrategy must not leak into app/: '.implode(', ', $offenders));
    }

    public function test_readiness_contract_does_not_reference_commerce_models_or_concepts(): void
    {
        $content = file_get_contents(
            self::ENGINE_ROOT.'/Application/Contracts/GameStartReadinessChecker.php'
        ) ?: '';

        foreach (['Order', 'Payment', 'NumberReservation', 'Commerce'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $content,
                "GameStartReadinessChecker must not mention $needle in its type signature."
            );
        }
    }

    public function test_draw_game_number_data_does_not_depend_on_http_or_strategy(): void
    {
        $content = file_get_contents(
            self::ENGINE_ROOT.'/Application/DTOs/DrawGameNumberData.php'
        ) ?: '';

        foreach (self::HTTP_NAMESPACES as $needle) {
            $this->assertStringNotContainsString($needle, $content);
        }
        $this->assertStringNotContainsString('DrawNumberStrategy', $content);
    }
}
