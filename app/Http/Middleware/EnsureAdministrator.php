<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || ! $request->user()->isAdministrator() || $request->user()->status !== 'Active') {
            $request->session()->invalidate();
            abort(403);
        }

        return $next($request);
    }
}
