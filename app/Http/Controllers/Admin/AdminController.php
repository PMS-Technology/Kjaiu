<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Services\Admin\SettingService;
use App\Support\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Base class for every administrator API controller.
 *
 * The admin SPA reads a slightly wider envelope than the public API: on top of
 * `{status, msg, data}` the original platform appends `identify` (the request
 * path), `action` (the controller action), `is_sale` and `per_page_limit`.
 * Those extras drive the 401 → /forbidden redirect and the default page size,
 * so they are emitted here rather than being repeated in every controller.
 */
abstract class AdminController
{
    /**
     * Session/cookie based responses share one shape so the SPA never has to
     * special-case a status code.
     */
    protected function respond(array $payload): JsonResponse
    {
        return response()->json($payload);
    }

    protected function ok(mixed $data = null, string $msg = '请求成功', array $extra = []): JsonResponse
    {
        return $this->respond($this->wrap(ApiResponse::success($data, $msg), $extra));
    }

    /**
     * Successful response whose payload lives at the top level rather than
     * under `data` — the original does this for list endpoints such as
     * `order/search` (`list`, `count`, `price_total`) and `adminuser`
     * (`list`, `count`).
     */
    protected function okFlat(string $msg = '请求成功', array $fields = []): JsonResponse
    {
        return $this->respond(array_merge([
            'status' => ApiResponse::OK,
            'msg' => $msg,
        ], $fields, $this->envelope()));
    }

    protected function fail(string $msg = '操作失败', int $status = ApiResponse::FAIL, array $extra = []): JsonResponse
    {
        return $this->respond($this->wrap(ApiResponse::error($msg, $status), $extra));
    }

    protected function validationFail(string $msg): JsonResponse
    {
        return $this->fail($msg, ApiResponse::VALIDATION_FAILED);
    }

    protected function notFound(string $msg = '数据不存在'): JsonResponse
    {
        return $this->fail($msg, 404);
    }

    /**
     * 1001 — the original's "soft success" (nothing to pay, nothing to do).
     */
    protected function notice(string $msg, mixed $data = null): JsonResponse
    {
        return $this->respond(ApiResponse::success($data, $msg) + ['status' => ApiResponse::NOTICE]);
    }

    /**
     * Paginated list payload: `{list, total, page, limit, total_page}` plus any
     * extra aggregate fields the caller wants alongside.
     */
    protected function paginated(Collection|array $list, int $total, int $page, int $limit, array $extra = []): JsonResponse
    {
        $limit = max(1, $limit);

        return $this->ok(array_merge([
            'list' => array_values($list instanceof Collection ? $list->all() : $list),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_page' => (int) ceil($total / $limit),
        ], $extra));
    }

    protected function paginateRequest(LengthAwarePaginator $paginator, array $extra = []): JsonResponse
    {
        return $this->paginated(
            $paginator->items(),
            $paginator->total(),
            $paginator->currentPage(),
            $paginator->perPage(),
            $extra,
        );
    }

    /**
     * Read `page` / `limit` the way the SPA sends them, clamping to the page
     * sizes the table components offer.
     */
    protected function pageParams(Request $request, int $defaultLimit = 50): array
    {
        $page = max(1, (int) $request->input('page', 1));
        $limit = (int) $request->input('limit', $defaultLimit);
        $limit = $limit > 0 ? min($limit, 200) : $defaultLimit;

        return [$page, $limit];
    }

    /**
     * Resolve the `orderby` / `sort` (or `order` / `sorting`) pair. Only
     * whitelisted columns reach the query builder, since the values come
     * straight from the query string.
     *
     * @param  array<int,string>  $allowed
     * @return array{0:string,1:string}
     */
    protected function sortParams(Request $request, array $allowed, string $defaultColumn = 'id', string $defaultDir = 'DESC'): array
    {
        $column = (string) ($request->input('orderby') ?? $request->input('order') ?? $defaultColumn);
        $direction = strtoupper((string) ($request->input('sort') ?? $request->input('sorting') ?? $defaultDir));

        if (! in_array($column, $allowed, true)) {
            $column = $defaultColumn;
        }

        return [$column, $direction === 'ASC' ? 'ASC' : 'DESC'];
    }

