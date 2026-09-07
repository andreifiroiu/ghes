<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A batch of impressions the app measured on-device.
 *
 * Impressions only, on purpose. Every other implicit type is already logged
 * by the endpoint that serves it — a view by `GET /events/{event}`, a click
 * by `POST /events/{event}/click`, a settled search by `GET /events` — and
 * the app has to make those calls anyway. Accepting them here as well would
 * double them in the click-through rate, the engagement aggregate and the
 * profile summary, with nothing on the server able to tell the copy from
 * the original. Impressions are the one thing only the client can see.
 */
class ActivityBatchRequest extends FormRequest
{
    /** The largest batch one call accepts; the app flushes at 25. */
    public const MAX_EVENTS = 100;

    /**
     * Accepted shapes of `at`, as `date_format` parameters: ISO 8601 with an
     * explicit offset, milliseconds optional — what `Date.prototype
     * .toISOString()` emits. A bare `date` rule would accept a device's local
     * wall-clock time and read it as UTC. Both `P` (`+02:00`) and `p` (`Z`)
     * are listed because the rule re-formats the parsed value and requires
     * it to match the input, and `p` prints `+00:00` as `Z`.
     */
    public const AT_FORMATS = 'Y-m-d\TH:i:sP,Y-m-d\TH:i:sp,Y-m-d\TH:i:s.vP,Y-m-d\TH:i:s.vp';

    /**
     * The surfaces the app may file a row under: its own screens, `push`
     * for a screen opened from a notification (as the web digest links do),
     * and the ambiguous `api`. Not the web or digest surfaces — this is the
     * first endpoint where a client can mint rows at volume, and a row filed
     * under `digest` would move the digest's click-through rate.
     *
     * @var list<ActivitySurface>
     */
    public const SURFACES = [
        ActivitySurface::MobileFeed,
        ActivitySurface::MobileBrowse,
        ActivitySurface::MobileEventDetail,
        ActivitySurface::MobileSaved,
        ActivitySurface::Push,
        ActivitySurface::Api,
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:'.self::MAX_EVENTS],
            'events.*.id' => ['required', 'uuid'],
            'events.*.type' => ['required', Rule::in([ActivityType::EventImpression->value])],
            'events.*.event_id' => ['required', 'uuid'],
            'events.*.from' => ['sometimes', 'nullable', Rule::in(array_column(self::SURFACES, 'value'))],
            'events.*.at' => ['required', 'date_format:'.self::AT_FORMATS],
        ];
    }
}
