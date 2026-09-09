<?php

declare(strict_types=1);

use App\Enums\EventCategory;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every JSON and JSONB column that carries an empty-document default, as
 * `table => [column => document]`.
 *
 * @var array<string, array<string, string>>
 */
const JSON_DEFAULT_COLUMNS = [
    'users' => ['interest_profile' => '{}'],
    'chat_messages' => ['metadata' => '{}'],
    'llm_usage_logs' => ['metadata' => '{}'],
    'scraper_runs' => ['error_log' => '[]'],
    'event_notifications' => ['event_ids' => '[]', 'discovery_event_ids' => '[]'],
    'events' => ['tags' => '[]', 'metadata' => '{}'],
    'event_sources' => ['payload' => '{}'],
    'user_activity_logs' => ['context' => '{}'],
];

/**
 * The columns each table needs before an insert is legal, minus the JSON
 * column under test — that one is deliberately absent so the database default
 * is what fills it.
 *
 * @return array<string, mixed>
 */
function jsonDefaultRow(string $table): array
{
    return match ($table) {
        'users' => [
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'password' => 'secret',
        ],
        'events' => [
            'title' => 'Concert',
            'source' => 'iabilet',
            'source_url' => 'https://example.test/e/1',
            'category' => EventCategory::Music->value,
        ],
        'event_sources' => [
            'event_id' => Event::factory()->create()->id,
            'source' => 'iabilet',
            'source_url' => 'https://example.test/e/1',
            'url_key' => 'example.test/e/1',
            'occurrence_key' => 'undated',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ],
        'event_notifications' => [
            'user_id' => User::factory()->create()->id,
            'channel' => 'email',
            'frequency' => 'daily',
        ],
        'chat_messages' => [
            'user_id' => User::factory()->create()->id,
            'role' => 'user',
            'content' => 'Îmi place jazzul.',
        ],
        'scraper_runs' => [
            'source' => 'iabilet',
            'status' => 'running',
            'started_at' => now(),
        ],
        'llm_usage_logs' => [
            'operation' => 'classification',
            'model' => 'claude',
            'input_tokens' => 1,
            'output_tokens' => 1,
        ],
        'user_activity_logs' => [
            'type' => 'view',
            'surface' => 'dashboard',
        ],
    };
}

/**
 * @return array<string, array{string, string, string}>
 */
function jsonDefaultCases(): array
{
    $cases = [];

    foreach (JSON_DEFAULT_COLUMNS as $table => $columns) {
        foreach ($columns as $column => $document) {
            $cases["{$table}.{$column}"] = [$table, $column, $document];
        }
    }

    return $cases;
}

/**
 * A row that omits the column reads back as the empty document.
 *
 * This asserts the default works, not that it is spelled a particular way —
 * a literal default behaves identically here on sqlite, so this test alone
 * cannot catch a revert. The test below is the one that guards the spelling.
 * Removing a default outright does fail this test, because these columns are
 * NOT NULL.
 *
 * The insert goes through the query builder rather than a factory so the
 * column is genuinely absent and the database default is what fills it; a
 * factory sets its own value and would prove nothing.
 */
it('fills a JSON column with its empty document when the insert omits it', function (string $table, string $column, string $document) {
    $id = (string) Str::uuid();

    DB::table($table)->insert(['id' => $id] + jsonDefaultRow($table));

    $stored = (array) DB::table($table)->where('id', $id)->first();

    expect($stored[$column])->toBe($document);
})->with(jsonDefaultCases());

/**
 * No migration declares a JSON column default as a bare literal.
 *
 * MySQL rejects a literal default on a JSON column with error 1101, which is
 * what broke a production deploy; `DB::raw("('{}')")` is the expression form
 * MySQL 8.0.13+, PostgreSQL and sqlite all accept. That error is unreachable
 * from this suite, which `phpunit.xml` pins to sqlite, and the schema cannot
 * stand in for it either: a later `->change()` makes sqlite rebuild the table
 * and rewrite its stored DDL into the parenthesised form whichever way the
 * migration spelled it. So this guard reads the migrations themselves, which
 * is driver-independent and is what actually regresses.
 */
it('declares no JSON column default as a bare literal', function () {
    $offenders = [];

    foreach (glob(database_path('migrations/*.php')) ?: [] as $migration) {
        $source = (string) file_get_contents($migration);

        if (preg_match_all("/->(?:json|jsonb)\('(\w+)'\)->default\('/", $source, $matches)) {
            foreach ($matches[1] as $column) {
                $offenders[] = basename($migration).' — '.$column;
            }
        }
    }

    expect($offenders)->toBe([], implode('; ', $offenders).' — use ->default(DB::raw("(\'{}\')")), a literal default is MySQL error 1101');
});
