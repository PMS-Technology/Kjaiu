<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLog extends Model
{
    public const ACTION_LABELS = [
        'product.created' => '创建商品', 'product.updated' => '修改商品', 'product.toggled' => '切换商品上下架', 'product.deleted' => '删除商品',
        'product_group.created' => '创建商品分组', 'customer.created' => '创建用户', 'customer.updated' => '修改用户资料',
        'credit.adjusted' => '修改用户余额', 'invoice.created' => '创建账单', 'invoice.paid' => '确认账单收款', 'invoice.cancelled' => '取消账单',
        'supplier.created' => '创建上游供应商', 'supplier.updated' => '修改上游供应商', 'supplier.catalog_products_imported' => '导入上游商品',
        'supplier.identifier_revealed' => '查看供应商登录标识',
        'settings.site_updated' => '修改站点设置', 'settings.mail_updated' => '修改邮件配置', 'settings.mail_tested' => '发送测试邮件',
        'payment_gateway.created' => '添加收款方式', 'payment_gateway.updated' => '修改收款方式',
    ];

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function getActionLabelAttribute(): string
    {
        return self::ACTION_LABELS[$this->action] ?? match (true) {
            str_contains($this->action, 'succeeded') => '上游操作成功',
            str_contains($this->action, 'failed') => '上游操作失败',
            str_contains($this->action, 'requested') => '发起上游操作',
            default => str_replace(['.', '_'], ' ', $this->action),
        };
    }

    public function getSubjectLabelAttribute(): string
    {
        $type = class_basename((string) $this->subject_type);
        $label = ['User' => '用户', 'Product' => '商品', 'Invoice' => '账单', 'SupplierAccount' => '供应商', 'PaymentGateway' => '收款方式', 'SystemSetting' => '系统设置'][$type] ?? $type;

        return $label.($this->subject_id ? ' #'.$this->subject_id : '');
    }

    public static function record(
        Request $request,
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
    ): self {
        return self::create([
            'actor_id' => $request->user()?->id,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
        ]);
    }
}
