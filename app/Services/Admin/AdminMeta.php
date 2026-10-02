<?php

namespace App\Services\Admin;

use App\Models\Client;
use App\Models\Currency;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\TicketStatus;

/**
 * The enum/dictionary payloads the admin SPA renders in selects.
 *
 * The original platform repeats these maps in every page response (product
 * types, pay types, domain statuses, invoice statuses, …). They live here so
 * the controllers stay readable and a label only has to be corrected once.
 */
class AdminMeta
{
    /**
     * 商品类型 — also used as the `schema` discriminator on `shd_host.type`.
     */
    public const PRODUCT_TYPES = [
        'hostingaccount' => '虚拟主机',
        'server' => '独立服务器',
        'cloud' => '云服务器',
        'dcimcloud' => '魔方云',
        'dcim' => '魔方DCIM',
        'bareMetal' => '裸金属',
        'software' => '软件产品',
        'cdn' => 'CDN',
        'other' => '其他服务',
        'ssl' => 'ssl证书',
        'domain' => '域名',
        'sms' => '短信服务',
    ];

    /**
     * Per-admin-page product type subsets — the resource pages only offer a
     * slice of the full list.
     */
    public const OPTION_PRODUCT_TYPES = [
        'hostingaccount' => '虚拟主机',
        'server' => '独立服务器',
        'cloud' => '云服务器',
        'bareMetal' => '裸金属',
        'software' => '软件产品',
        'cdn' => 'CDN',
        'other' => '其他服务',
        'ssl' => 'ssl证书',
        'domain' => '域名',
        'sms' => '短信服务',
    ];

    public const PAY_TYPES = [
        'free' => '免费',
        'onetime' => '一次性',
        'recurring' => '周期',
    ];

    /**
     * 定价 column — the product list shows the cycle set rather than the code.
     */
    public const PAY_TYPE_LABELS = [
        'free' => '免费',
        'onetime' => '一次性',
        'recurring' => '周期',
        'day' => '按天',
        'hour' => '按小时',
        'ontrial' => '试用',
        'onetime_recurring' => '一次性/周期',
    ];

    public const AUTO_SETUP = [
        '' => '无',
        'on' => '审核后开通',
        'payment' => '付款后开通',
        'order' => '下单后开通',
    ];

    public const BILLING_CYCLES = [
        'onetime' => '一次性',
        'hour' => '小时',
        'day' => '天',
        'ontrial' => '试用',
        'monthly' => '月',
        'quarterly' => '季',
        'semiannually' => '半年',
        'annually' => '年',
        'biennially' => '两年',
        'triennially' => '三年',
        'fourly' => '四年',
        'fively' => '五年',
        'sixly' => '六年',
        'sevenly' => '七年',
        'eightly' => '八年',
        'ninely' => '九年',
        'tenly' => '十年',
    ];

    /** Cycle columns as they appear on `shd_pricing`, in schema order. */
    public const CYCLE_COLUMNS = [
        'onetime', 'hour', 'day', 'ontrial', 'monthly', 'quarterly', 'semiannually',
        'annually', 'biennially', 'triennially', 'fourly', 'fively', 'sixly',
        'sevenly', 'eightly', 'ninely', 'tenly',
    ];

    /** Setup-fee column suffix for each cycle. */
    public const SETUP_COLUMNS = [
        'onetime' => 'osetupfee',
        'hour' => 'hsetupfee',
        'day' => 'dsetupfee',
        'ontrial' => 'ontrialfee',
        'monthly' => 'msetupfee',
        'quarterly' => 'qsetupfee',
        'semiannually' => 'ssetupfee',
        'annually' => 'asetupfee',
        'biennially' => 'bsetupfee',
        'triennially' => 'tsetupfee',
        'fourly' => 'foursetupfee',
        'fively' => 'fivesetupfee',
        'sixly' => 'sixsetupfee',
        'sevenly' => 'sevensetupfee',
        'eightly' => 'eightsetupfee',
        'ninely' => 'ninesetupfee',
        'tenly' => 'tensetupfee',
    ];

    public const DOMAIN_STATUS = [
        'Pending' => ['name' => '待开通', 'color' => '#fca426'],
        'Active' => ['name' => '已激活', 'color' => '#3fbf70'],
        'Cancelled' => ['name' => '被取消', 'color' => '#959799'],
        'Fraud' => ['name' => '有欺诈', 'color' => '#FF0000'],
        'Deleted' => ['name' => '被删除', 'color' => '#2d2d2d'],
        'Suspended' => ['name' => '已暂停', 'color' => '#e31519'],
    ];

