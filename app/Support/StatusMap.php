<?php

namespace App\Support;

/**
 * Chinese label maps for the enumerations the client area renders.
 *
 * Values mirror the original platform's language pack
 * (`public/language/chinese.php`, keys `domainstatus_select_*`,
 * `invoice_payment_status_*`, `billing_cycle_*`, `product_type_*`, ...), so the
 * markup produced here reads the same as the ThinkPHP templates it replaces.
 */
class StatusMap
{
    /** Host `domainstatus` -> 中文. */
    public const HOST_STATUS = [
        'Pending' => '待开通',
        'Active' => '已激活',
        'Suspended' => '已暂停',
        'Cancelled' => '被取消',
        'Fraud' => '有欺诈',
        'Deleted' => '被删除',
        'Completed' => '已完成',
        'Verifiy_Active' => '待核验',
        'Overdue_Active' => '已逾期',
        'Issue_Active' => '签发中',
    ];

    /** SSL products use a different reading for the same Pending value. */
    public const HOST_STATUS_SSL = [
        'Pending' => '待核验',
    ];

    /** Badge colour class per host status (Tailwind utility fragments). */
    public const HOST_STATUS_COLOR = [
        'Pending' => 'amber',
        'Active' => 'emerald',
        'Suspended' => 'orange',
        'Cancelled' => 'rose',
        'Fraud' => 'rose',
        'Deleted' => 'slate',
        'Completed' => 'sky',
        'Verifiy_Active' => 'amber',
        'Overdue_Active' => 'rose',
        'Issue_Active' => 'sky',
    ];

    /** `shd_invoices.status` -> 中文. */
    public const INVOICE_STATUS = [
        'Paid' => '已支付',
        'Unpaid' => '未支付',
        'Refunded' => '已退款',
        'Cancelled' => '被取消',
        'Draft' => '已草稿',
        'Overdue' => '已逾期',
        'Collections' => '已收藏',
    ];

    public const INVOICE_STATUS_COLOR = [
        'Paid' => 'emerald',
        'Unpaid' => 'rose',
        'Refunded' => 'sky',
        'Cancelled' => 'slate',
        'Draft' => 'slate',
        'Overdue' => 'orange',
        'Collections' => 'amber',
    ];

    /** Credit-limit invoices use their own wording. */
    public const CREDIT_LIMIT_INVOICE_STATUS = [
        'Paid' => '已还款',
        'Unpaid' => '待还款',
        'prepayment' => '提前还款',
        'Overdue' => '已逾期',
    ];

    /** Billing cycle key -> long 中文. */
    public const BILLING_CYCLE = [
        'free' => '免费',
        'onetime' => '一次性',
        'hour' => '小时',
        'day' => '天',
        'ontrial' => '试用',
        'monthly' => '月付',
        'quarterly' => '季付',
        'semiannually' => '半年付',
        'annually' => '年付',
        'biennially' => '两年付',
        'triennially' => '三年付',
        'fourly' => '四年付',
        'fively' => '五年付',
        'sixly' => '六年付',
        'sevenly' => '七年付',
        'eightly' => '八年付',
        'ninely' => '九年付',
        'tenly' => '十年付',
    ];