    /**
     * The trailing envelope fields the SPA relies on.
     */
    protected function envelope(): array
    {
        $path = '/'.ltrim(request()->path(), '/');

        return [
            'identify' => $path,
            'action' => $this->actionName(),
            'is_sale' => (int) (optional($this->admin())->is_sale ?? 0),
            'per_page_limit' => (int) (SettingService::value('per_page_limit') ?: 50),
        ];
    }

    protected function wrap(array $payload, array $extra = []): array
    {
        return array_merge($payload, $this->envelope(), $extra);
    }

    protected function actionName(): string
    {
        $action = request()->route()?->getActionName() ?? '';

        return $action === '' ? 'index' : (string) substr($action, (int) strrpos($action, '@') + 1);
    }

    protected function admin(): ?User
    {
        return auth('admin')->user();
    }

    protected function adminId(): int
    {
        return (int) (optional($this->admin())->id ?? 0);
    }

    /**
     * The current administrator's display name, stored on `shd_admin_log`
     * rows and shown in the operation logs.
     */
    protected function adminName(): string
    {
        $admin = $this->admin();

        return (string) ($admin->user_login ?? $admin->user_nickname ?? 'admin');
    }

    /**
     * Clamp a decimal to the platform's money precision.
     */
    protected function money(float|int|string|null $value): float
    {
        return round((float) $value, 2);
    }

    /**
     * Accept a unix timestamp from any of the shapes the SPA posts: seconds,
     * milliseconds (the request layer divides 13-digit values by 1000) or an
     * empty string for "not set".
     */
    protected function timestamp(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (is_string($value) && ! is_numeric($value)) {
            $parsed = strtotime($value);

            return $parsed === false ? null : $parsed;
        }

        $value = (int) $value;

        if ($value <= 0) {
            return null;
        }

        return $value > 9999999999 ? (int) floor($value / 1000) : $value;
    }

    /**
     * Decode a `datetimerange` filter ([start, end]) into a pair of unix
     * timestamps, tolerating the SPA's mixed seconds/milliseconds output.
     */
    protected function timeRange(mixed $value): array
    {
        if (! is_array($value)) {
            return [null, null];
        }

        return [$this->timestamp($value[0] ?? null), $this->timestamp($value[1] ?? null)];
    }

    /**
     * Boolean-ish form values arrive as "1" / "0" / true / false.
     */
    protected function bool(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array($value, [1, '1', true, 'true', 'on', 'yes'], true);
    }

    /**
     * Split a comma separated id list posted by multi-selects (`appliesto`,
     * `pids`, `requires`, …). The original stores these as comma-padded CSV.
     */
    protected function csv(mixed $value): string
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = array_filter(explode(',', (string) $value), fn ($v) => $v !== '');
        }

        $items = array_values(array_unique(array_map(fn ($v) => (int) $v, $items)));

        return $items === [] ? '' : ','.implode(',', $items).',';
    }

    /**
     * Decode a CSV/JSON blob into an int list.
     */
    protected function csvToArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('intval', $value)));
        }

        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode((string) $value, true);

        if (is_array($decoded)) {
            return array_values(array_filter(array_map('intval', $decoded)));
        }

        return array_values(array_filter(array_map('intval', explode(',', (string) $value))));
    }

    /**
     * Write an administrator activity row (`shd_activity_log`). The original
     * logs every mutating admin action; the SPA surfaces them under
     * 系统日志 → 操作日志.
     */
    protected function log(string $description, int $activeId = 0, string $type = 'admin'): void
    {
        try {
            DB::table('activity_log')->insert([
                'create_time' => time(),
                'description' => $description,
                'user' => $this->adminName(),
                'uid' => $this->adminId(),
                'ipaddr' => (string) request()->ip(),
                'type' => 1,
                'activeid' => $activeId,
                'usertype' => $type,
                'port' => (string) request()->getPort(),
                'type_data_id' => 0,
            ]);
        } catch (\Throwable) {
            // Logging must never break the action that triggered it.
        }
    }

    /**
     * Fetch a model row or send a 404 envelope. Returns null when the caller
     * should return the response as-is.
     */
    protected function findOrFailJson(string $model, mixed $id, string $msg = '数据不存在'): array
    {
        $row = $model::query()->find($id);

        return [$row, $row === null ? $this->notFound($msg) : null];
    }
}
