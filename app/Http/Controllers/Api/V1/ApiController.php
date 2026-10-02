<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Currency;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared plumbing for the public API controllers.
 *
 * Every response is wrapped in the platform envelope and paginated with the
 * documented `page` / `limit` / `orderby` / `sort` / `keywords` parameters.
 */
abstract class ApiController extends Controller
{
    /**
     * The authenticated client for the current request.
     */
    protected function client(Request $request): ?Client
    {
        $client = app()->bound('api.client') ? app('api.client') : null;

        if ($client instanceof Client) {
            return $client;
        }

        $resolved = $request->user();

        return $resolved instanceof Client ? $resolved : null;
    }

    /**
     * The authenticated client, or abort the request with the platform error.
     */
    protected function requireClient(Request $request): Client
    {
        $client = $this->client($request);

        abort_if($client === null, 401, '请先登录');

        return $client;
    }

    protected function ok(mixed $data = null, string $msg = '请求成功', array $extra = []): JsonResponse
    {
        return response()->json(ApiResponse::success($data, $msg, $extra));
    }

    protected function fail(string $msg = '操作失败', int $status = ApiResponse::FAIL, mixed $data = null): JsonResponse
    {
        return response()->json(ApiResponse::error($msg, $status, $data));
    }

    /**
     * Pagination parameters, clamped to a sane range.
     *
     * @return array{0:int, 1:int}
     */
    protected function pagination(Request $request): array
    {
        $page = max(1, (int) $request->input('page', 1));
        $limit = (int) $request->input('limit', 20);
        $limit = max(1, min(100, $limit ?: 20));

        return [$page, $limit];
    }

    /**
     * Apply the documented `keywords` / `orderby` / `sort` parameters.
     */
    protected function applyListQuery($query, Request $request, array $searchable = [], string $defaultOrder = 'id')
    {
        $keywords = trim((string) $request->input('keywords', ''));

        if ($keywords !== '' && $searchable !== []) {
            $query->where(function ($sub) use ($searchable, $keywords) {
                foreach ($searchable as $column) {
                    $sub->orWhere($column, 'like', '%' . $keywords . '%');
                }
            });
        }

        $orderby = (string) $request->input('orderby', $defaultOrder);
        $sort = strtoupper((string) $request->input('sort', 'DESC')) === 'ASC' ? 'asc' : 'desc';

        if (! preg_match('/^[A-Za-z0-9_]+$/', $orderby)) {
            $orderby = $defaultOrder;
        }

        return $query->orderBy($orderby, $sort);
    }

    /**
     * Paginator to the documented response shape.
     */
    protected function paginated($paginator, callable $transform, array $extra = []): JsonResponse
    {
        return $this->ok(array_merge([
            'list' => collect($paginator->items())->map($transform)->values()->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'limit' => $paginator->perPage(),
            'total_page' => $paginator->lastPage(),
        ], $extra));
    }

    /**
     * Default currency for pricing payloads.
     */
    protected function currency(): ?Currency
    {
        return Currency::default();
    }

    /**
     * Format an amount for API output using the active currency.
     */
    protected function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
