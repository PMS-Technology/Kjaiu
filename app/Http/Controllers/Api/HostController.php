<?php

namespace App\Http\Controllers\Api;

use App\Models\Service;
use App\Services\BillingService;
use App\Services\SupplierProvisioningOutbox;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class HostController extends ApiController
{
    private const STATUSES = [
        'Unpaid' => ['name' => '待付款', 'color' => '#fca426'],
        'Pending' => ['name' => '待开通', 'color' => '#fca426'],
        'Active' => ['name' => '已激活', 'color' => '#3fbf70'],
        'Cancelled' => ['name' => '被取消', 'color' => '#959799'],
        'Fraud' => ['name' => '有欺诈', 'color' => '#ff0000'],
        'Deleted' => ['name' => '被删除', 'color' => '#2d2d2d'],
        'Suspended' => ['name' => '已暂停', 'color' => '#e15151'],
        'Failed' => ['name' => '开通失败', 'color' => '#e15151'],
    ];

    public function index(
        Request $request,
        SupplierProvisioningOutbox $supplierOutbox,
    ): JsonResponse {
        $limit = max(1, min(100, $request->integer('limit', 20)));
        $statuses = $request->input('domainstatus', $request->input('status'));
        $statuses = is_array($statuses) ? $statuses : array_filter([(string) $statuses]);
        $keywords = trim((string) $request->input('keywords'));

        $query = Service::query()
            ->where('user_id', $request->user()->id)
            ->with('product')
            ->when($statuses !== [], fn ($builder) => $builder->whereIn('status', $statuses))
            ->when($request->filled('cate_id'), fn ($builder) => $builder->whereHas(
                'product', fn ($product) => $product->where('product_group_id', $request->integer('cate_id'))
            ))
            ->when($keywords !== '', fn ($builder) => $builder->where(function ($search) use ($keywords) {
                $search->where('name', 'like', "%$keywords%")
                    ->orWhere('domain', 'like', "%$keywords%")
                    ->orWhere('dedicated_ip', 'like', "%$keywords%");
            }));

        $sort = strtolower((string) $request->input('sort')) === 'asc' ? 'asc' : 'desc';
        $orderBy = in_array($request->input('orderby'), ['id', 'next_due_at', 'status'], true)
            ? $request->input('orderby')
            : 'id';
        $paginator = $query->orderBy($orderBy, $sort)->paginate($limit);

        return $this->success([
            'total' => $paginator->total(),
            'host' => collect($paginator->items())
                ->map(fn (Service $service) => $this->host($service, $supplierOutbox))
                ->values(),
            'domainstatus' => self::STATUSES,
        ]);
    }

    public function show(
        Request $request,
        Service $service,
        SupplierProvisioningOutbox $supplierOutbox,
    ): JsonResponse {
        if ($service->user_id !== $request->user()->id) {
            return $this->error('产品不存在', 404);
        }

        $service->load('product');

        return $this->success([
            'host' => $this->host($service, $supplierOutbox),
            'currency' => config('kjaiu.currency'),
        ]);
    }

    public function renewPage(
        Request $request,
        Service $service,
        SupplierProvisioningOutbox $supplierOutbox,
    ): JsonResponse {
        if ($service->user_id !== $request->user()->id) {
            return $this->error('产品不存在', 404);
        }
        if (! in_array($service->status, ['Active', 'Suspended'], true)) {
            return $this->error('产品不可续费');
        }
        if ($supplierOutbox->isSupplierManaged($service)) {
            return $this->error('当前版本暂不支持上游供应商服务续费');
        }

        $product = $service->product()->with('prices')->first();
        $cycles = collect();
        if ($product
            && ! in_array($product->billing_cycle, ['free', 'onetime'], true)
            && ! $supplierOutbox->isSupplierManaged($service, $product->billing_cycle)) {
            $cycles->push([
                'setup_fee' => $product->setup_fee,
                'price' => $product->price,
                'billingcycle' => $product->billing_cycle,
                'billingcycle_zh' => $this->cycleName($product->billing_cycle),
                'amount' => $product->price,
                'saleproducts' => '0.00',
            ]);
        }
        if ($product) {
            foreach ($product->prices->where('is_active', true) as $price) {
                if (in_array($price->billing_cycle, ['free', 'onetime'], true)
                    || $cycles->contains('billingcycle', $price->billing_cycle)
                    || $supplierOutbox->isSupplierManaged($service, $price->billing_cycle)) {
                    continue;
                }
                $cycles->push([
                    'setup_fee' => $price->setup_fee,
                    'price' => $price->price,
                    'billingcycle' => $price->billing_cycle,
                    'billingcycle_zh' => $this->cycleName($price->billing_cycle),
                    'amount' => $price->price,
                    'saleproducts' => '0.00',
                ]);
            }
        }

        return $this->success([
            'currency' => config('kjaiu.currency'),
            'cycle' => $cycles,
            'pay_type' => ['pay_type' => 'recurring'],
        ]);
    }

    public function renew(
        Request $request,
        Service $service,
        BillingService $billing,
        SupplierProvisioningOutbox $supplierOutbox,
    ): JsonResponse {
        if ($service->user_id !== $request->user()->id) {
            return $this->error('产品不存在', 404);
        }

        $validator = Validator::make($request->all(), [
            'billingcycle' => ['nullable', 'string', 'max:32'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }
        $billingCycle = $request->string('billingcycle', $service->billing_cycle)->toString();
        try {
            $supplierOutbox->ensureLocalRenewalAvailable($service, $billingCycle);
        } catch (DomainException $exception) {
            $validator->errors()->add('service', $exception->getMessage());

            return $this->validationError($validator->errors());
        }

        try {
            $invoice = $billing->createRenewalInvoice(
                $request->user(),
                $service,
                $billingCycle,
            );
        } catch (ValidationException $exception) {
            return $this->validationError($exception->validator->errors());
        }

        return $this->success(['invoice_id' => $invoice->id, 'invoiceid' => $invoice->id], '续费账单创建成功');
    }

    public function autoRenew(
        Request $request,
        Service $service,
        SupplierProvisioningOutbox $supplierOutbox,
    ): JsonResponse {
        if ($service->user_id !== $request->user()->id) {
            return $this->error('产品不存在', 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => ['required_without:initiative_renew', 'boolean'],
            'initiative_renew' => ['required_without:status', 'boolean'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $autoRenew = $request->has('status')
            ? $request->boolean('status')
            : $request->boolean('initiative_renew');

        try {
            DB::transaction(function () use ($request, $service, $supplierOutbox, $autoRenew): void {
                $lockedService = Service::query()
                    ->where('user_id', $request->user()->id)
                    ->lockForUpdate()
                    ->findOrFail($service->id);
                if (! $autoRenew) {
                    $lockedService->update(['auto_renew' => false]);

                    return;
                }

                $product = $lockedService->product()->lockForUpdate()->first();
                if ($product) {
                    $product->setRelation(
                        'prices',
                        $product->prices()->orderBy('id')->lockForUpdate()->get(),
                    );
                }
                $price = $product?->priceFor((string) $lockedService->billing_cycle);

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

                if ($lockedService->status !== 'Active'
                    || ! $lockedService->next_due_at
                    || ! in_array($lockedService->billing_cycle, [
                        'hourly',
                        'daily',
                        'weekly',
                        'monthly',
                        'quarterly',
                        'semiannually',
                        'annually',
                        'yearly',
                        'biennially',
                        'triennially',
                    ], true)
                    || ! $product?->is_active
                    || ! $price?->is_active) {
                    throw ValidationException::withMessages([
                        'auto_renew' => '当前服务不可启用自动续费',
                    ]);
                }

                $lockedService->update(['auto_renew' => true]);
            }, 3);
        } catch (ValidationException $exception) {
            return $this->validationError($exception->validator->errors());
        }

        return $this->success([], '修改成功');
    }

    public function moduleStatus(Request $request, Service $service): JsonResponse
    {
        if ($service->user_id !== $request->user()->id) {
            return $this->error('产品不存在', 404);
        }

        return $this->success([
            'status' => match ($service->status) {
                'Active' => 'on',
                'Pending' => 'pending',
                default => 'off',
            },
            'desc' => self::STATUSES[$service->status]['name'] ?? $service->status,
        ]);
    }

    private function host(
        Service $service,
        SupplierProvisioningOutbox $supplierOutbox,
    ): array {
        return [
            'id' => $service->id,
            'type' => $service->type,
            'domain' => $service->domain ?: $service->name,
            'domainstatus' => $service->status,
            'regdate' => $service->registered_at?->timestamp ?? $service->created_at?->timestamp,
            'nextduedate' => $service->next_due_at?->timestamp ?? 0,
            'firstpaymentamount' => $service->first_payment_amount,
            'amount' => $service->renew_amount,
            'billingcycle' => $service->billing_cycle,
            'dedicatedip' => $service->dedicated_ip ?? '',
            'assignedips' => $service->assigned_ips ?? [],
            'initiative_renew' => $service->auto_renew
                && ! $supplierOutbox->isSupplierManaged($service) ? 1 : 0,
            'notes' => $service->notes ?? '',
            'product_id' => $service->product_id,
            'product_name' => $service->product?->name ?? $service->name,
            'host_cancel' => null,
        ];
    }

    private function cycleName(string $cycle): string
    {
        return [
            'free' => '免费',
            'hourly' => '小时付',
            'daily' => '天付',
            'monthly' => '月付',
            'quarterly' => '季付',
            'semiannually' => '半年付',
            'annually' => '年付',
            'biennially' => '两年付',
            'triennially' => '三年付',
            'onetime' => '一次性',
        ][$cycle] ?? $cycle;
    }
}
