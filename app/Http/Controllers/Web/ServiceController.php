<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Service;
use App\Models\SupplierOperation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->withExists([
                'supplierServiceLinks as has_supplier_service_link',
                'supplierOperations as has_nonterminal_supplier_operation' => fn ($query) => $query
                    ->whereIn('status', SupplierOperation::NONTERMINAL_STATUSES),
            ])
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
        $serviceId = $service->getKey();

        return DB::transaction(function () use ($request, $serviceId): RedirectResponse {
            $supplierOperationStatuses = SupplierOperation::query()
                ->where('service_id', $serviceId)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('status');
            $service = Service::query()->lockForUpdate()->findOrFail($serviceId);
            $hasSupplierServiceLink = $service->supplierServiceLinks()
                ->lockForUpdate()
                ->limit(1)
                ->get(['id'])
                ->isNotEmpty();
            $supplierManaged = $hasSupplierServiceLink
                || $supplierOperationStatuses->contains(
                    fn (string $status): bool => in_array(
                        $status,
                        SupplierOperation::NONTERMINAL_STATUSES,
                        true,
                    ),
                );
            $data = $request->validate([
                'status' => $supplierManaged
                    ? ['sometimes', Rule::in([$service->status])]
                    : ['required', Rule::in(self::TRANSITIONS[$service->status] ?? [$service->status])],
                'dedicated_ip' => ['nullable', 'ip'],
                'internal_notes' => ['nullable', 'string', 'max:5000'],
            ], [
                'status.in' => $supplierManaged
                    ? '供应商托管服务的状态由上游状态与对账流程控制，不能在此修改。'
                    : '所选服务状态转换无效。',
            ]);

            if ($supplierManaged) {
                unset($data['status']);
            }

            $before = $service->only(['status', 'dedicated_ip', 'internal_notes']);
            if (array_key_exists('status', $data)) {
                $data['activated_at'] = $data['status'] === 'Active' && ! $service->activated_at
                    ? now()
                    : $service->activated_at;
            }
            $service->update($data);
            $auditRequest = clone $request;
            if ($supplierManaged) {
                $auditRequest->headers->set('User-Agent', '[REDACTED]');
            }
            AuditLog::record($auditRequest, 'service.updated', $service, $before, $service->only(['status', 'dedicated_ip', 'internal_notes']));

            return back()->with(
                'success',
                $supplierManaged ? '服务信息已更新' : '服务状态已更新',
            );
        }, 3);
    }
}
