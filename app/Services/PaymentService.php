<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Client;
use App\Models\Configuration;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request as RequestFacade;

/**
 * Payment dispatch for invoices and balance top-ups.
 *
 * Gateways are rows in `shd_payment_gateways`; each row names a driver under
 * `public/plugins/gateways/<gateway>/<gateway>.php` (the original platform's
 * plugin layout) exposing `pay($params)`, `callback($params)` and
 * `refund($params)`. Drivers are loaded lazily so an installation without a
 * given plugin keeps working.
 *
 * The built-in `BankTransfer` gateway is implemented here in full: it needs no
 * third party, returns the site's payment instructions and leaves the invoice
 * unpaid until an administrator confirms the transfer.
 */
class PaymentService
{
    /** Gateway that never leaves the platform. */
    public const OFFLINE = 'BankTransfer';

    /** Payment row status values used by `shd_pay_log`. */
    public const PAY_PENDING = 'pending';
    public const PAY_SUCCESS = 'success';
    public const PAY_FAILED = 'failed';

    /** @var array<string, bool> driver files already required */
    protected static array $loaded = [];

    public function __construct(
        protected InvoiceService $invoices = new InvoiceService(),
    ) {
    }

    /**
     * Enabled gateways, in the order the admin panel set.
     *
     * @return array<int, array{id:int, name:string, title:string, status:int, module:string, url:string, author_url:string}>
     */
    public function gateways(): array
    {
        return PaymentGateway::query()
            ->orderBy('order')
            ->get()
            ->map(fn (PaymentGateway $gateway) => $this->gatewayPayload($gateway))
            ->values()
            ->all();
    }

    /**
     * One gateway row, in the shape the /v1 payloads document.
     */
    public function gatewayPayload(PaymentGateway $gateway): array
    {
        $settings = $gateway->settings();

        return [
            'id' => (int) ($gateway->order ?: 0),
            'name' => (string) $gateway->gateway,
            'title' => $gateway->displayName(),
            'status' => 1,
            'module' => 'gateways',
            'url' => (string) ($settings['url'] ?? ''),
            'author_url' => (string) ($settings['author_url'] ?? ''),
        ];
    }

    public function gateway(string $name): ?PaymentGateway
    {
        return PaymentGateway::query()->where('gateway', $name)->first();
    }

