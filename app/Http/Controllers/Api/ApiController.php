<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\MessageBag;

abstract class ApiController extends Controller
{
    protected function success(array $data = [], string $message = '请求成功', array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'status' => 200,
            'msg' => $message,
            'data' => $data,
        ], $extra));
    }

    protected function error(string $message, int $status = 400, array $data = []): JsonResponse
    {
        $response = ['status' => $status, 'msg' => $message];
        if ($data !== []) {
            $response['data'] = $data;
        }

        return response()->json($response);
    }

    protected function validationError(MessageBag $errors): JsonResponse
    {
        return $this->error($errors->first(), 400, ['errors' => $errors->toArray()]);
    }
}
