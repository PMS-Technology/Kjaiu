<?php

namespace App\Support;

use App\Models\Configuration;

/**
 * The JSON envelope used by both the original administrator API and the
 * client-area AJAX endpoints:
 *
 *   { "status": 200, "msg": "请求成功", "data": { ... } }
 *
 * `status` is emitted as an integer; some of the original templates compare it
 * loosely against the string "200", so keep the value stable.
 */
class ApiResponse
{
    public const OK = 200;
    public const FAIL = 400;
    public const VALIDATION_FAILED = 406;
    public const UNAUTHORIZED = 401;
    public const ERROR = 500;
    /** Soft success: nothing to pay / nothing to do. */
    public const NOTICE = 1001;

    public static function success(mixed $data = null, string $msg = '请求成功', array $extra = []): array
    {
        return array_merge([
            'status' => self::OK,
            'msg' => $msg,
            'data' => $data,
        ], $extra);
    }

    public static function error(string $msg = '操作失败', int $status = self::FAIL, mixed $data = null, array $extra = []): array
    {
        return array_merge([
            'status' => $status,
            'msg' => $msg,
            'data' => $data,
        ], $extra);
    }

    public static function validationError(string $msg): array
    {
        return self::error($msg, self::VALIDATION_FAILED);
    }

    /**
     * Company name / currency helpers shared by the client area and the API.
     */
    public static function siteName(): string
    {
        return (string) (Configuration::value('web_name') ?: config('kjaiu.name'));
    }
}
