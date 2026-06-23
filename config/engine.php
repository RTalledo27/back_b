<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Draw interval constraints
    |--------------------------------------------------------------------------
    |
    | Applied at the application layer during StartGameAction when
    | auto_draw_enabled = true. Also enforced at the database layer via a
    | CHECK constraint on games.draw_interval_seconds (same range).
    |
    */
    'draw_interval_min_seconds' => (int) env('ENGINE_DRAW_INTERVAL_MIN', 10),
    'draw_interval_max_seconds' => (int) env('ENGINE_DRAW_INTERVAL_MAX', 3600),

    /*
    |--------------------------------------------------------------------------
    | Dispatcher cadence
    |--------------------------------------------------------------------------
    |
    | How often (in seconds) the scheduler fires DispatchDueGameDrawsJob.
    | Laravel's sub-minute scheduler keeps the loop alive within each
    | cron minute. Valid range: 1..59.
    |
    | Deployment:
    |   - Cron: * * * * * php artisan schedule:run >> /dev/null 2>&1
    |   - Deploys: php artisan schedule:interrupt
    |   - Dev:     php artisan schedule:work
    |
    */
    'dispatch_poll_seconds' => (int) env('ENGINE_DISPATCH_POLL_SECONDS', 15),

    /*
    |--------------------------------------------------------------------------
    | Dispatcher batch size
    |--------------------------------------------------------------------------
    |
    | Maximum number of games processed per dispatcher tick. Each game is
    | individually locked (SKIP LOCKED) so only games not held by another
    | dispatcher instance are considered.
    |
    */
    'dispatch_batch_size' => (int) env('ENGINE_DISPATCH_BATCH_SIZE', 200),

    /*
    |--------------------------------------------------------------------------
    | Catch-up policy
    |--------------------------------------------------------------------------
    |
    | A delayed engine executes only the selected due tick and then advances
    | to the first grid point strictly after the current time. Intermediate
    | ticks are aggregated into one engine_ticks_skipped audit row.
    |
    */
    'catch_up_policy' => env('ENGINE_CATCH_UP_POLICY', 'skip_to_next'),

    /*
    |--------------------------------------------------------------------------
    | Draw command namespace (UUID v5)
    |--------------------------------------------------------------------------
    |
    | Stable UUID v4 used as the namespace for generating deterministic
    | draw command IDs via UUID v5(namespace, game_id|scheduled_draw_at).
    | Not a secret — any value is valid as long as it is a valid UUID v4
    | and never changes between deployments.
    |
    */
    'draw_command_namespace' => env(
        'ENGINE_DRAW_COMMAND_NAMESPACE',
        'a1b2c3d4-e5f6-4789-abcd-ef0123456789'
    ),

];
