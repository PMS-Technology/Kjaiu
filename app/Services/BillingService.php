<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductPrice;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BillingService
{
    private const MAX_DATABASE_AMOUNT_MINOR = 999_999_999_999_999_999;

    public function __construct(
        private readonly SupplierProvisioningOutbox $supplierOutbox,
    ) {}

    /**
     * @param  array<int, int>|null  $positions  Zero-based cart positions.
     */
    public function checkout(
        User $user,
        ?array $positions,
        ?string $idempotencyKey,
        string $gateway,
    ): Invoice {
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);
        $positions = $this->normalizePositions($positions);
        $fingerprint = $idempotencyKey === null ? null : hash('sha256', json_encode([
            'positions' => $positions,
            'gateway' => strtolower($gateway),
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $positions, $idempotencyKey, $fingerprint, $gateway) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->ensureActiveUser($lockedUser);

            if ($idempotencyKey !== null) {
                $existingOrder = Order::query()
                    ->where('user_id', $lockedUser->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existingOrder) {
                    if ($existingOrder->notes !== "idempotency:$fingerprint") {
                        throw ValidationException::withMessages([
                            'idempotency_key' => '该幂等键已用于不同的结算请求',
                        ]);
                    }

                    $invoice = $existingOrder->invoice()
                        ->with(['items', 'order.items.product'])
                        ->first();

                    if ($invoice) {
                        return $invoice;
                    }

                    throw ValidationException::withMessages([
                        'idempotency_key' => '该幂等请求仍在处理中，请稍后重试',
                    ]);
                }
            }

            $cartItems = CartItem::query()
                ->where('user_id', $lockedUser->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->values();

            $selectedItems = $this->selectCartItems($cartItems, $positions);
            $productIds = $selectedItems->pluck('product_id')->unique()->sort()->values();
            $products = Product::query()
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $this->lockCheckoutEligibility($products);
            $prices = ProductPrice::query()
                ->whereIn('product_id', $productIds)
                ->orderBy('product_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->groupBy('product_id');
            foreach ($products as $product) {
                $product->setRelation('prices', $prices->get($product->id, collect()));
            }

            try {
                $mappings = $this->supplierOutbox->activeMappings($selectedItems
                    ->map(fn (CartItem $item): array => [
                        'product_id' => (int) $item->product_id,
                        'billing_cycle' => (string) $item->billing_cycle,
                    ])
                    ->all());
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['product_id' => $exception->getMessage()]);
            }

            $lines = [];
            $reservations = [];
            $subtotalMinor = 0;
            $currency = strtoupper((string) config('kjaiu.currency.code', 'CNY'));

            foreach ($selectedItems as $cartItem) {
                $product = $products->get($cartItem->product_id);
                if (! $product
                    || ! $product->is_active
                    || ! $product->group?->is_active
                    || ! $product->group?->parent?->is_active) {
                    throw ValidationException::withMessages(['product_id' => '商品不存在或已下架']);
                }

                $price = $product->priceFor($cartItem->billing_cycle);
                if (! $price || ! $price->is_active) {
                    throw ValidationException::withMessages(['billing_cycle' => '商品不支持该付款周期']);
                }

                $configuration = $cartItem->configuration;
                $hasConfiguration = is_array($configuration)
                    ? $configuration !== []
                    : $configuration !== null;
                $mapping = $mappings[$this->supplierMappingKey(
                    (int) $product->id,
                    (string) $cartItem->billing_cycle,
                )] ?? null;
                if ($hasConfiguration && $mapping !== null) {
                    throw ValidationException::withMessages([
                        'configoption' => '当前上游映射商品暂不支持客户自定义配置',
                    ]);
                }
                $configuration = is_array($configuration) ? $configuration : [];

                $quantity = (int) $cartItem->quantity;
                if ($quantity < 1 || $quantity > 100) {
                    throw ValidationException::withMessages(['quantity' => '商品数量必须在 1 到 100 之间']);
                }

                if ($product->stock_control) {
                    $reservations[$product->id] = ($reservations[$product->id] ?? 0) + $quantity;
                }

                $unitMinor = Money::toMinor($price->price);
                $setupMinor = Money::toMinor($price->setup_fee);
                $unitTotalMinor = $unitMinor + $setupMinor;
                if ($unitTotalMinor > intdiv(self::MAX_DATABASE_AMOUNT_MINOR, $quantity)) {
                    throw ValidationException::withMessages(['total' => '订单总额超出系统支持范围']);
                }

                $amountMinor = $unitTotalMinor * $quantity;
                if ($subtotalMinor > self::MAX_DATABASE_AMOUNT_MINOR - $amountMinor) {
                    throw ValidationException::withMessages(['total' => '订单总额超出系统支持范围']);
                }

                $subtotalMinor += $amountMinor;
                $lines[] = [
                    'product' => $product,
                    'billingCycle' => $cartItem->billing_cycle,
                    'quantity' => $quantity,
                    'reservedQuantity' => $product->stock_control ? $quantity : 0,
                    'unitMinor' => $unitMinor,
                    'setupMinor' => $setupMinor,
                    'amountMinor' => $amountMinor,
                    'configuration' => $configuration,
                    'mapping' => $mapping,
                ];
            }

            foreach ($reservations as $productId => $quantity) {
                $product = $products->get($productId);
                if ($product->quantity === null || $product->quantity < $quantity) {
                    throw ValidationException::withMessages(['quantity' => "{$product->name} 库存不足"]);
                }

                $product->quantity -= $quantity;
                $product->save();
            }

            $order = Order::create([
                'user_id' => $lockedUser->id,
                'status' => 'Pending',
                'subtotal' => Money::format($subtotalMinor),
                'total' => Money::format($subtotalMinor),
                'currency' => $currency,
                'idempotency_key' => $idempotencyKey,
                'notes' => $fingerprint === null ? null : "idempotency:$fingerprint",
            ]);

            $invoice = Invoice::create([
                'user_id' => $lockedUser->id,
                'order_id' => $order->id,
                'number' => $this->invoiceNumber(),
                'status' => 'Unpaid',
                'subtotal' => Money::format($subtotalMinor),
                'total' => Money::format($subtotalMinor),
                'currency' => $currency,
                'due_at' => now()->addDay(),
                'payment_method' => $gateway,
            ]);

            foreach ($lines as $line) {
                $orderItem = $order->items()->create([
                    'product_id' => $line['product']->id,
                    'product_name' => $line['product']->name,
                    'billing_cycle' => $line['billingCycle'],
                    'quantity' => $line['quantity'],
                    'reserved_quantity' => $line['reservedQuantity'],
                    'unit_price' => Money::format($line['unitMinor']),
                    'setup_fee' => Money::format($line['setupMinor']),
                    'amount' => Money::format($line['amountMinor']),
                    'configuration' => $line['configuration'],
                ]);
                if ($line['mapping'] !== null) {
                    try {
                        $this->supplierOutbox->freezeRoute(
                            $orderItem,
                            $line['mapping'],
                            $currency,
                        );
                    } catch (DomainException $exception) {
                        throw ValidationException::withMessages([
                            'product_id' => $exception->getMessage(),
                        ]);
                    }
                }

                $unitAmountMinor = $line['unitMinor'] + $line['setupMinor'];
                for ($index = 0; $index < $line['quantity']; $index++) {
                    $invoice->items()->create([
                        'order_item_id' => $orderItem->id,
                        'unit_index' => $index,
                        'type' => 'host',
                        'billing_cycle' => $line['billingCycle'],
                        'description' => "{$line['product']->name} - {$line['billingCycle']}",
                        'amount' => Money::format($unitAmountMinor),
                    ]);
                }
            }

            CartItem::query()
                ->where('user_id', $lockedUser->id)
                ->whereIn('id', $selectedItems->pluck('id'))
                ->delete();

            if ($subtotalMinor === 0) {
                $this->settleLockedInvoice($invoice, $lockedUser, 'Free');
            }

            return $invoice->fresh(['items', 'order.items.product']);
        }, 3);
    }

    public function payWithCredit(User $user, Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($user, $invoice) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->ensureActiveUser($lockedUser);
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($lockedInvoice->user_id !== $lockedUser->id) {
                throw ValidationException::withMessages(['invoice' => '账单不存在']);
            }

            if ($lockedInvoice->status === 'Paid') {
                return $lockedInvoice->fresh(['items', 'transactions']);
            }

            if ($lockedInvoice->status !== 'Unpaid') {
                throw ValidationException::withMessages(['invoice' => '当前账单状态不可支付']);
            }

            $lockedInvoice->load('items');
            if ($lockedInvoice->items->contains('type', 'recharge')) {
                throw ValidationException::withMessages(['invoice' => '充值账单不能使用余额支付']);
            }
            $this->ensureRenewalPaymentAvailable($lockedInvoice, $lockedUser);

            $amountMinor = Money::toMinor($lockedInvoice->total);
            $balanceMinor = Money::toMinor($lockedUser->credit);
            if ($balanceMinor < $amountMinor) {
                throw ValidationException::withMessages(['credit' => '账户余额不足']);
            }

            $lockedUser->credit = Money::format($balanceMinor - $amountMinor);
            $lockedUser->save();

            $lockedInvoice->credit = Money::format($amountMinor);
            $lockedInvoice->save();

            $this->createTransaction(
                $lockedUser,
                $lockedInvoice,
                'payment',
                'Credit',
                0,
                $amountMinor,
                $balanceMinor,
                $balanceMinor - $amountMinor,
            );

            return $this->settleLockedInvoice($lockedInvoice, $lockedUser, 'Credit');
        }, 3);
    }

    public function prepareGatewayPayment(User $user, Invoice $invoice, string $gateway): Invoice
    {
        return DB::transaction(function () use ($user, $invoice, $gateway) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->ensureActiveUser($lockedUser);
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($lockedInvoice->user_id !== $lockedUser->id) {
                throw ValidationException::withMessages(['invoice' => '账单不存在']);
            }
            if ($lockedInvoice->status === 'Paid') {
                return $lockedInvoice;
            }
            if ($lockedInvoice->status !== 'Unpaid') {
                throw ValidationException::withMessages(['invoice' => '当前账单状态不可支付']);
            }

            $lockedInvoice->load('items');
            $this->ensureRenewalPaymentAvailable($lockedInvoice, $lockedUser);

            $lockedInvoice->payment_method = $gateway;
            $lockedInvoice->save();

            return $lockedInvoice->fresh();
        }, 3);
    }

    /**
     * Records a payment that has already been verified by an administrator or gateway adapter.
     */
    public function recordPayment(
        Invoice $invoice,
        string $gateway,
        ?string $transactionNumber = null,
        ?bool &$changed = null,
    ): Invoice {
        $changed = false;
        $gateway = trim($gateway);
        if ($gateway === '' || mb_strlen($gateway) > 64) {
            throw ValidationException::withMessages([
                'gateway' => '支付渠道名称不得超过 64 个字符',
            ]);
        }

        $transactionNumber = trim((string) $transactionNumber) ?: null;
        if ($transactionNumber !== null && mb_strlen($transactionNumber) > 191) {
            throw ValidationException::withMessages([
                'transaction_number' => '外部流水号不得超过 191 个字符',
            ]);
        }

        try {
            return DB::transaction(function () use ($invoice, $gateway, $transactionNumber, &$changed) {
                $user = User::query()->lockForUpdate()->findOrFail($invoice->user_id);
                $lockedInvoice = Invoice::query()->lockForUpdate()->with('items')->findOrFail($invoice->id);
                if ($lockedInvoice->user_id !== $user->id) {
                    throw ValidationException::withMessages(['invoice' => '账单所属客户不一致']);
                }

                if ($transactionNumber !== null) {
                    $existingTransaction = Transaction::query()
                        ->where('transaction_number', $transactionNumber)
                        ->first();
                    if ($existingTransaction && $existingTransaction->invoice_id !== $lockedInvoice->id) {
                        throw ValidationException::withMessages([
                            'transaction_number' => '该外部流水号已用于其他账单',
                        ]);
                    }
                }

                if ($lockedInvoice->status === 'Paid') {
                    if ($transactionNumber !== null && ! Transaction::query()
                        ->where('invoice_id', $lockedInvoice->id)
                        ->where('transaction_number', $transactionNumber)
                        ->exists()) {
                        throw ValidationException::withMessages([
                            'transaction_number' => '账单已由其他流水完成支付',
                        ]);
                    }

                    return $lockedInvoice->fresh(['items', 'transactions']);
                }

                if ($lockedInvoice->status !== 'Unpaid') {
                    throw ValidationException::withMessages(['invoice' => '当前账单状态不可入账']);
                }
                $this->ensureRenewalPaymentAvailable($lockedInvoice, $user);

                $amountMinor = Money::toMinor($lockedInvoice->total);
                $balanceBefore = Money::toMinor($user->credit);
                $rechargeItems = $lockedInvoice->items->where('type', 'recharge');
                $isRecharge = $rechargeItems->isNotEmpty();

                if ($isRecharge && ($lockedInvoice->items->count() !== 1 || $rechargeItems->count() !== 1)) {
                    throw ValidationException::withMessages(['invoice' => '充值账单项目不完整']);
                }
                if ($isRecharge && Money::toMinor($rechargeItems->first()->amount) !== $amountMinor) {
                    throw ValidationException::withMessages(['invoice' => '充值账单金额不一致']);
                }

                if ($isRecharge && $lockedInvoice->payment_method !== null
                    && strcasecmp($lockedInvoice->payment_method, $gateway) !== 0) {
                    throw ValidationException::withMessages(['gateway' => '入账渠道与充值账单不一致']);
                }

                $balanceAfter = $isRecharge ? $balanceBefore + $amountMinor : $balanceBefore;
                $maximumBalance = Money::toMinor((string) config('kjaiu.funds.maximum_balance'));
                if ($isRecharge && $balanceAfter > $maximumBalance) {
                    throw ValidationException::withMessages(['amount' => '充值后余额超过系统上限']);
                }

                if ($isRecharge) {
                    $user->credit = Money::format($balanceAfter);
                    $user->save();
                }

                $this->createTransaction(
                    $user,
                    $lockedInvoice,
                    $isRecharge ? 'recharge' : 'payment',
                    $gateway,
                    $amountMinor,
                    0,
                    $balanceBefore,
                    $balanceAfter,
                    $transactionNumber,
                );

                $settled = $this->settleLockedInvoice($lockedInvoice, $user, $gateway);
                $changed = true;

                return $settled;
            }, 3);
        } catch (QueryException $exception) {
            if ($transactionNumber !== null
                && Transaction::query()->where('transaction_number', $transactionNumber)->exists()) {
                throw ValidationException::withMessages([
                    'transaction_number' => '该外部流水号已被使用',
                ]);
            }

            throw $exception;
        }
    }

    public function cancelInvoice(Invoice $invoice, ?bool &$changed = null): Invoice
    {
        $changed = false;

        return DB::transaction(function () use ($invoice, &$changed) {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($lockedInvoice->status === 'Cancelled') {
                return $lockedInvoice->fresh();
            }

            if ($lockedInvoice->status !== 'Unpaid') {
                throw ValidationException::withMessages(['invoice' => '只有未支付账单可以取消']);
            }

            if ($lockedInvoice->order_id !== null) {
                $orderItems = OrderItem::query()
                    ->where('order_id', $lockedInvoice->order_id)
                    ->orderBy('product_id')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $reservations = $orderItems
                    ->whereNotNull('product_id')
                    ->where('reserved_quantity', '>', 0)
                    ->groupBy('product_id')
                    ->map(fn ($items) => $items->sum('reserved_quantity'));
                $products = Product::query()
                    ->whereIn('id', $reservations->keys())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                foreach ($reservations as $productId => $quantity) {
                    $product = $products->get($productId);
                    if ($product) {
                        $currentQuantity = (int) ($product->quantity ?? 0);
                        if ($quantity > Product::MAX_STOCK - $currentQuantity) {
                            throw ValidationException::withMessages([
                                'quantity' => "{$product->name} 的库存超出系统支持范围",
                            ]);
                        }

                        $product->quantity = $currentQuantity + $quantity;
                        $product->save();
                    }
                }

                foreach ($orderItems as $orderItem) {
                    if ($orderItem->reserved_quantity > 0) {
                        $orderItem->reserved_quantity = 0;
                        $orderItem->save();
                    }
                }

                Order::query()->whereKey($lockedInvoice->order_id)->update(['status' => 'Cancelled']);
            }

            $lockedInvoice->status = 'Cancelled';
            $lockedInvoice->renewal_key = null;
            $lockedInvoice->save();
            $changed = true;

            return $lockedInvoice->fresh();
        }, 3);
    }

    public function createRechargeInvoice(
        User $user,
        string|int|float $amount,
        string $gateway,
        ?string $idempotencyKey = null,
    ): Invoice {
        $amountMinor = Money::toMinor($amount);
        $minimum = Money::toMinor((string) config('kjaiu.funds.minimum'));
        $maximum = Money::toMinor((string) config('kjaiu.funds.maximum'));
        if ($amountMinor < $minimum || $amountMinor > $maximum) {
            throw ValidationException::withMessages(['amount' => '充值金额超出允许范围']);
        }
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);
        if ($idempotencyKey !== null) {
            $idempotencyKey = hash('sha256', "recharge\0$idempotencyKey");
        }

        return DB::transaction(function () use ($user, $amountMinor, $gateway, $idempotencyKey) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->ensureActiveUser($lockedUser);

            if ($idempotencyKey !== null) {
                $existing = Invoice::query()
                    ->where('user_id', $lockedUser->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->with('items')
                    ->first();
                if ($existing) {
                    if (Money::toMinor($existing->total) !== $amountMinor
                        || strcasecmp((string) $existing->payment_method, $gateway) !== 0
                        || ! $existing->items->contains('type', 'recharge')) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => '该幂等键已用于不同的充值请求',
                        ]);
                    }

                    return $existing;
                }
            }

            $balanceAfter = Money::toMinor($lockedUser->credit) + $amountMinor;
            if ($balanceAfter > Money::toMinor((string) config('kjaiu.funds.maximum_balance'))) {
                throw ValidationException::withMessages(['amount' => '充值后余额超过系统上限']);
            }

            $invoice = Invoice::create([
                'user_id' => $lockedUser->id,
                'number' => $this->invoiceNumber(),
                'idempotency_key' => $idempotencyKey,
                'status' => 'Unpaid',
                'subtotal' => Money::format($amountMinor),
                'total' => Money::format($amountMinor),
                'currency' => (string) config('kjaiu.currency.code', 'CNY'),
                'due_at' => now()->addHour(),
                'payment_method' => $gateway,
            ]);

            $invoice->items()->create([
                'type' => 'recharge',
                'description' => '账户余额充值',
                'amount' => Money::format($amountMinor),
            ]);

            return $invoice->fresh('items');
        }, 3);
    }

    public function createRenewalInvoice(User $user, Service $service, string $billingCycle): Invoice
    {
        return DB::transaction(function () use ($user, $service, $billingCycle) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->ensureActiveUser($lockedUser);
            $lockedService = Service::query()->lockForUpdate()->findOrFail($service->id);

            return $this->createRenewalInvoiceForLockedService(
                $lockedUser,
                $lockedService,
                $billingCycle,
            );
        }, 3);
    }

    public function autoRenewDueService(Service $service): Invoice
    {
        return DB::transaction(function () use ($service) {
            $lockedUser = User::query()->lockForUpdate()->find($service->user_id);
            if (! $lockedUser) {
                throw ValidationException::withMessages(['service' => '服务不符合自动续费条件']);
            }
            $this->ensureActiveUser($lockedUser);

            $lockedService = Service::query()->lockForUpdate()->find($service->id);
            if (! $lockedService
                || $lockedService->user_id !== $lockedUser->id
                || ! $lockedService->auto_renew
                || $lockedService->status !== 'Active'
                || ! $lockedService->next_due_at
                || $lockedService->next_due_at->isAfter(now())) {
                throw ValidationException::withMessages(['service' => '服务不符合自动续费条件']);
            }

            $invoice = $this->createRenewalInvoiceForLockedService(
                $lockedUser,
                $lockedService,
                (string) $lockedService->billing_cycle,
                fn (int $amountMinor) => $this->sufficientCreditBalance(
                    $lockedUser,
                    $amountMinor,
                ),
            );
            if ($invoice->status === 'Paid') {
                return $invoice->fresh(['items', 'transactions']);
            }

            $lockedInvoice = Invoice::query()
                ->with('items')
                ->lockForUpdate()
                ->find($invoice->id);
            if (! $lockedInvoice || $lockedInvoice->user_id !== $lockedUser->id) {
                throw ValidationException::withMessages(['invoice' => '账单不存在']);
            }
            if ($lockedInvoice->status !== 'Unpaid') {
                throw ValidationException::withMessages(['invoice' => '当前账单状态不可支付']);
            }

            $this->ensureRenewalPaymentAvailable($lockedInvoice, $lockedUser);

            $amountMinor = Money::toMinor($lockedInvoice->total);
            $balanceMinor = $this->sufficientCreditBalance($lockedUser, $amountMinor);

            $lockedUser->credit = Money::format($balanceMinor - $amountMinor);
            $lockedUser->save();

            $lockedInvoice->credit = Money::format($amountMinor);
            $lockedInvoice->save();

            $this->createTransaction(
                $lockedUser,
                $lockedInvoice,
                'payment',
                'Credit',
                0,
                $amountMinor,
                $balanceMinor,
                $balanceMinor - $amountMinor,
            );

            return $this->settleLockedInvoice($lockedInvoice, $lockedUser, 'Credit');
        }, 3);
    }

    private function createRenewalInvoiceForLockedService(
        User $user,
        Service $service,
        string $billingCycle,
        ?callable $beforeCreate = null,
    ): Invoice {
        if ($service->user_id !== $user->id
            || ! in_array($service->status, ['Active', 'Suspended'], true)) {
            throw ValidationException::withMessages(['service' => '产品不可续费']);
        }

        $price = $this->renewalPriceForLockedService($service, $billingCycle);
        $this->ensureLocalRenewalAvailable($service, $billingCycle);

        if ($this->nextDueAt(now(), $billingCycle, $service->billing_anchor_day) === null) {
            throw ValidationException::withMessages(['billing_cycle' => '该付款周期不可续费']);
        }

        $renewalDueAt = $service->next_due_at?->copy();
        $renewalKey = hash('sha256', implode('|', [
            (string) $service->id,
            $renewalDueAt?->copy()->utc()->format('Y-m-d H:i:s') ?? 'none',
        ]));
        $existing = Invoice::query()
            ->where('renewal_key', $renewalKey)
            ->with('items')
            ->lockForUpdate()
            ->first();
        if ($existing) {
            if ($existing->items->firstWhere('type', 'renew')?->billing_cycle !== $billingCycle) {
                throw ValidationException::withMessages([
                    'billing_cycle' => '当前到期周期已有其他续费账单，请先取消原账单',
                ]);
            }

            return $existing;
        }

        $amountMinor = Money::toMinor($price->price);
        $beforeCreate?->__invoke($amountMinor);
        $invoice = Invoice::create([
            'user_id' => $user->id,
            'number' => $this->invoiceNumber(),
            'renewal_key' => $renewalKey,
            'renewal_due_at' => $renewalDueAt,
            'status' => 'Unpaid',
            'subtotal' => Money::format($amountMinor),
            'total' => Money::format($amountMinor),
            'currency' => (string) config('kjaiu.currency.code', 'CNY'),
            'due_at' => now()->addDay(),
        ]);

        $invoice->items()->create([
            'service_id' => $service->id,
            'type' => 'renew',
            'rel_id' => $service->id,
            'billing_cycle' => $billingCycle,
            'description' => "{$service->name} - 续费 {$billingCycle}",
            'amount' => Money::format($amountMinor),
        ]);

        return $invoice->fresh('items');
    }

    private function renewalPriceForLockedService(Service $service, string $billingCycle): ProductPrice
    {
        $product = Product::query()->lockForUpdate()->find($service->product_id);
        if (! $product || ! $product->is_active) {
            throw ValidationException::withMessages([
                'service' => '当前产品不存在或已停用，无法续费',
            ]);
        }

        if ($product->billing_cycle === $billingCycle) {
            return new ProductPrice([
                'billing_cycle' => $product->billing_cycle,
                'price' => $product->price,
                'setup_fee' => $product->setup_fee,
                'is_active' => true,
            ]);
        }

        $price = ProductPrice::query()
            ->where('product_id', $product->id)
            ->where('billing_cycle', $billingCycle)
            ->lockForUpdate()
            ->first();
        if (! $price || ! $price->is_active) {
            throw ValidationException::withMessages(['billing_cycle' => '产品不支持该续费周期']);
        }

        return $price;
    }

    private function sufficientCreditBalance(User $user, int $amountMinor): int
    {
        $balanceMinor = Money::toMinor($user->credit);
        if ($balanceMinor < $amountMinor) {
            throw ValidationException::withMessages(['credit' => '账户余额不足']);
        }

        return $balanceMinor;
    }

    private function settleLockedInvoice(Invoice $invoice, User $user, string $gateway): Invoice
    {
        $invoice->loadMissing(['items', 'order.items.product', 'order.items.supplierRoute']);
        $now = now();

        if ($invoice->order) {
            $invoice->order->update(['status' => 'Paid']);
            foreach ($invoice->order->items->sortBy('id') as $orderItem) {
                $route = $orderItem->supplierRoute;
                $configuration = $orderItem->configuration;
                $hasConfiguration = is_array($configuration)
                    ? $configuration !== []
                    : $configuration !== null;
                if ($route !== null && $hasConfiguration) {
                    throw ValidationException::withMessages([
                        'configoption' => '当前上游映射商品暂不支持客户自定义配置',
                    ]);
                }
                for ($index = 0; $index < $orderItem->quantity; $index++) {
                    $active = $route === null && (bool) $orderItem->product?->auto_setup;
                    $perServiceMinor = Money::toMinor($orderItem->unit_price)
                        + Money::toMinor($orderItem->setup_fee);
                    $serviceNumber = $this->serviceNumber();
                    $service = Service::query()->firstOrCreate(
                        ['order_item_id' => $orderItem->id, 'unit_index' => $index],
                        [
                            'user_id' => $user->id,
                            'order_id' => $invoice->order_id,
                            'product_id' => $orderItem->product_id,
                            'name' => mb_substr((string) $orderItem->product_name, 0, 244).' - '.$serviceNumber,
                            'type' => $orderItem->product?->type ?? 'server',
                            'status' => $active ? 'Active' : 'Pending',
                            'first_payment_amount' => Money::format($perServiceMinor),
                            'renew_amount' => $orderItem->unit_price,
                            'billing_cycle' => $orderItem->billing_cycle,
                            'billing_anchor_day' => $route === null ? $now->day : null,
                            'registered_at' => $now,
                            'activated_at' => $active ? $now : null,
                            'next_due_at' => $route === null
                                ? $this->nextDueAt($now, $orderItem->billing_cycle, $now->day)
                                : null,
                        ],
                    );

                    $invoice->items()
                        ->where('order_item_id', $orderItem->id)
                        ->where('unit_index', $index)
                        ->update(['service_id' => $service->id, 'rel_id' => $service->id]);

                    if ($route !== null) {
                        $this->supplierOutbox->queueProvision($invoice, $orderItem, $service, $route);
                    }
                }
            }
        }

        $renewalItems = $invoice->items->where('type', 'renew')->sortBy('service_id');
        foreach ($renewalItems as $item) {
            $service = Service::query()->lockForUpdate()->find($item->service_id ?: $item->rel_id);
            if (! $service || $service->user_id !== $user->id
                || ! in_array($service->status, ['Active', 'Suspended'], true)) {
                throw ValidationException::withMessages(['service' => '关联服务当前不可续费']);
            }

            if (! $this->sameMoment($service->next_due_at, $invoice->renewal_due_at)) {
                throw ValidationException::withMessages(['invoice' => '该续费账单已失效，请重新创建']);
            }

            $cycle = (string) ($item->billing_cycle ?: $service->billing_cycle);
            $this->ensureLocalRenewalAvailable($service, $cycle);
            $base = $service->next_due_at && $service->next_due_at->isFuture()
                ? $service->next_due_at
                : $now;
            $nextDueAt = $this->nextDueAt($base, $cycle, $service->billing_anchor_day);
            if ($nextDueAt === null) {
                throw ValidationException::withMessages(['billing_cycle' => '该付款周期不可续费']);
            }

            $service->update([
                'status' => 'Active',
                'billing_cycle' => $cycle,
                'billing_anchor_day' => $service->billing_anchor_day ?: $base->day,
                'renew_amount' => $item->amount,
                'next_due_at' => $nextDueAt,
            ]);
        }

        $invoice->status = 'Paid';
        $invoice->paid_at = $now;
        $invoice->payment_method = $gateway;
        $invoice->save();

        return $invoice->fresh(['items', 'transactions']);
    }

    private function createTransaction(
        User $user,
        Invoice $invoice,
        string $type,
        string $gateway,
        int $amountIn,
        int $amountOut,
        int $balanceBefore,
        int $balanceAfter,
        ?string $number = null,
    ): Transaction {
        return Transaction::create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'transaction_number' => $number ?: $this->transactionNumber(),
            'type' => $type,
            'gateway' => $gateway,
            'amount_in' => Money::format($amountIn),
            'amount_out' => Money::format($amountOut),
            'balance_before' => Money::format($balanceBefore),
            'balance_after' => Money::format($balanceAfter),
            'currency' => $invoice->currency,
            'paid_at' => now(),
        ]);
    }

    private function selectCartItems($cartItems, ?array $positions)
    {
        if ($cartItems->isEmpty()) {
            throw ValidationException::withMessages(['items' => '购物车不能为空']);
        }

        if ($positions === null) {
            return $cartItems;
        }

        $selected = collect();
        foreach ($positions as $position) {
            if (! $cartItems->has($position)) {
                throw ValidationException::withMessages(['position' => '购物车商品不存在']);
            }
            $selected->push($cartItems->get($position));
        }

        if ($selected->isEmpty()) {
            throw ValidationException::withMessages(['position' => '请选择要结算的商品']);
        }

        return $selected;
    }

    private function normalizePositions(?array $positions): ?array
    {
        if ($positions === null) {
            return null;
        }

        $normalized = [];
        foreach ($positions as $position) {
            if ((! is_int($position) && ! ctype_digit((string) $position)) || (int) $position < 0) {
                throw ValidationException::withMessages(['position' => '购物车位置无效']);
            }
            $normalized[] = (int) $position;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    private function normalizeIdempotencyKey(?string $key): ?string
    {
        $key = trim((string) $key);
        if ($key === '') {
            return null;
        }
        if (strlen($key) > 64) {
            throw ValidationException::withMessages(['idempotency_key' => '幂等键不能超过 64 个字符']);
        }

        return $key;
    }

    private function ensureActiveUser(User $user): void
    {
        if ($user->status !== 'Active') {
            throw ValidationException::withMessages(['user' => '账户已被停用']);
        }
    }

    private function nextDueAt(Carbon $from, string $cycle, ?int $anchorDay = null): ?Carbon
    {
        return match ($cycle) {
            'hourly' => $from->copy()->addHour(),
            'daily' => $from->copy()->addDay(),
            'weekly' => $from->copy()->addWeek(),
            'monthly' => $this->addAnchoredMonths($from, 1, $anchorDay),
            'quarterly' => $this->addAnchoredMonths($from, 3, $anchorDay),
            'semiannually' => $this->addAnchoredMonths($from, 6, $anchorDay),
            'annually', 'yearly' => $this->addAnchoredMonths($from, 12, $anchorDay),
            'biennially' => $this->addAnchoredMonths($from, 24, $anchorDay),
            'triennially' => $this->addAnchoredMonths($from, 36, $anchorDay),
            default => null,
        };
    }

    private function addAnchoredMonths(Carbon $from, int $months, ?int $anchorDay): Carbon
    {
        $date = $from->copy()->addMonthsNoOverflow($months);
        $day = min($anchorDay ?: $from->day, $date->daysInMonth);

        return $date->setDate($date->year, $date->month, $day);
    }

    private function sameMoment(?Carbon $first, ?Carbon $second): bool
    {
        if ($first === null || $second === null) {
            return $first === null && $second === null;
        }

        return $first->getTimestamp() === $second->getTimestamp();
    }

    private function ensureLocalRenewalAvailable(Service $service, string $billingCycle): void
    {
        try {
            $this->supplierOutbox->ensureLocalRenewalAvailable($service, $billingCycle);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['service' => $exception->getMessage()]);
        }
    }

    private function ensureRenewalPaymentAvailable(Invoice $invoice, User $user): void
    {
        foreach ($invoice->items->where('type', 'renew')->sortBy('service_id') as $item) {
            $service = Service::query()->lockForUpdate()->find($item->service_id ?: $item->rel_id);
            if (! $service || $service->user_id !== $user->id
                || ! in_array($service->status, ['Active', 'Suspended'], true)) {
                throw ValidationException::withMessages(['service' => '关联服务当前不可续费']);
            }

            $cycle = (string) ($item->billing_cycle ?: $service->billing_cycle);
            $this->ensureLocalRenewalAvailable($service, $cycle);
        }
    }

    private function lockCheckoutEligibility($products): void
    {
        $groupIds = $products->pluck('product_group_id')->unique()->sort()->values();
        $groupParents = ProductGroup::query()
            ->whereIn('id', $groupIds)
            ->orderBy('id')
            ->get(['id', 'parent_id'])
            ->keyBy('id');
        $allGroupIds = $groupIds
            ->merge($groupParents->pluck('parent_id')->filter())
            ->unique()
            ->sort()
            ->values();
        $groups = ProductGroup::query()
            ->whereIn('id', $allGroupIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($products as $product) {
            $group = $groups->get($product->product_group_id);
            $discoveredGroup = $groupParents->get($product->product_group_id);
            if ($group === null
                || $discoveredGroup === null
                || (string) $group->parent_id !== (string) $discoveredGroup->parent_id) {
                throw ValidationException::withMessages([
                    'product_id' => '商品分组在结算期间发生变化，请重试',
                ]);
            }
            $parent = $group?->parent_id === null ? null : $groups->get($group->parent_id);
            $product->setRelation('group', $group);
            $group?->setRelation('parent', $parent);
        }
    }

    private function supplierMappingKey(int $productId, string $billingCycle): string
    {
        return $productId."\0".$billingCycle;
    }

    private function invoiceNumber(): string
    {
        return 'KJ'.now()->format('Ymd').strtoupper((string) Str::ulid());
    }

    private function transactionNumber(): string
    {
        return strtoupper((string) Str::ulid());
    }

    private function serviceNumber(): string
    {
        return strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
    }
}
