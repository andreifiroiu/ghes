<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Enums\ApiErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractPaginator;
use LogicException;

/**
 * The one envelope every `/api/v1` response uses.
 *
 * `JsonResource::withoutWrapping()` is global (every Inertia page prop depends
 * on it), so the API cannot lean on the framework's default `data` wrapper.
 * Instead every v1 controller method returns through here, which is the only
 * realistic way to keep ~30 endpoints on one shape:
 *
 * - single resource      → `{ "data": { … } }`
 * - plain list           → `{ "data": [ … ] }`
 * - paginated list       → `{ "data": [ … ], "links": { … }, "meta": { … } }`
 * - acknowledgement      → `{ "data": { "message": "…" } }`
 * - failure              → `{ "error": { "code", "message", "details" } }`
 *
 * On the paginated shape: `links` is an *object* (`first`/`last`/`prev`/`next`)
 * and the page-number links live at `meta.links` as an array. That confusion
 * has already white-screened two admin pages, so the client must read
 * `meta.last_page` and `links.next`, never iterate `links`.
 */
final class ApiResponse
{
    /**
     * @param  JsonResource|array<string, mixed>  $item
     */
    public static function item(JsonResource|array $item, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $item instanceof JsonResource ? $item->resolve() : $item,
        ], $status);
    }

    /**
     * An unpaginated list. Prefer {@see paginated()} for anything unbounded.
     *
     * @param  ResourceCollection|array<int, mixed>  $items
     */
    public static function collection(ResourceCollection|array $items): JsonResponse
    {
        return response()->json([
            'data' => $items instanceof ResourceCollection ? $items->resolve() : array_values($items),
        ]);
    }

    /**
     * A collection built from a paginator. The framework already renders
     * `{data, links, meta}` for those regardless of the wrapping flag, because
     * the pagination keys force the payload under `data`; this method exists so
     * the call site reads the same as the other shapes and the contract test
     * has one place to point at.
     */
    public static function paginated(ResourceCollection $collection): JsonResponse
    {
        // A plain collection would come back as a bare array with no `data`
        // key — the type hint accepts both, so this is the only guard.
        if (! $collection->resource instanceof AbstractPaginator) {
            throw new LogicException('ApiResponse::paginated() needs a collection built from a paginator.');
        }

        return $collection->response();
    }

    /**
     * A write acknowledged with nothing worth returning. Still wrapped in
     * `data` so the client has exactly one success parser.
     *
     * @param  array<string, mixed>  $extra
     */
    public static function message(string $message, array $extra = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => ['message' => $message, ...$extra],
        ], $status);
    }

    /**
     * @param  array<string, mixed>|null  $details  Field errors for a 422, or nothing.
     * @param  array<string, mixed>  $extra  Sibling keys such as `retry_after` on a 429.
     * @param  array<string, string>  $headers
     */
    public static function error(
        ApiErrorCode $code,
        string $message,
        int $status,
        ?array $details = null,
        array $extra = [],
        array $headers = [],
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => $code->value,
                'message' => $message,
                'details' => $details,
                ...$extra,
            ],
        ], $status, $headers);
    }
}
