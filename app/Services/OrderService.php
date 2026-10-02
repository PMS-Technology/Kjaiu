<?php

namespace App\Services;

use App\Models\CartSession;
use App\Models\Client;
use App\Models\Host;
use App\Models\Invoice;
use App\Models\ModuleQueue;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use Illuminate\Support\Facades\DB;

/**
 * Order placement: turns a cart into an order, an invoice and (when the
 * product is configured for it) a queued provisioning task.
 *
 * The original platform's `auto_setup` values are honoured:
 *   ''        manual activation
 *   'order'   activate as soon as the order is placed
 *   'payment' activate when the first payment is received
 *   'on'      activate after manual approval
 */
class OrderService
{
    public function __construct(
        protected CartService $cart = new CartService(),
        protected InvoiceService $invoices = new InvoiceService(),
        protected PricingService $pricing = new PricingService(),
    ) {
    }

    /**
     * Place an order from the current cart.
     *
     * @param  array{promo?:string, notes?:string, gateway?:string}  $options
     * @return array{order:Order, invoice:Invoice}
     */
    public function place(Client $client, CartSession $cart, array $options = []): array
    {
        $items = $this->cart->items($cart);

        if ($items === []) {
            throw new \InvalidArgumentException('购物车为空');
        }

        $promo = null;

        if (! empty($options['promo'])) {
            $promo = $this->cart->findPromo((string) $options['promo']);

            if ($promo === null) {
                throw new \InvalidArgumentException('优惠码无效');
            }
        }

        $group = $client->group;
        $subtotal = $this->cart->subtotal($cart);

        return DB::transaction(function () use ($client, $cart, $items, $promo, $group, $subtotal, $options) {
            $discount = 0.0;

            if ($promo !== null) {
                $discount = $this->pricing->applyPromo($subtotal, $promo);
            }

            $net = max(0, $subtotal - $discount);
            $net = $this->pricing->groupDiscount($net, $group?->discount_percent);

            // Line amounts are scaled proportionally when a discount applies so
            // the invoice items still sum to the charged total.
            $ratio = $subtotal > 0 ? ($net / $subtotal) : 1.0;

            $invoiceItems = [];

            foreach ($items as $item) {
                $product = Product::query()->find($item['productid'] ?? null);

                $invoiceItems[] = [
                    'type' => 'hosting',
                    'rel_id' => $product?->id ?? 0,
                    'description' => $this->describeItem($item),
                    'amount' => PricingService::money((float) $item['amount'] * $ratio),
                    'taxed' => (int) ($product?->tax ?? 0),
                ];
            }

            $order = Order::create([
                'uid' => $client->id,
                'ordernum' => $this->nextOrderNumber(),
                'status' => Order::STATUS_UNPAID,
                'create_time' => time(),
                'update_time' => time(),
                'amount' => PricingService::money($net),
                'payment' => (string) ($options['gateway'] ?? ''),
                'promo_code' => $promo?->code ?? '',
                'promo_type' => $promo?->type ?? '',
                'promo_value' => $discount,
                'notes' => (string) ($options['notes'] ?? ''),
            ]);

            $invoice = $this->invoices->create($client, $invoiceItems, time() + 86400 * 7, 'hosting', [
                'is_cron' => 0,
            ]);

            $order->invoiceid = $invoice->id;
            $order->save();

            // Create the pending services and queue provisioning where the
            // product asks for immediate activation.
            foreach ($items as $item) {
                $product = Product::query()->find($item['productid'] ?? null);

                if ($product === null) {
                    continue;
                }

                $host = $this->createPendingHost($client, $product, $item, (int) $order->id);

                if ((string) $product->auto_setup === 'order') {
                    $this->queueProvisioning($host, 'CreateAccount');
                }
            }

            if ($promo !== null) {
                $promo->used = (int) $promo->used + 1;
                $promo->save();
            }

            $this->cart->clear($cart);

            return ['order' => $order->fresh(), 'invoice' => $invoice->fresh()];
        });
    }

    /**
     * Create the Pending service row that an order implies.
     */
    protected function createPendingHost(Client $client, Product $product, array $item, int $orderId): Host
    {
        $cycle = (string) ($item['cycle'] ?? 'monthly');
        $nextDue = $this->pricing->nextDueDate($cycle);

        return Host::create([
            'uid' => $client->id,
            'orderid' => $orderId,
            'productid' => $product->id,
            'serverid' => 0,
            'domain' => (string) ($item['host'] ?? ''),
            'payment' => (string) ($item['cycle'] ?? ''),
            'billingcycle' => $cycle,
            'firstpaymentamount' => (float) ($item['amount'] ?? 0),
            'amount' => (float) ($item['amount'] ?? 0),
            'regdate' => time(),
            'nextduedate' => $nextDue ?? 0,
            'nextinvoicedate' => $nextDue ?? 0,
            'domainstatus' => Host::STATUS_PENDING,
            'username' => (string) ($item['host'] ?? ''),
            'password' => (string) ($item['password'] ?? ''),
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    /**
     * Queue a module action for asynchronous execution by the cron runner.
     */
    public function queueProvisioning(Host $host, string $action): ModuleQueue
    {
        $product = $host->product;

        return ModuleQueue::create([
            'service_type' => ModuleQueue::SERVICE_HOST,
            'service_id' => $host->id,
            'module_name' => (string) ($product?->server_type ?? ''),
            'module_action' => $action,
            'last_attempt' => 0,
            'last_attempt_error' => '',
            'num_retries' => 0,
            'completed' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    /**
     * Human-readable invoice line for a cart item.
     */
    protected function describeItem(array $item): string
    {
        $name = (string) ($item['name'] ?? 'Product');
        $cycle = (string) ($item['cycle'] ?? 'monthly');
        $qty = (int) ($item['qty'] ?? 1);

        $suffix = $qty > 1 ? " x{$qty}" : '';

        return "{$name} - {$cycle}{$suffix}";
    }

    /**
     * Order number in the original's "YYYYMMDDnnnn" shape.
     */
    public function nextOrderNumber(): string
    {
        $prefix = date('Ymd');
        $sequence = Order::query()->where('ordernum', 'like', $prefix . '%')->count() + 1;

        return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
