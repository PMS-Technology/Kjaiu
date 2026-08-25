<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateFinanceApi
{
    public function __construct(private readonly JwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $authorization = trim((string) $request->header('Authorization'));
        if (! preg_match('/^(?:JWT|Bearer)\s+(.+)$/i', $authorization, $matches)) {
            return response()->json(['status' => 405, 'msg' => '请登陆后再试']);
        }

        try {
            $claims = $this->jwt->parse($matches[1]);
            $user = User::query()->whereKey($claims['sub'])->first();
        } catch (Throwable) {
            $user = null;
        }

        if (! $user || $user->status !== 'Active' || (int) $claims['ver'] !== $user->token_version) {
            return response()->json(['status' => 405, 'msg' => '登录状态已失效，请重新登录']);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
