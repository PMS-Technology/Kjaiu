<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Support\ApiResponse;
use App\Support\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates public API requests (/v1).
 *
 * The original platform expects the token in an `authorization: JWT <token>`
 * header; the response envelope matches the client-area one so downstream
 * clients parse it identically.
 */
class AuthenticateApi
{
    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        $token = JwtService::tokenFromRequest($request);

        if ($token === null) {
            return response()->json(ApiResponse::error('请先登录', ApiResponse::UNAUTHORIZED), 401);
        }

        $payload = (new JwtService())->decode($token);

        if ($payload === null) {
            return response()->json(ApiResponse::error('登录已过期，请重新登录', ApiResponse::UNAUTHORIZED), 401);
        }

        $clientId = (new JwtService())->clientId($payload);
        $client = $clientId ? Client::query()->find($clientId) : null;

        if ($client === null) {
            return response()->json(ApiResponse::error('账号不存在', ApiResponse::UNAUTHORIZED), 401);
        }

        if (! $client->isActive()) {
            return response()->json(ApiResponse::error('账号已被停用', ApiResponse::UNAUTHORIZED), 401);
        }

        // Bind the authenticated client for the rest of the request lifecycle.
        $request->setUserResolver(fn () => $client);
        app()->instance('api.client', $client);

        return $next($request);
    }
}
