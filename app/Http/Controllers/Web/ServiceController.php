<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ServiceController extends Controller
{
    private const TRANSITIONS = [
        'Pending' => ['Pending', 'Active', 'Cancelled', 'Failed'],
        'Active' => ['Active', 'Suspended', 'Cancelled'],
        'Suspended' => ['Suspended', 'Active', 'Cancelled'],
        'Cancelled' => ['Cancelled', 'Deleted'],
        'Failed' => ['Failed', 'Pending', 'Cancelled'],
        'Deleted' => ['Deleted'],
    ];

    public function index(Request $request): View
    {
        $keyword = trim((string) $request->input('q'));
        $status = (string) $request->input('status');
        $services = Service::query()
            ->with(['user', 'product'])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($keyword !== '', fn ($query) => $query->where(function ($search) use ($keyword) {
                $search->where('name', 'like', "%$keyword%")
                    ->orWhere('domain', 'like', "%$keyword%")
                    ->orWhere('dedicated_ip', 'like', "%$keyword%")
                    ->orWhereHas('user', fn ($user) => $user->where('email', 'like', "%$keyword%"));
            }))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.services.index', [
            'services' => $services,
            'keyword' => $keyword,
            'status' => $status,
            'transitions' => self::TRANSITIONS,
        ]);
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(self::TRANSITIONS[$service->status] ?? [$service->status])],
            'dedicated_ip' => ['nullable', 'ip'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $before = $service->only(['status', 'dedicated_ip', 'internal_notes']);
        $data['activated_at'] = $data['status'] === 'Active' && ! $service->activated_at
            ? now()
            : $service->activated_at;
        $service->update($data);
        AuditLog::record($request, 'service.updated', $service, $before, $service->only(['status', 'dedicated_ip', 'internal_notes']));

        return back()->with('success', '服务状态已更新');
    }
}