    public const ORDER_STATUS = [
        'Pending' => ['name' => '待核验', 'color' => '#FF0000'],
        'Active' => ['name' => '已激活', 'color' => '#008000'],
        'Cancelled' => ['name' => '已取消', 'color' => '#808080'],
        'Fraud' => ['name' => '有欺诈', 'color' => '#000000'],
        'Suspended' => ['name' => '已暂停', 'color' => '#000000'],
        'Guarantee' => ['name' => '担保中', 'color' => '#000000'],
    ];

    public const INVOICE_STATUS = [
        'Unpaid' => ['name' => '未支付', 'color' => '#FF0000'],
        'Paid' => ['name' => '已支付', 'color' => '#008000'],
        'Refunded' => ['name' => '已退款', 'color' => '#000000'],
        'Cancelled' => ['name' => '被取消', 'color' => '#808080'],
        'Draft' => ['name' => '已草稿', 'color' => '#808080'],
        'Overdue' => ['name' => '已逾期', 'color' => '#FFA500'],
        'Collections' => ['name' => '已收藏', 'color' => '#808080'],
    ];

    public const CLIENT_STATUS = [
        ['name' => '停用'],
        ['name' => '正常'],
        ['name' => '关闭'],
    ];

    public const CLIENT_CERTIFI_STATUS = [
        ['name' => '未认证'],
        ['name' => '已认证'],
        ['name' => '未通过'],
        ['name' => '待审核'],
        ['name' => '提交资料'],
    ];

    public const CUSTOM_FIELD_TYPES = [
        'text' => '文本框',
        'link' => '链接',
        'password' => '密码',
        'dropdown' => '下拉',
        'tickbox' => '选项框',
        'textarea' => '文本区',
    ];

    public const CUSTOM_BROKERAGE = [
        'default' => '默认',
        'percentage' => '百分比',
        'fixed' => '固定数额',
        'none' => '无',
    ];

    public const AFFILIATE_PAY_TYPE = [
        'default' => '默认',
        'percentage' => '百分比',
        'fixed' => '固定数额',
        'none' => '无',
    ];

    public const CART_THEMES = ['default', 'province', 'area'];

    public const API_TYPES = [
        ['name' => 'normal', 'name_zh' => '本地接口'],
        ['name' => 'zjmf_api', 'name_zh' => '供应商资源'],
    ];

    public const PAY_ON_TRIAL_CONDITION = [
        'email' => '邮件',
        'phone' => '手机',
        'wechat' => '微信',
        'realname' => '实名认证',
    ];

    public const LANGUAGES = [
        'zh-cn' => '中文简体',
        'zh-hk' => '中文繁体',
        'en-us' => 'English',
    ];

    public const PROFILE_OPTIONAL_FIELDS = [
        'username' => '姓名',
        'companyname' => '公司',
        'qq' => 'QQ',
        'address1' => '地址',
    ];

    public const SEX_OPTIONS = [
        ['value' => '0', 'label' => '未知'],
        ['value' => '1', 'label' => '男'],
        ['value' => '2', 'label' => '女'],
    ];

    public const PROMO_TYPES = [
        'percent' => '百分比',
        'fixed' => '固定金额',
        'override' => '覆盖价格',
        'free' => '免费',
    ];

    public const WITHDRAW_STATUS = [
        'Pending' => '待审核',
        'Cancelled' => '已拒绝',
        'Active' => '已通过',
    ];

    public const WITHDRAW_TYPE = [
        'credit' => '余额提现',
        'income' => '推广收益提现',
    ];

    /**
     * Module registry used by the product/server screens. Modules are folders
     * under `public/plugins/servers`; the built-in list is returned when the
     * directory is absent (as in this port, where modules are dispatched
     * through `App\Services\ModuleService`).
     *
     * @return array<int,array{value:string,name:string}>
     */
    public static function modules(): array
    {
        return ModuleRegistry::modules();
    }

    /**
     * `{value, name}` pairs for a PHP map.
     */
    public static function options(array $map): array
    {
        $out = [];

        foreach ($map as $value => $name) {
            $out[] = ['value' => (string) $value, 'name' => $name];
        }

        return $out;
    }

    /**
     * Tickets statuses as rows (`shd_ticket_status` is admin editable).
     */
    public static function ticketStatuses(): array
    {
        return TicketStatus::query()->orderBy('order')->get([
            'id', 'title', 'color', 'order', 'show_active', 'show_await', 'auto_close',
        ])->toArray();
    }

