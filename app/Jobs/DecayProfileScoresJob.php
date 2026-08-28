<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\InterestProfile\ProfileDecayer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class DecayProfileScoresJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(ProfileDecayer $decayer): void
    {
        Log::info('DecayProfileScoresJob: starting profile decay');

        $decayed = $decayer->decayAll();

        Log::info('DecayProfileScoresJob: profile decay complete', ['profiles_decayed' => $decayed]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('DecayProfileScoresJob: failed permanently', ['error' => $e->getMessage()]);
    }
}