    /**
     * Start a payment for an invoice.
     *
     * @return array{status:bool, msg:string, data:array}
     */
    public function pay(Invoice $invoice, Client $client, string $gateway): array
    {
        $outstanding = $this->invoices->outstanding($invoice);

        if ($outstanding <= 0) {
            return [
                'status' => false,
                'msg' => '账单已支付，无需重复支付',
                'data' => ['code' => 1001],
            ];
        }

        if ($gateway === '' || $gateway === self::OFFLINE) {
            return $this->bankTransfer($invoice, $client, $outstanding);
        }

        $row = $this->gateway($gateway);

        if ($row === null) {
            return ['status' => false, 'msg' => '支付方式不存在', 'data' => []];
        }

        $driver = $this->loadDriver($gateway);

        if ($driver === null) {
            return ['status' => false, 'msg' => '支付方式不可用：未安装对应网关插件', 'data' => []];
        }

        $logId = $this->logPay($invoice, $gateway, $outstanding, self::PAY_PENDING);

        try {
            $result = $driver('pay', $this->driverParams($row, $invoice, $client, $outstanding, $logId));
        } catch (\Throwable $e) {
            Log::error('Payment gateway threw', [
                'gateway' => $gateway,
                'invoice' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            $this->updatePayLog($logId, self::PAY_FAILED, $e->getMessage());

            return ['status' => false, 'msg' => '支付接口调用失败：' . $e->getMessage(), 'data' => []];
        }

        $normalised = $this->normalisePayResult($result);

        if (! $normalised['status']) {
            $this->updatePayLog($logId, self::PAY_FAILED, $normalised['msg']);

            return ['status' => false, 'msg' => $normalised['msg'], 'data' => ['pay_html' => $normalised['data']]];
        }

        return [
            'status' => true,
            'msg' => '请求成功',
            'data' => [
                'pay_html' => $normalised['data'],
                'trans_id' => $logId,
            ],
        ];
    }

    /**
     * Balance top-up: an invoice of type `recharge`, then a normal payment.
     *
     * @return array{status:bool, msg:string, data:array}
     */
    public function recharge(Invoice $invoice, Client $client, string $gateway): array
    {
        return $this->pay($invoice, $client, $gateway);
    }

    /**
     * The offline bank-transfer flow.
     *
     * The invoice stays Unpaid; `paymt` is set to the offline marker so the
     * client-area pay panel renders the transfer instructions instead of a
     * redirect, and an administrator confirms the transfer later.
     */
    public function bankTransfer(Invoice $invoice, Client $client, ?float $amount = null): array
    {
        $amount = $amount ?? $this->invoices->outstanding($invoice);

        if ($amount <= 0) {
            return ['status' => false, 'msg' => '账单已支付，无需重复支付', 'data' => ['code' => 1001]];
        }

        $invoice->paymt = 'offline';
        $invoice->save();

        $logId = $this->logPay($invoice, self::OFFLINE, $amount, self::PAY_PENDING);

        return [
            'status' => true,
            'msg' => '请按以下信息完成转账',
            'data' => [
                'payment' => self::OFFLINE,
                'total' => $this->money($amount),
                'total_desc' => $this->money($amount) . (string) (Currency::default()?->suffix ?? ''),
                'invoiceid' => (int) $invoice->id,
                'pay_html' => [
                    'type' => 'html',
                    'data' => $this->bankTransferHtml($invoice, $amount),
                ],
                'trans_id' => $logId,
            ],
        ];
    }

    /**
     * Handle a gateway callback.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status:bool, msg:string, invoice:?Invoice}
     */
    public function callback(string $gateway, array $payload): array
    {
        if ($gateway === self::OFFLINE) {
            return $this->confirmOffline($payload);
        }

        $driver = $this->loadDriver($gateway);

        if ($driver === null) {
            return ['status' => false, 'msg' => '支付方式不存在', 'invoice' => null];
        }

        try {
            $result = $driver('callback', $payload);
        } catch (\Throwable $e) {
            Log::error('Payment gateway callback failed', [
                'gateway' => $gateway,
                'error' => $e->getMessage(),
            ]);

            return ['status' => false, 'msg' => '回调处理失败', 'invoice' => null];
        }

        $invoiceId = (int) ($result['invoiceid'] ?? $result['invoice_id'] ?? $payload['invoiceid'] ?? 0);
        $paid = (bool) ($result['status'] ?? false);
        $invoice = $invoiceId > 0 ? Invoice::query()->find($invoiceId) : null;

        if (! $paid || $invoice === null) {
            $this->updatePayLog(
                (int) ($result['trans_id'] ?? 0),
                self::PAY_FAILED,
                (string) ($result['msg'] ?? '')
            );

            return ['status' => false, 'msg' => (string) ($result['msg'] ?? '支付未完成'), 'invoice' => $invoice];
        }

        $this->settle($invoice, $gateway, (float) ($result['amount'] ?? $this->invoices->outstanding($invoice)));

        return ['status' => true, 'msg' => '支付成功', 'invoice' => $invoice];
    }

    /**
     * Mark an invoice paid by a gateway and record the transaction.
     */
    public function settle(Invoice $invoice, string $gateway, ?float $amount = null, string $transId = ''): Invoice
    {
        $amount = $amount ?? $this->invoices->outstanding($invoice);

        return DB::transaction(function () use ($invoice, $gateway, $amount, $transId) {
            $this->invoices->markPaid($invoice, $gateway, $amount);

            Account::create([
                'uid' => (int) $invoice->uid,
                'currency' => (string) (Currency::default()?->code ?? 'CNY'),
                'gateway' => $gateway,
                'create_time' => time(),
                'update_time' => time(),
                'pay_time' => time(),
                'description' => $invoice->type === 'recharge'
                    ? '用户充值'
                    : ('账单支付 #' . $invoice->invoiceNumber()),
                'amount_in' => PricingService::money($amount),
                'fees' => 0,
                'amount_out' => 0,
                'rate' => 1,
                'trans_id' => $transId,
                'invoice_id' => (int) $invoice->id,
                'refund' => 0,
                'delete_time' => 0,
                'aff_refund' => 0,
                'refund_credit' => 0,
            ]);

            // A recharge invoice credits the client balance.
            if ((string) $invoice->type === 'recharge') {
                $client = $invoice->client;

                if ($client !== null) {
                    $client->addCredit(
                        PricingService::money($amount),
                        '充值 #' . $invoice->invoiceNumber(),
                        (int) $invoice->id
                    );
                }
            }

            $this->markPayLogPaid($invoice->id, $gateway, $transId);

            return $invoice->refresh();
        });
    }

    /**
     * Offline confirmation, performed by an administrator or a client upload.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status:bool, msg:string, invoice:?Invoice}
     */
    protected function confirmOffline(array $payload): array
    {
        $invoice = Invoice::query()->find((int) ($payload['invoiceid'] ?? 0));

        if ($invoice === null) {
            return ['status' => false, 'msg' => '账单不存在', 'invoice' => null];
        }

        if ($invoice->isPaid()) {
            return ['status' => false, 'msg' => '账单已支付', 'invoice' => $invoice];
        }

        $this->settle(
            $invoice,
            self::OFFLINE,
            (float) ($payload['amount'] ?? $this->invoices->outstanding($invoice)),
            (string) ($payload['trans_id'] ?? '')
        );

        return ['status' => true, 'msg' => '已确认收款', 'invoice' => $invoice];
    }

    /**
     * Refund through the gateway that took the payment.
     */
    public function refund(Invoice $invoice, float $amount, string $reason = ''): bool
    {
        $gateway = (string) $invoice->payment;

        if ($gateway === '' || $gateway === self::OFFLINE) {
            // Offline payments are refunded by hand; the ledger entry is the
            // only thing the platform can produce.
            return $this->invoices->refund($invoice, $amount, $reason);
        }

        $driver = $this->loadDriver($gateway);

        if ($driver === null) {
            return false;
        }

        try {
            $result = $driver('refund', [
                'invoiceid' => (int) $invoice->id,
                'amount' => PricingService::money($amount),
                'reason' => $reason,
            ]);
        } catch (\Throwable $e) {
            Log::error('Payment gateway refund failed', [
                'gateway' => $gateway,
                'invoice' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (! ($result['status'] ?? false)) {
            return false;
        }

        return $this->invoices->refund($invoice, $amount, $reason);
    }

    /**
     * Payment-status poll used by the client area.
     *
     * The original answers `1000` while a payment is still in flight and
     * `200` once the invoice is settled (`status == 200` success).
     */
    public function status(Invoice $invoice): array
    {
        if ($invoice->isPaid()) {
            return [
                'status' => true,
                'code' => 200,
                'paid' => true,
                'msg' => '支付成功',
                'url' => $this->returnUrl($invoice),
                'hid' => $this->relatedHostIds($invoice),
            ];
        }

        return [
            'status' => true,
            'code' => 1000,
            'paid' => false,
            'msg' => '支付中',
            'url' => '',
            'hid' => $this->relatedHostIds($invoice),
        ];
    }

    /**
     * Where the client goes once an invoice is paid: the service it was
     * raised for, else the stored return URL.
     */
    public function returnUrl(Invoice $invoice): string
    {
        if (trim((string) $invoice->url) !== '') {
            return (string) $invoice->url;
        }

        $hostId = $this->relatedHostIds($invoice);

        if ($hostId !== []) {
            return 'servicedetail?id=' . $hostId[0];
        }

        return 'billing?id=' . $invoice->id;
    }

    /**
     * Host ids an invoice's line items relate to.
     *
     * @return array<int, int>
     */
    public function relatedHostIds(Invoice $invoice): array
    {
        return $invoice->items()
            ->whereIn('type', ['hosting', 'renew', 'upgrade', 'configoptions'])
            ->orderBy('id')
            ->pluck('rel_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Load a gateway plugin, returning a callable `fn($action, $params)`.
     *
     * Two plugin shapes are supported: the original's plain functions
     * (`<gateway>_pay`, `<gateway>_callback`) and a class named
     * `\<Gateway>` with `pay`/`callback`/`refund` methods.
     */
    public function loadDriver(string $gateway): ?callable
    {
        $gateway = preg_replace('/[^A-Za-z0-9_\-]/', '', $gateway) ?: '';

        if ($gateway === '') {
            return null;
        }

        $path = base_path('public/plugins/gateways/' . $gateway . '/' . $gateway . '.php');

        if (! is_file($path)) {
            return null;
        }

        if (! isset(self::$loaded[$gateway])) {
            try {
                require_once $path;
            } catch (\Throwable $e) {
                Log::error('Payment gateway plugin failed to load', [
                    'gateway' => $gateway,
                    'error' => $e->getMessage(),
                ]);

                self::$loaded[$gateway] = false;

                return null;
            }

            self::$loaded[$gateway] = true;
        }

        if (! self::$loaded[$gateway]) {
            return null;
        }

        $class = '\\' . $gateway;

        if (class_exists($class)) {
            return function (string $action, array $params) use ($class) {
                if (! method_exists($class, $action)) {
                    throw new \RuntimeException('支付插件缺少方法：' . $action);
                }

                return (new $class())->{$action}($params);
            };
        }

        $functions = [];

        foreach (['pay', 'callback', 'refund'] as $action) {
            foreach (['_', ''] as $separator) {
                $candidate = $gateway . $separator . $action;

                if (function_exists($candidate)) {
                    $functions[$action] = $candidate;

                    break;
                }

                $candidate = strtolower($gateway) . $separator . $action;

                if (function_exists($candidate)) {
                    $functions[$action] = $candidate;

                    break;
                }
            }
        }

        if ($functions === []) {
            return null;
        }

        return function (string $action, array $params) use ($functions) {
            if (! isset($functions[$action])) {
                throw new \RuntimeException('支付插件缺少方法：' . $action);
            }

            return $functions[$action]($params);
        };
    }

    /**
     * Parameter bag handed to a gateway plugin.
     */
    protected function driverParams(
        PaymentGateway $gateway,
        Invoice $invoice,
        Client $client,
        float $amount,
        int $logId,
    ): array {
        $currency = Currency::default();

        return [
            'invoiceid' => (int) $invoice->id,
            'ordernum' => $invoice->invoiceNumber(),
            'amount' => PricingService::money($amount),
            'currency' => (string) ($currency->code ?? 'CNY'),
            'subject' => $invoice->type === 'recharge'
                ? '账户充值'
                : ('账单支付 #' . $invoice->invoiceNumber()),
            'client' => [
                'id' => (int) $client->id,
                'email' => (string) $client->email,
                'username' => (string) $client->username,
                'phonenumber' => (string) $client->phonenumber,
            ],
            'setting' => $gateway->settings(),
            'notify_url' => url('/v1/pay/callback/' . $gateway->gateway),
            'return_url' => url('/pay?action=billing&invoiceid=' . $invoice->id),
            'trans_id' => $logId,
            'ip' => (string) (RequestFacade::ip() ?? ''),
        ];
    }

    /**
     * Gateway results arrive as `['status'=>bool,'data'=>['type'=>…,'data'=>…]]`
     * or a bare HTML/URL string.
     */
    protected function normalisePayResult(mixed $result): array
    {
        if (is_string($result)) {
            return ['status' => $result !== '', 'msg' => '', 'data' => ['type' => 'html', 'data' => $result]];
        }

        if (! is_array($result)) {
            return ['status' => false, 'msg' => '支付接口返回数据异常', 'data' => null];
        }

        $status = (bool) ($result['status'] ?? $result['code'] ?? false);
        $data = $result['data'] ?? $result['pay_html'] ?? null;

        if ($data !== null && ! isset($data['type'])) {
            $data = ['type' => 'url', 'data' => is_string($data) ? $data : ''];
        }

        return [
            'status' => $status,
            'msg' => (string) ($result['msg'] ?? ($status ? '请求成功' : '支付请求失败')),
            'data' => $data,
        ];
    }

    /**
     * Record a payment attempt; the returned id is echoed to the gateway as
     * the transaction reference.
     */
    public function logPay(Invoice $invoice, string $gateway, float $amount, string $status): int
    {
        return (int) DB::table('pay_log')->insertGetId([
            'invoice_id' => (int) $invoice->id,
            'trans_id' => 0,
            'payment' => $gateway,
            'amount' => PricingService::money($amount),
            'currency' => (string) (Currency::default()?->code ?? 'CNY'),
            'status' => $status,
            'description' => '',
            'create_time' => time(),
        ]);
    }

    protected function updatePayLog(int $logId, string $status, string $description = ''): void
    {
        if ($logId <= 0) {
            return;
        }

        DB::table('pay_log')->where('id', $logId)->update([
            'status' => $status,
            'description' => mb_substr($description, 0, 255),
        ]);
    }

    protected function markPayLogPaid(int $invoiceId, string $gateway, string $transId = ''): void
    {
        DB::table('pay_log')
            ->where('invoice_id', $invoiceId)
            ->where('payment', $gateway)
            ->where('status', self::PAY_PENDING)
            ->update([
                'status' => self::PAY_SUCCESS,
                'trans_id' => $transId !== '' ? (int) preg_replace('/\D/', '', $transId) : 0,
            ]);
    }

    /**
     * Bank transfer instructions rendered into the pay panel.
     */
    protected function bankTransferHtml(Invoice $invoice, float $amount): string
    {
        $bank = [
            '开户银行' => (string) Configuration::value('bank_name', ''),
            '收款户名' => (string) Configuration::value('bank_account_name', Configuration::value('company_name', '')),
            '银行账号' => (string) Configuration::value('bank_account', ''),
            '付款备注' => '订单号 ' . $invoice->invoiceNumber(),
        ];

        $rows = '';

        foreach ($bank as $label => $value) {
            if ($value === '') {
                continue;
            }

            $rows .= '<li><span>' . e($label) . '：</span>' . e($value) . '</li>';
        }

        $note = (string) Configuration::value(
            'bank_transfer_note',
            '请在转账后将凭证提交给客服，我们会在核实后为您完成支付。'
        );

        return '<div class="bank-transfer">'
            . '<h4>对公转账</h4>'
            . '<p>应付金额：<strong>' . e($this->money($amount)) . '</strong></p>'
            . '<ul>' . $rows . '</ul>'
            . '<p class="tip">' . e($note) . '</p>'
            . '</div>';
    }

    protected function money(float $amount): string
    {
        return number_format(PricingService::money($amount), 2, '.', '');
    }
}