    /**
     * Payment gateways enabled in the panel.
     */
    public static function gateways(): array
    {
        // `shd_payment_gateways` is keyed by `gateway`, not by an `id` column.
        return PaymentGateway::query()->orderBy('order')->get()->toArray();
    }

    /**
     * Currencies, optionally with their rate relative to the default.
     */
    public static function currencies(): array
    {
        return Currency::query()->orderBy('id')->get()->toArray();
    }

    /**
     * Active administrator list for the 销售 filters.
     */
    public static function admins(): array
    {
        return \App\Models\User::query()
            ->where('user_status', 1)
            ->orderBy('id')
            ->get(['id', 'user_login', 'user_nickname'])
            ->toArray();
    }

    /**
     * Client group options.
     */
    public static function clientGroups(): array
    {
        return \App\Models\ClientGroup::query()
            ->orderBy('id')
            // The column is `group_colour` in the mirrored schema.
            ->get(['id', 'group_name', 'group_colour', 'discount_percent'])
            ->toArray();
    }

    /**
     * `{id, name}` pairs for product groups plus their first-level parents.
     *
     * @return array<int,array{id:int,name:string}>
     */
    public static function productGroups(): array
    {
        return \App\Models\ProductGroup::query()->orderBy('order')->get(['id', 'name'])->toArray();
    }

    /**
     * Product picker used by `common/get_product_list`.
     */
    public static function productList(?string $type = null, ?int $id = null): array
    {
        $query = Product::query()->orderBy('order');

        if ($type !== null && $type !== '') {
            $query->where('type', $type);
        }

        if ($id) {
            $query->where('gid', $id);
        }

        return $query->get(['id', 'name', 'gid', 'type', 'pay_type'])->toArray();
    }

    /**
     * Human label for a product type.
     */
    public static function productTypeLabel(?string $type): string
    {
        return self::PRODUCT_TYPES[(string) $type] ?? (string) $type;
    }

    /**
     * Human label for a host/domain status.
     */
    public static function domainStatusLabel(?string $status): string
    {
        return self::DOMAIN_STATUS[(string) $status]['name'] ?? (string) $status;
    }

    /**
     * Human label for an order status.
     */
    public static function orderStatusLabel(?string $status): string
    {
        return self::ORDER_STATUS[(string) $status]['name'] ?? (string) $status;
    }

    /**
     * Human label for an invoice status.
     */
    public static function invoiceStatusLabel(?string $status): string
    {
        return self::INVOICE_STATUS[(string) $status]['name'] ?? (string) $status;
    }

    /**
     * Ticket status title, used by the ticket list/detail.
     */
    public static function ticketStatusTitle(?int $statusId): string
    {
        if (! $statusId) {
            return '';
        }

        return (string) (TicketStatus::query()->whereKey($statusId)->value('title') ?? '');
    }

    /**
     * Client status label.
     */
    public static function clientStatusLabel(int $status): string
    {
        return self::CLIENT_STATUS[$status]['name'] ?? '未知';
    }

    /**
     * Product "定价" column, derived from the stored `pay_type` JSON blob.
     */
    public static function productPayLabel(Product $product): string
    {
        $payType = $product->pay_type;

        if (is_string($payType) && str_starts_with(trim($payType), '{')) {
            $decoded = json_decode($payType, true);

            if (is_array($decoded) && isset($decoded['pay_type'])) {
                $payType = $decoded['pay_type'];
            }
        }

        return self::PAY_TYPE_LABELS[(string) $payType] ?? '免费';
    }

    /**
     * Map a client row for the admin lists, adding the derived columns the
     * table components expect (`group_name`, `host_total`, `credit_limit`…).
     */
    public static function clientRow(Client $client): array
    {
        $row = $client->toArray();
        $row['group_name'] = (string) (optional($client->group)->group_name ?? '');
        $row['status_zh'] = self::clientStatusLabel((int) $client->status);
        $row['certifi_zh'] = self::CLIENT_CERTIFI_STATUS[(int) $client->certifi]['name'] ?? '未认证';

        return $row;
    }

    /**
     * Ticket row with the joined labels the list renders.
     */
    public static function ticketRow(Ticket $ticket): array
    {
        $row = $ticket->toArray();
        $row['status_title'] = self::ticketStatusTitle((int) $ticket->status);
        $row['status_color'] = (string) (TicketStatus::query()->whereKey($ticket->status)->value('color') ?? '');

        return $row;
    }
}
