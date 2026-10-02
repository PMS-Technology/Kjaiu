<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a signed-in client for storefront and client-area pages.
 */
class AuthenticateClient
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth('client')->check()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 401,
                    'msg' => '请先登录',
                    'data' => null,
                ]);
            }

            return redirect()->guest(route('client.login', ['redirect' => $request->fullUrl()]));
        }

        $client = auth('client')->user();

        if (! $client->isActive()) {
            auth('client')->logout();

            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 401,
                    'msg' => '账号已被停用',
                    'data' => null,
                ]);
            }

            return redirect()->route('client.login')->withErrors(['username' => '账号已被停用']);
        }

        return $next($request);
    }
}
