<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends ApiController
{
    public function methods(): JsonResponse
    {
        return response()->json([
            'status' => 200,
            'data' => [
                'login_email' => ['captcha' => 0],
                'login_id' => ['captcha' => 0],
                'Oauth' => [],
            ],
        ]);
    }

    public function login(Request $request, JwtService $jwt): JsonResponse
    {
        [$field, $value, $password] = $this->credentials($request);

        $validator = Validator::make(
            [$field => $value, 'password' => $password],
            [$field => ['required', 'string'], 'password' => ['required', 'string']],
            ["$field.required" => ucfirst($field).' can not be empty', 'password.required' => 'Password can not be empty'],
        );

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $user = User::query()->where($field, $value)->first();
        if (! $user || ! Hash::check($password, $user->password)) {
            return $this->error('账号或密码错误');
        }

        if ($user->status !== 'Active') {
            return $this->error('账号已被停用');
        }

        $token = $jwt->issue($user);

        return response()->json([
            'status' => 200,
            'msg' => 'login successful',
            'jwt' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->increment('token_version');

        return $this->success([], '退出成功');
    }

    private function credentials(Request $request): array
    {
        if (is_array($request->input('email'))) {
            return [
                'email',
                trim((string) $request->input('email.email')),
                (string) $request->input('email.password'),
            ];
        }

        if (is_array($request->input('phone'))) {
            return [
                'phone',
                trim((string) $request->input('phone.phone')),
                (string) $request->input('phone.password'),
            ];
        }

        if (is_array($request->input('id'))) {
            return [
                'id',
                trim((string) $request->input('id.id')),
                (string) $request->input('id.password'),
            ];
        }

        if ($request->filled('email')) {
            return ['email', trim((string) $request->input('email')), (string) $request->input('password')];
        }

        if ($request->filled('phone')) {
            return ['phone', trim((string) $request->input('phone')), (string) $request->input('password')];
        }

        return ['id', trim((string) $request->input('id')), (string) $request->input('password')];
    }
}
