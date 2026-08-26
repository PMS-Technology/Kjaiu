<?php

namespace App\Http\Controllers\Web\Portal;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\BillingService;
use App\Services\SupplierProvisioningOutbox;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ServiceController extends Controller
{
    public function index(Request $request, SupplierProvisioningOutbox $supplierOutbox): View
    {
        $status = (string) $request->input('status');
        $services = Service::query()
            ->where('user_id', $request->user()->id)
            ->select($this->safeColumns())
            ->with('product:id,name')
            ->when(in_array($status, ['Pending', 'Active', 'Suspended', 'Cancelled', 'Failed', 'Deleted'], true), fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->paginate(15)
            ->withQueryString();
        $services->getCollection()->each(
            fn (Service $service) => $service->setAttribute(
                'supplier_managed',
                $supplierOutbox->isSupplierManaged($service),
            ),
        );

        return view('portal.services.index', compact('services', 'status'));
    }

    public function show(
        Request $request,
        int $service,
        SupplierProvisioningOutbox $supplierOutbox,
    ): View {
        $service = $this->ownedService($request, $service);
        $service->load([
            'product' => fn ($query) => $query
                ->select(['id', 'name', 'type', 'description', 'billing_cycle', 'price', 'setup_fee', 'is_active'])
                ->with(['prices' => fn ($prices) => $prices->where('is_active', true)->orderBy('id')]),
        ]);
        $supplierManaged = $supplierOutbox->isSupplierManaged($service);

        return view('portal.services.show', [
            'service' => $service,
            'renewalCycles' => $this->renewalCycles($service, $supplierOutbox, $supplierManaged),
            'supplierManaged' => $supplierManaged,
        ]);
    }

    public function renew(
        Request $request,
        int $service,
        BillingService $billing,
        SupplierProvisioningOutbox $supplierOutbox,
    ): RedirectResponse {
        $service = $this->ownedService($request, $service);
        $service->load(['product.prices']);
        $data = $request->validate([
            'billing_cycle' => ['required', 'string', 'max:32'],
        ]);
        try {
            $supplierOutbox->ensureLocalRenewalAvailable($service, $data['billing_cycle']);
        } catch (DomainException $exception) {
            return back()->withErrors(['service' => $exception->getMessage()])->withInput();
        }
        if (! $service->product?->is_active) {
            return back()->withErrors(['service' => '当前产品已停用，无法创建续费账单']);
        }
        $cycles = array_keys($this->renewalCycles($service, $supplierOutbox, false));
        $request->validate([
            'billing_cycle' => ['required', 'string', Rule::in($cycles)],
        ]);

        try {
            $invoice = $billing->createRenewalInvoice($request->user(), $service, $data['billing_cycle']);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return redirect()->route('portal.invoices.show', $invoice)->with('success', '续费账单已创建');
    }

    public function updateAutoRenew(
        Request $request,
        int $service,
        SupplierProvisioningOutbox $supplierOutbox,
    ): RedirectResponse {
        $service = $this->ownedService($request, $service);
        $data = $request->validate([
            'auto_renew' => ['required', 'boolean'],
        ]);
        $autoRenew = (bool) $data['auto_renew'];
        if ($autoRenew) {
            try {
                DB::transaction(function () use ($request, $service, $supplierOutbox): void {
                    $lockedService = Service::query()
                        ->where('user_id', $request->user()->id)
                        ->lockForUpdate()
                        ->findOrFail($service->id);
                    try {
                        $supplierOutbox->ensureLocalRenewalAvailable(
                            $lockedService,
                            (string) $lockedService->billing_cycle,
                        );
                    } catch (DomainException) {
                        throw ValidationException::withMessages([
                            'auto_renew' => '当前版本暂不支持上游供应商服务自动续费',
                        ]);
                    }

                    $lockedService->load('product.prices');
                    $product = $lockedService->product;
                    $price = $product?->priceFor($lockedService->billing_cycle);
                    if ($lockedService->status !== 'Active'
                        || in_array($lockedService->billing_cycle, ['free', 'onetime'], true)
                        || ! $lockedService->next_due_at
                        || ! $product?->is_active
                        || ! $price?->is_active) {
                        throw ValidationException::withMessages([
                            'auto_renew' => '当前服务不可启用自动续费',
                        ]);
                    }

                    $lockedService->update(['auto_renew' => true]);
                }, 3);
            } catch (ValidationException $exception) {
                return back()->withErrors($exception->errors());
            }

            return back()->with('success', '已启用余额自动续费');
        }

        $service->update(['auto_renew' => false]);

        return back()->with('success', '已关闭余额自动续费');
    }

    private function ownedService(Request $request, int $service): Service
    {
        return Service::query()
            ->where('user_id', $request->user()->id)
            ->select($this->safeColumns())
            ->findOrFail($service);
    }

    private function safeColumns(): array
    {
        return [
            'id', 'user_id', 'order_id', 'order_item_id', 'product_id', 'name', 'domain', 'type',
            'status', 'first_payment_amount', 'renew_amount', 'billing_cycle', 'registered_at',
            'billing_anchor_day', 'activated_at', 'next_due_at', 'dedicated_ip', 'assigned_ips',
            'auto_renew', 'notes', 'created_at', 'updated_at',
        ];
    }

    private function renewalCycles(
        Service $service,
        SupplierProvisioningOutbox $supplierOutbox,
        bool $supplierManaged,
    ): array {
        $product = $service->product;
        if ($supplierManaged
            || ! $product?->is_active
            || ! in_array($service->status, ['Active', 'Suspended'], true)) {
            return [];
        }

        $cycles = [];
        if (! in_array($product->billing_cycle, ['free', 'onetime'], true)
            && ! $supplierOutbox->isSupplierManaged($service, $product->billing_cycle)) {
            $cycles[$product->billing_cycle] = $product->price;
        }
        foreach ($product->prices->where('is_active', true) as $price) {
            if (! in_array($price->billing_cycle, ['free', 'onetime'], true)
                && ! $supplierOutbox->isSupplierManaged($service, $price->billing_cycle)) {
                $cycles[$price->billing_cycle] = $price->price;
            }
        }

        return $cycles;
    }
}
