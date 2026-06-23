<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Modules\RepeatNumberBingo\Application\Actions\AutoPauseGameAfterIntegrityFailureAction;
use App\Modules\RepeatNumberBingo\Application\Actions\DispatchDueGameDrawsAction;
use App\Modules\RepeatNumberBingo\Application\Actions\DrawGameNumberAction;
use App\Modules\RepeatNumberBingo\Application\Actions\ExecuteScheduledGameDrawAction;
use App\Modules\RepeatNumberBingo\Application\Actions\PauseGameAction;
use App\Modules\RepeatNumberBingo\Application\Actions\ResumeGameAction;
use App\Modules\RepeatNumberBingo\Application\Actions\StartGameAction;
use App\Modules\RepeatNumberBingo\Application\Queries\ListGameDrawsQuery;
use App\Modules\RepeatNumberBingo\Application\Services\CommittedDrawEventsDispatcher;
use App\Modules\RepeatNumberBingo\Infrastructure\Broadcasting\Events\PublicGameUpdated;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;

final class Phase4ArchitectureTest extends TestCase
{
    public function test_repeat_number_bingo_controllers_are_thin_and_do_not_access_persistence_directly(): void
    {
        foreach ($this->phpFiles($this->appPath('Modules/RepeatNumberBingo/Presentation/Http/Controllers')) as $file) {
            $source = file_get_contents($file) ?: '';

            $this->assertStringNotContainsString('DB::', $source, $file);
            $this->assertStringNotContainsString('::query()', $source, $file);
            $this->assertStringNotContainsString('::create(', $source, $file);
            $this->assertStringNotContainsString('->save(', $source, $file);
            $this->assertStringNotContainsString('DB::transaction', $source, $file);
        }
    }

    public function test_phase_four_domain_core_has_no_laravel_framework_dependencies(): void
    {
        foreach ([
            $this->appPath('Modules/RepeatNumberBingo/Domain/Enums'),
            $this->appPath('Modules/RepeatNumberBingo/Domain/Exceptions'),
            $this->appPath('Modules/RepeatNumberBingo/Domain/Services'),
            $this->appPath('Modules/RepeatNumberBingo/Domain/ValueObjects'),
        ] as $directory) {
            foreach ($this->phpFiles($directory) as $file) {
                $source = file_get_contents($file) ?: '';

                $this->assertStringNotContainsString('Illuminate\\', $source, $file);
                $this->assertStringNotContainsString('Laravel\\', $source, $file);
            }
        }
    }

    public function test_resources_do_not_execute_queries_or_load_relations(): void
    {
        foreach ($this->phpFiles($this->appPath('Modules/RepeatNumberBingo/Presentation/Http/Resources')) as $file) {
            $source = file_get_contents($file) ?: '';

            foreach (['::query(', 'DB::', '->load(', '->loadMissing(', '->fresh(', '->refresh('] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, $file);
            }
        }
    }

    public function test_public_broadcast_event_serializes_only_slug_and_array_payload(): void
    {
        $reflection = new ReflectionClass(PublicGameUpdated::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);

        $types = array_map(
            static fn ($parameter): ?string => $parameter->getType() instanceof ReflectionNamedType
                ? $parameter->getType()->getName()
                : null,
            $constructor->getParameters(),
        );

        $this->assertSame(['string', 'array'], $types);

        $source = file_get_contents($reflection->getFileName()) ?: '';
        $this->assertStringNotContainsString('SerializesModels', $source);
        $this->assertStringNotContainsString(Model::class, $source);
        $this->assertStringNotContainsString('Domain\\Models', $source);
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function transactionalWriteActions(): iterable
    {
        yield 'start' => [StartGameAction::class];
        yield 'manual draw' => [DrawGameNumberAction::class];
        yield 'scheduled draw' => [ExecuteScheduledGameDrawAction::class];
        yield 'pause' => [PauseGameAction::class];
        yield 'resume' => [ResumeGameAction::class];
        yield 'auto pause' => [AutoPauseGameAfterIntegrityFailureAction::class];
    }

    #[DataProvider('transactionalWriteActions')]
    public function test_phase_four_write_actions_control_their_transactions(string $action): void
    {
        $source = file_get_contents((new ReflectionClass($action))->getFileName()) ?: '';

        $this->assertStringContainsString('DB::transaction(', $source);
    }

    public function test_game_draws_remains_the_official_history_and_is_persisted_before_projection(): void
    {
        $drawSource = file_get_contents((new ReflectionClass(DrawGameNumberAction::class))->getFileName()) ?: '';
        $drawInsert = mb_strpos($drawSource, "DB::table('game_draws')->insert");
        $counterUpsert = mb_strpos($drawSource, 'INSERT INTO game_number_counters');

        $this->assertIsInt($drawInsert);
        $this->assertIsInt($counterUpsert);
        $this->assertLessThan($counterUpsert, $drawInsert);

        $querySource = file_get_contents((new ReflectionClass(ListGameDrawsQuery::class))->getFileName()) ?: '';
        $this->assertStringContainsString('GameDraw::query()', $querySource);
    }

    public function test_scheduler_dispatcher_does_not_modify_calendar_or_game_state(): void
    {
        $source = file_get_contents((new ReflectionClass(DispatchDueGameDrawsAction::class))->getFileName()) ?: '';

        foreach ([
            '->save(',
            '::create(',
            '->update(',
            '->delete(',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }

        $this->assertDoesNotMatchRegularExpression('/->next_draw_at\s*=(?!=)/', $source);
        $this->assertDoesNotMatchRegularExpression('/->last_consumed_tick_at\s*=(?!=)/', $source);
    }

    public function test_only_scheduled_execution_consumes_ticks_and_advances_the_automatic_calendar(): void
    {
        $owners = [];

        foreach ($this->phpFiles($this->appPath('Modules/RepeatNumberBingo/Application')) as $file) {
            $source = file_get_contents($file) ?: '';

            if (preg_match('/->last_consumed_tick_at\s*=(?!=)/', $source) === 1) {
                $owners[] = str_replace('\\', '/', $file);
            }
        }

        $this->assertCount(1, $owners);
        $this->assertStringEndsWith(
            '/Actions/ExecuteScheduledGameDrawAction.php',
            $owners[0],
        );
    }

    public function test_draw_public_update_is_owned_by_the_committed_draw_dispatcher(): void
    {
        $source = file_get_contents((new ReflectionClass(CommittedDrawEventsDispatcher::class))->getFileName()) ?: '';

        $this->assertStringContainsString('PublicGameUpdateReason::NumberDrawn', $source);
        $this->assertStringContainsString('if ($result->wasReplay)', $source);
    }

    public function test_public_route_group_contains_no_write_routes(): void
    {
        $routes = file_get_contents($this->basePath('routes/api.php')) ?: '';
        $start = mb_strpos($routes, "Route::prefix('public')");
        $end = mb_strpos($routes, "Route::middleware(['auth:sanctum', 'admin'])", $start);

        $this->assertIsInt($start);
        $this->assertIsInt($end);
        $publicRoutes = mb_substr($routes, $start, $end - $start);

        foreach (['Route::post(', 'Route::put(', 'Route::patch(', 'Route::delete('] as $writeRoute) {
            $this->assertStringNotContainsString($writeRoute, $publicRoutes);
        }
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function appPath(string $path): string
    {
        return $this->basePath('app/'.$path);
    }

    private function basePath(string $path): string
    {
        return dirname(__DIR__, 3).'/'.$path;
    }
}
