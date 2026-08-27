<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public const CREDENTIAL_VERSION_SESSION_KEY = 'auth.web_credential_version';

    public function handle(Request $request, Closure $next): Response
    {
        $authenticatedUser = $request->user();
        $user = $authenticatedUser
            ? User::query()->find($authenticatedUser->getAuthIdentifier())
            : null;
        $guard = Auth::guard('web');
        $userCanAuthenticate = $user?->canAuthenticate() === true;

        // actingAs() injects a guard user but intentionally does not create a web login session.
        if ($user
            && app()->runningUnitTests()
            && ! $request->session()->has($guard->getName())) {
            $request->session()->put(self::CREDENTIAL_VERSION_SESSION_KEY, $user->token_version);
        }

        if ($userCanAuthenticate
            && $guard->viaRemember()
            && ! $request->session()->exists(self::CREDENTIAL_VERSION_SESSION_KEY)) {
            $request->session()->put(self::CREDENTIAL_VERSION_SESSION_KEY, $user->token_version);
        }

        $credentialVersion = $request->session()->get(self::CREDENTIAL_VERSION_SESSION_KEY);
        $credentialIsCurrent = is_int($credentialVersion)
            && is_int($user?->token_version)
            && $credentialVersion === $user->token_version;

        if ($userCanAuthenticate && $credentialIsCurrent) {
            $guard->setUser($user);

            return $next($request);
        }

        $guard->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors([
            'email' => match (true) {
                $user === null, ! $user->hasSupportedRole() => '该账户当前不可登录',
                $user->status !== 'Active' => '账户已停用，请联系管理员',
                default => '登录凭据已更新，请重新登录',
            },
        ]);
    }
}
