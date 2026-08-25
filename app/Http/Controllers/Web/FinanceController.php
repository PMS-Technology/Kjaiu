<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FinanceController extends Controller
{
    public function index(Request $request): View
    {
        $keyword = trim((string) $request->input('q'));
        $transactions = Transaction::query()
            ->with(['user', 'invoice'])
            ->when($keyword !== '', fn ($query) => $query->where(function ($search) use ($keyword) {
                $search->where('transaction_number', 'like', "%$keyword%")
                    ->orWhere('gateway', 'like', "%$keyword%")
                    ->orWhereHas('user', fn ($user) => $user
                        ->where('name', 'like', "%$keyword%")
                        ->orWhere('email', 'like', "%$keyword%"));
            }))
            ->latest('paid_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.finance.index', [
            'transactions' => $transactions,
            'keyword' => $keyword,
            'customers' => User::query()->where('role', 'client')->where('status', 'Active')->orderBy('name')->get(),
            'summary' => [
                'in' => Transaction::query()->sum('amount_in'),
                'out' => Transaction::query()->sum('amount_out'),
                'fees' => Transaction::query()->sum('fee'),
            ],
        ]);
    }

    public function adjust(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->where(
                fn ($query) => $query->where('role', 'client')->where('status', 'Active')
            )],
            'amount' => ['required', 'numeric', 'regex:/^-?\d{1,12}(?:\.\d{1,2})?$/', 'not_in:0,0.0,0.00,-0,-0.0,-0.00'],
            'reason' => ['required', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);

        try {
            $created = DB::transaction(function () use ($data, $request) {
                $user = User::query()
                    ->where('role', 'client')
                    ->where('status', 'Active')
                    ->lockForUpdate()
                    ->findOrFail($data['user_id']);
                $change = Money::toMinor($data['amount']);
                $existing = Transaction::query()
                    ->where('user_id', $user->id)
                    ->where('idempotency_key', $data['idempotency_key'])
                    ->first();
                if ($existing) {
                    $existingChange = Money::toMinor($existing->amount_in) - Money::toMinor($existing->amount_out);
                    if ($existingChange !== $change || ($existing->metadata['reason'] ?? null) !== $data['reason']) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => '该幂等键已用于不同的调账请求',
                        ]);
                    }

                    return false;
                }

                $before = Money::toMinor($user->credit);
                $after = $before + $change;
                if ($after < 0) {
                    throw ValidationException::withMessages(['amount' => '调整后余额不能小于 0']);
                }
                if ($after > Money::toMinor((string) config('kjaiu.funds.maximum_balance'))) {
                    throw ValidationException::withMessages(['amount' => '调整后余额超过系统上限']);
                }

                $user->update(['credit' => Money::format($after)]);
                $transaction = Transaction::create([
                    'user_id' => $user->id,
                    'transaction_number' => strtoupper((string) Str::ulid()),
                    'idempotency_key' => $data['idempotency_key'],
                    'type' => 'adjustment',
                    'gateway' => 'Admin',
                    'amount_in' => Money::format(max(0, $change)),
                    'amount_out' => Money::format(max(0, -$change)),
                    'balance_before' => Money::format($before),
                    'balance_after' => Money::format($after),
                    'currency' => (string) config('kjaiu.currency.code', 'CNY'),
                    'paid_at' => now(),
                    'metadata' => ['reason' => $data['reason']],
                ]);
                AuditLog::record($request, 'credit.adjusted', $user, [
                    'credit' => Money::format($before),
                ], [
                    'credit' => Money::format($after),
                    'transaction_id' => $transaction->id,
                    'reason' => $data['reason'],
                ]);

                return true;
            });
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return back()->with('success', $created ? '余额调整已入账' : '该调账请求已处理');
    }
}