    /** Billing cycle key -> short 中文 used in list cells. */
    public const BILLING_CYCLE_SHORT = [
        'free' => '免费',
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

    /** `shd_orders.status` -> 中文. */
    public const ORDER_STATUS = [
        'Pending' => '待审核',
        'Active' => '已激活',
        'Completed' => '已完成',
        'Suspend' => '已暂停',
        'Terminated' => '被删除',
        'Cancelled' => '被取消',
        'Fraud' => '有欺诈',
        'Unpaid' => '未支付',
        'Paid' => '已支付',
        'Refunded' => '已退款',
    ];

    /** `shd_products.type` -> 中文. */
    public const PRODUCT_TYPE = [
        'hostingaccount' => '虚拟主机',
        'server' => '独立服务器',
        'cloud' => '云服务器',
        'dcimcloud' => '魔方云',
        'dcim' => '魔方DCIM',
        'bare_metal' => '裸金属',
        'software' => '软件产品',
        'cdn' => 'CDN',
        'other' => '其他服务',
        'ssl' => 'SSL证书',
        'domain' => '域名',
        'sms' => '短信服务',
    ];

    /** `shd_invoice_items.type` -> 中文. */
    public const INVOICE_ITEM_TYPE = [
        'product' => '产品',
        'hosting' => '产品',
        'host' => '产品',
        'renew' => '续费',
        'setup' => '初装费',
        'upgrade' => '产品升降级',
        'down' => '降级',
        'packet' => '流量包',
        'zjmf_flow_packet' => '流量包',
        'zjmf_reinstall_times' => '重装次数',
        'combine' => '合并账单',
        'voucher' => '发票',
        'credit_limit' => '信用额',
        'transfer_fee' => '产品转移费',
        'recharge' => '充值',
        'promo' => '优惠码',
        'discount' => '客户折扣',
        'express' => '快递费',
        'contract' => '合同邮费',
        'certifi_person' => '个人实名认证',
        'certifi_company' => '企业实名认证',
        'credit' => '余额',
        'refund' => '退款',
        'bill' => '账单',
    ];

    /** Ticket priority -> 中文. */
    public const TICKET_PRIORITY = [
        'low' => '低',
        'medium' => '中',
        'high' => '高',
    ];

    /** `shd_system_message.type` -> 中文. */
    public const MESSAGE_TYPE = [
        1 => '工单消息',
        2 => '产品消息',
        3 => '站内消息',
        4 => '活动消息',
    ];

    /** Client account status -> 中文. */
    public const CLIENT_STATUS = [
        0 => '停用',
        1 => '正常',
        2 => '关闭',
    ];

    /** Real-name certification status -> 中文. */
    public const CERTIFI_STATUS = [
        0 => '未认证',
        1 => '已认证',
        2 => '未通过',
        3 => '待审核',
        4 => '提交资料',
    ];

    /** Affiliate withdrawal status -> 中文. */
    public const WITHDRAW_STATUS = [
        1 => '待审核',
        2 => '审核通过',
        3 => '拒绝',
    ];

    /** Affiliate withdrawal type -> 中文. */
    public const WITHDRAW_TYPE = [
        1 => '余额',
        2 => '仅记录',
        3 => '流水支持',
    ];

    /** Cancel request status -> 中文. */
    public const CANCEL_REQUEST_STATUS = [
        0 => '未执行',
        1 => '已执行',
        2 => '已执行但失败',
    ];

    /** Host cancel type -> 中文. */
    public const CANCEL_TYPE = [
        'Immediate' => '立即',
        'Endofbilling' => '等待账单周期结束',
    ];

    /** Module power status -> 中文. */
    public const POWER_STATUS = [
        'on' => '已开机',
        'off' => '已关机',
        'unknown' => '未知',
        'process' => '处理中',
        'waiting' => '等待中',
        'suspend' => '已暂停',
        'wait_reboot' => '等待重启',
        'wait' => '等待中',
    ];

    /** Host suspension reasons -> 中文. */
    public const SUSPEND_REASON = [
        'due' => '到期',
        'flow' => '用量超额',
        'uncertifi' => '未实名认证',
        'other' => '其他',
    ];

    public static function hostStatus(string $status, string $type = ''): string
    {
        if ($type === 'ssl' && isset(self::HOST_STATUS_SSL[$status])) {
            return self::HOST_STATUS_SSL[$status];
        }

        return self::HOST_STATUS[$status] ?? $status;
    }

    public static function hostStatusColor(string $status): string
    {
        return self::HOST_STATUS_COLOR[$status] ?? 'slate';
    }

    public static function invoiceStatus(string $status, bool $creditLimit = false): string
    {
        $map = $creditLimit ? self::CREDIT_LIMIT_INVOICE_STATUS : self::INVOICE_STATUS;

        return $map[$status] ?? $status;
    }

    /**
     * Credit-limit invoices read `Paid` as 已还款 rather than 已支付.
     */
    public static function creditLimitInvoiceStatus(string $status): string
    {
        return self::CREDIT_LIMIT_INVOICE_STATUS[$status] ?? self::INVOICE_STATUS[$status] ?? $status;
    }

    public static function invoiceStatusColor(string $status): string
    {
        return self::INVOICE_STATUS_COLOR[$status] ?? 'slate';
    }

    public static function cycle(string $cycle): string
    {
        return self::BILLING_CYCLE[$cycle] ?? $cycle;
    }

    public static function cycleShort(string $cycle): string
    {
        return self::BILLING_CYCLE_SHORT[$cycle] ?? $cycle;
    }

    public static function productType(string $type): string
    {
        return self::PRODUCT_TYPE[$type] ?? $type;
    }

    public static function invoiceItemType(string $type): string
    {
        return self::INVOICE_ITEM_TYPE[$type] ?? $type;
    }

    public static function messageType(?int $type): string
    {
        return self::MESSAGE_TYPE[(int) $type] ?? '站内消息';
    }

    /**
     * Status filter options offered by the service list, in the order the
     * original template presents them.
     */
    public static function hostStatusOptions(): array
    {
        return [
            'Pending' => self::HOST_STATUS['Pending'],
            'Active' => self::HOST_STATUS['Active'],
            'Suspended' => self::HOST_STATUS['Suspended'],
            'Cancelled' => self::HOST_STATUS['Cancelled'],
            'Deleted' => self::HOST_STATUS['Deleted'],
        ];
    }
}
