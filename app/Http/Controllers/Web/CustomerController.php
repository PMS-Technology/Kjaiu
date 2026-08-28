<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Transaction;
use App\Services\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $keyword = trim((string) $request->input('q'));
        $customers = User::query()
            ->whereIn('role', User::ROLES)
            ->withCount(['services', 'invoices'])
            ->when($keyword !== '', fn ($query) => $query->where(function ($search) use ($keyword) {
                $search->where('name', 'like', "%$keyword%")
                    ->orWhere('email', 'like', "%$keyword%")
                    ->orWhere('phone', 'like', "%$keyword%")
                    ->orWhere('id', $keyword);
            }))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $editing = $request->integer('edit')
            ? User::query()->whereIn('role', User::ROLES)->findOrFail($request->integer('edit'))
            : null;

        return view('admin.customers.index', compact('customers', 'editing', 'keyword'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32', 'unique:users,phone'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'role' => ['required', Rule::in(User::ROLES)],
            'password' => ['required', 'string', 'min:8', 'max:72'],
        ]);

        $customer = User::create($data + ['status' => 'Active']);
        AuditLog::record($request, 'customer.created', $customer, null, $customer->only(['name', 'email', 'role', 'status']));

        return redirect()->route('admin.customers.index')->with('success', '用户已创建');
    }

    public function update(Request $request, User $customer): RedirectResponse
    {
        abort_unless($customer->hasSupportedRole(), 404);
        $before = $customer->only(['name', 'email', 'phone', 'company_name', 'role', 'status']);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users')->ignore($customer)],
            'phone' => ['nullable', 'string', 'max:32', Rule::unique('users')->ignore($customer)],
            'company_name' => ['nullable', 'string', 'max:255'],
            'role' => ['required', Rule::in(User::ROLES)],
            'status' => ['required', Rule::in(['Active', 'Suspended'])],
            'password' => ['nullable', 'string', 'min:8', 'max:72'],
        ]);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }
        $customerId = $customer->id;
        $customer = DB::transaction(function () use ($customerId, $data) {
            $customer = User::query()
                ->whereIn('role', User::ROLES)
                ->lockForUpdate()
                ->findOrFail($customerId);
            $removesActiveAdministrator = $customer->isAdministrator()
                && $customer->status === 'Active'
                && ($data['role'] !== User::ROLE_ADMIN || $data['status'] !== 'Active');
            if ($removesActiveAdministrator) {
                $hasAnotherActiveAdministrator = User::query()
                    ->where('role', User::ROLE_ADMIN)
                    ->where('status', 'Active')
                    ->whereKeyNot($customer->getKey())
                    ->lockForUpdate()
                    ->get(['id'])
                    ->isNotEmpty();
                if (! $hasAnotherActiveAdministrator) {
                    throw ValidationException::withMessages([
                        'role' => '不能停用或降级最后一个正常管理员。',
                    ]);
                }
            }
            $securityChanged = array_key_exists('password', $data)
                || $data['status'] !== $customer->status
                || $data['role'] !== $customer->role;
            if ($securityChanged) {
                $data['token_version'] = $customer->token_version + 1;
                $data['remember_token'] = Str::random(60);
            }

            $customer->forceFill($data)->save();
            if ($securityChanged) {
                $this->revokeDatabaseSessions($customer);
            }

            return $customer->fresh();
        }, 3);
        AuditLog::record(
            $request,
            'customer.updated',
            $customer,
            $before,
            $customer->only(['name', 'email', 'phone', 'company_name', 'role', 'status']),
        );

        return redirect()->route('admin.customers.index')->with('success', '用户资料已更新');
    }

    public function updateBalance(Request $request, User $customer): RedirectResponse
    {
        abort_unless($customer->role === User::ROLE_CLIENT && $customer->status === 'Active', 404);
        $data = $request->validate([
            'balance' => ['required', 'numeric', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/', 'min:0'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($customer, $data, $request): void {
            $user = User::query()->where('role', User::ROLE_CLIENT)->where('status', 'Active')
                ->lockForUpdate()->findOrFail($customer->id);
            $before = Money::toMinor($user->credit);
            $after = Money::toMinor($data['balance']);
            if ($after > Money::toMinor((string) config('kjaiu.funds.maximum_balance'))) {
                throw ValidationException::withMessages(['balance' => '余额超过系统上限']);
            }
            $change = $after - $before;
            if ($change === 0) {
                return;
            }
            $user->update(['credit' => Money::format($after)]);
            $transaction = Transaction::create([
                'user_id' => $user->id,
                'transaction_number' => strtoupper((string) Str::ulid()),
                'idempotency_key' => hash('sha256', "balance-set\0".(string) Str::uuid()),
                'type' => 'adjustment',
                'gateway' => 'Admin',
                'amount_in' => Money::format(max(0, $change)),
                'amount_out' => Money::format(max(0, -$change)),
                'balance_before' => Money::format($before),
                'balance_after' => Money::format($after),
                'currency' => config('kjaiu.currency.code', 'CNY'),
                'paid_at' => now(),
                'metadata' => ['reason' => $data['reason'], 'mode' => 'set_balance'],
            ]);
            AuditLog::record($request, 'credit.adjusted', $user, ['credit' => Money::format($before)], [
                'credit' => Money::format($after),
                'transaction_id' => $transaction->id,
                'reason' => $data['reason'],
            ]);
        }, 3);

        return back()->with('success', '用户余额已更新并记录资金流水');
    }

    private function revokeDatabaseSessions(User $customer): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table((string) config('session.table', 'sessions'))
            ->where('user_id', $customer->id)
            ->delete();
    }
}
