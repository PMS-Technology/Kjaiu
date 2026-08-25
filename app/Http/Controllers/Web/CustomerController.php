<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $keyword = trim((string) $request->input('q'));
        $customers = User::query()
            ->where('role', 'client')
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
            ? User::query()->where('role', 'client')->findOrFail($request->integer('edit'))
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
            'password' => ['required', 'string', 'min:8', 'max:72'],
        ]);

        $customer = User::create($data + ['role' => 'client', 'status' => 'Active']);
        AuditLog::record($request, 'customer.created', $customer, null, $customer->only(['name', 'email', 'status']));

        return redirect()->route('admin.customers.index')->with('success', '客户已创建');
    }

    public function update(Request $request, User $customer): RedirectResponse
    {
        abort_unless($customer->role === 'client', 404);
        $before = $customer->only(['name', 'email', 'phone', 'company_name', 'status']);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users')->ignore($customer)],
            'phone' => ['nullable', 'string', 'max:32', Rule::unique('users')->ignore($customer)],
            'company_name' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['Active', 'Suspended'])],
            'password' => ['nullable', 'string', 'min:8', 'max:72'],
        ]);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }
        if (array_key_exists('password', $data) || $data['status'] !== $customer->status) {
            $data['token_version'] = $customer->token_version + 1;
        }
        $customer->update($data);
        AuditLog::record(
            $request,
            'customer.updated',
            $customer,
            $before,
            $customer->only(['name', 'email', 'phone', 'company_name', 'status']),
        );

        return redirect()->route('admin.customers.index')->with('success', '客户资料已更新');
    }
}
