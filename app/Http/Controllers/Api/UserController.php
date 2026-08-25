<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->success([
            'client' => [
                'phone_code' => $user->phone_code,
                'phone' => $user->phone ?? '',
                'email' => $user->email ?? '',
                'qq' => '',
                'username' => $user->username ?: $user->name,
                'companyname' => $user->company_name,
                'country' => 'CN',
                'province' => '',
                'city' => '',
                'region' => '',
                'address' => '',
                'defaultgateway' => '',
                'marketing_emails_opt_in' => 0,
                'credit' => $user->credit,
            ],
            'country' => [
                ['CN', 'China', '中国', '86'],
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'locale' => ['sometimes', 'string', 'max:16'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $request->user()->update($validator->validated());

        return $this->success([], '修改成功');
    }

    public function password(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'old_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'max:72'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        if (! Hash::check($request->string('old_password')->toString(), $request->user()->password)) {
            return $this->error('原密码错误');
        }

        $request->user()->update([
            'password' => $request->string('new_password')->toString(),
            'token_version' => $request->user()->token_version + 1,
        ]);

        return $this->success([], '密码修改成功');
    }
}
