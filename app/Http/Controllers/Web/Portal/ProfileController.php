<?php

namespace App\Http\Controllers\Web\Portal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureActiveUser;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        return view('portal.profile.show', ['user' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone_code' => ['required', 'string', 'max:8', 'regex:/^\+\d{1,6}$/'],
            'phone' => ['nullable', 'string', 'max:32', Rule::unique('users', 'phone')->ignore($request->user()->id)],
            'real_name' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'locale' => ['required', Rule::in(['zh_CN', 'en_US'])],
        ]);

        $request->user()->update($data);

        return back()->with('success', '账户资料已更新');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ]);

        $currentSessionId = $request->session()->getId();
        $user = DB::transaction(function () use ($request, $data, $currentSessionId) {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => '当前密码不正确',
                ]);
            }

            $user->forceFill([
                'password' => $data['password'],
                'token_version' => $user->token_version + 1,
                'remember_token' => Str::random(60),
            ])->save();

            $this->revokeOtherDatabaseSessions($user, $currentSessionId);

            return $user->fresh();
        }, 3);

        Auth::guard('web')->login($user);
        $request->session()->put(
            EnsureActiveUser::CREDENTIAL_VERSION_SESSION_KEY,
            $user->token_version,
        );
        $request->session()->regenerateToken();

        return back()->with('success', '密码已更新，当前会话仍保持登录');
    }

    private function revokeOtherDatabaseSessions(User $user, string $currentSessionId): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();
    }
}
