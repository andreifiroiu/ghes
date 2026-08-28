<?php

declare(strict_types=1);

use App\Jobs\ClassifyEventJob;
use App\Jobs\GeocodeEventJob;
use App\Models\Event;
use App\Services\Anthropic\AnthropicClient;
use App\Services\Processing\EventClassifier;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

it('dispatches GeocodeEventJob after classifying an event', function () {
    Bus::fake([GeocodeEventJob::class]);

    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"category": "Music", "tags": ["jazz"], "confidence": 0.9}']],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        ]),
    ]);

    $event = Event::factory()->create([
        'is_classified' => false,
        'tags' => [],
    ]);

    $classifier = new EventClassifier(
        client: new AnthropicClient(apiKey: 'test-key', model: 'claude-sonnet-4-20250514'),
    );

    (new ClassifyEventJob($event->id))->handle($classifier);

    Bus::assertDispatched(GeocodeEventJob::class, fn ($job) => $job->eventId === $event->id);
});
