<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a signed-in administrator for the admin API surface.
 *
 * Administrators authenticate against the `admin` guard (`shd_user`); the
 * JSON envelope matches the client-area one because the admin SPA parses
 * `status` / `msg` / `data` on every response.
 */
class AuthenticateAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth('admin')->check()) {
            return response()->json([
                'status' => 401,
                'msg' => '请先登录',
                'data' => null,
            ]);
        }

        $admin = auth('admin')->user();

        if (! $admin->isEnabled()) {
            auth('admin')->logout();

            return response()->json([
                'status' => 401,
                'msg' => '账号已被禁用',
                'data' => null,
            ]);
        }

        return $next($request);
    }
}
