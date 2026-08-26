<?php

namespace App\Models;

use DomainException;
use JsonException;

class SupplierOperation extends SupplierOwnedModel
{
    public const ACTION_PROVISION = 'provision';

    public const ACTION_RENEW = 'renew';

    public const ACTION_SUSPEND = 'suspend';

    public const ACTION_UNSUSPEND = 'unsuspend';

    public const ACTION_CANCEL = 'cancel';

    public const ACTION_SYNC = 'sync';

    public const ACTIONS = [
        self::ACTION_PROVISION,
        self::ACTION_RENEW,
        self::ACTION_SUSPEND,
        self::ACTION_UNSUSPEND,
        self::ACTION_CANCEL,
        self::ACTION_SYNC,
    ];

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_BLOCKED_CREDIT = 'blocked_credit';

    public const STATUS_AWAITING_CONFIRMATION = 'awaiting_confirmation';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_BLOCKED_CREDIT,
        self::STATUS_AWAITING_CONFIRMATION,
        self::STATUS_AMBIGUOUS,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
    ];

    public const NONTERMINAL_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_AWAITING_CONFIRMATION,
        self::STATUS_BLOCKED_CREDIT,
        self::STATUS_AMBIGUOUS,
    ];

    protected $fillable = [
        'action',
        'status',
        'step',
        'idempotency_key',
        'request_hash',
        'upstream_reference',
        'request_payload',
        'response_payload',
        'last_error_code',
        'last_error',
        'attempts',
        'metadata',
        'available_at',
        'started_at',
        'finished_at',
    ];

    protected $hidden = ['request_payload', 'response_payload'];

    protected static function booted(): void
    {
        parent::booted();

        static::saving(function (SupplierOperation $operation): void {
            if (! is_string($operation->action) || trim($operation->action) === '') {
                throw new DomainException('A non-empty supplier operation action is required.');
            }
            $operation->action = trim($operation->action);

            $operation->status ??= self::STATUS_QUEUED;

            if (! is_string($operation->idempotency_key) || trim($operation->idempotency_key) === '') {
                throw new DomainException('A non-empty supplier operation idempotency key is required.');
            }
            $operation->idempotency_key = trim($operation->idempotency_key);
            if (strlen($operation->idempotency_key) > 128) {
                throw new DomainException('The supplier operation idempotency key cannot exceed 128 characters.');
            }
            if (preg_match('/[^\x20-\x7e]/', $operation->idempotency_key)) {
                throw new DomainException(
                    'The supplier operation idempotency key must contain printable ASCII characters only.',
                );
            }
            if (! is_string($operation->request_hash) || preg_match('/\A[0-9a-f]{64}\z/', $operation->request_hash) !== 1) {
                throw new DomainException('A valid supplier operation request hash is required.');
            }
            if ($operation->upstream_reference !== null
                && (! is_string($operation->upstream_reference)
                    || trim($operation->upstream_reference) === ''
                    || strlen(trim($operation->upstream_reference)) > 128
                    || preg_match('/[^\x20-\x7e]/', trim($operation->upstream_reference)))) {
                throw new DomainException(
                    'The supplier operation upstream reference must contain at most 128 printable ASCII characters.',
                );
            }
            if ($operation->upstream_reference !== null) {
                $operation->upstream_reference = trim($operation->upstream_reference);
            }

            $operation->last_error = SupplierErrorSanitizer::sanitize(
                $operation->last_error,
                [$operation->request_payload ?? [], $operation->response_payload ?? []],
            );
        });
    }

    public function setLastErrorAttribute(mixed $error): void
    {
        $this->attributes['last_error'] = SupplierErrorSanitizer::sanitize($error);
    }

    public function setUpstreamReferenceAttribute(mixed $identifier): void
    {
        if ($identifier === null) {
            $this->attributes['upstream_reference'] = null;

            return;
        }
        if (! is_string($identifier) && ! is_int($identifier)) {
            throw new DomainException('The supplier operation upstream reference must be a string or integer.');
        }

        $this->attributes['upstream_reference'] = (string) $identifier;
    }

    public static function createFor(
        SupplierAccount $account,
        array $attributes,
        ?SupplierProductMapping $productMapping = null,
        ?Order $order = null,
        ?OrderItem $orderItem = null,
        ?Invoice $invoice = null,
        ?Service $service = null,
        ?SupplierServiceLink $serviceLink = null,
        ?SupplierInvoiceLink $invoiceLink = null,
        ?SupplierOrderItemRoute $orderItemRoute = null,
    ): static {
        $account = static::requirePersisted($account, 'supplier account');
        $orderItemRoute = $orderItemRoute === null
            ? null
            : static::requirePersisted($orderItemRoute, 'supplier order item route');
        $productMapping = $productMapping === null
            ? null
            : static::requirePersisted($productMapping, 'supplier product mapping');
        $order = $order === null ? null : static::requirePersisted($order, 'order');
        $orderItem = $orderItem === null ? null : static::requirePersisted($orderItem, 'order item');
        $invoice = $invoice === null ? null : static::requirePersisted($invoice, 'invoice');
        $service = $service === null ? null : static::requirePersisted($service, 'service');
        $serviceLink = $serviceLink === null
            ? null
            : static::requirePersisted($serviceLink, 'supplier service link');
        $invoiceLink = $invoiceLink === null
            ? null
            : static::requirePersisted($invoiceLink, 'supplier invoice link');

        static::validateReferences(
            $account,
            $attributes['action'] ?? null,
            $orderItemRoute,
            $productMapping,
            $order,
            $orderItem,
            $invoice,
            $service,
            $serviceLink,
            $invoiceLink,
        );

        $requestPayload = $attributes['request_payload'] ?? [];
        if (! is_array($requestPayload)) {
            throw new DomainException('The supplier operation request payload must be an array.');
        }

        try {
            $requestHash = hash('sha256', json_encode(
                $requestPayload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } catch (JsonException $exception) {
            throw new DomainException('The supplier operation request payload is not JSON encodable.', 0, $exception);
        }

        if (isset($attributes['request_hash'])
            && (! is_string($attributes['request_hash']) || ! hash_equals($requestHash, strtolower($attributes['request_hash'])))) {
            throw new DomainException('The supplier operation request hash does not match its payload.');
        }
        $attributes['request_hash'] = $requestHash;
        $attributes['request_payload'] = $requestPayload;

        $operation = new static($attributes);
        $operation->account()->associate($account);
        $operation->orderItemRoute()->associate($orderItemRoute);
        $operation->productMapping()->associate($productMapping);
        $operation->order()->associate($order);
        $operation->orderItem()->associate($orderItem);
        $operation->invoice()->associate($invoice);
        $operation->service()->associate($service);
        $operation->serviceLink()->associate($serviceLink);
        $operation->invoiceLink()->associate($invoiceLink);
        $operation->save();

        return $operation;
    }

    protected function casts(): array
    {
        return [
            'request_payload' => 'encrypted:array',
            'response_payload' => 'encrypted:array',
            'attempts' => 'integer',
            'metadata' => 'array',
            'available_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function account()
    {
        return $this->belongsTo(SupplierAccount::class, 'supplier_account_id');
    }

    public function serviceLink()
    {
        return $this->belongsTo(SupplierServiceLink::class, 'supplier_service_link_id');
    }

    public function invoiceLink()
    {
        return $this->belongsTo(SupplierInvoiceLink::class, 'supplier_invoice_link_id');
    }

    public function productMapping()
    {
        return $this->belongsTo(SupplierProductMapping::class, 'supplier_product_mapping_id');
    }

    public function orderItemRoute()
    {
        return $this->belongsTo(SupplierOrderItemRoute::class, 'supplier_order_item_route_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    protected function supplierOwnedRelations(): array
    {
        return [
            'orderItemRoute' => 'supplier_order_item_route_id',
            'productMapping' => 'supplier_product_mapping_id',
            'serviceLink' => 'supplier_service_link_id',
            'invoiceLink' => 'supplier_invoice_link_id',
        ];
    }

    private static function validateReferences(
        SupplierAccount $account,
        mixed $action,
        ?SupplierOrderItemRoute $orderItemRoute,
        ?SupplierProductMapping $productMapping,
        ?Order $order,
        ?OrderItem $orderItem,
        ?Invoice $invoice,
        ?Service $service,
        ?SupplierServiceLink $serviceLink,
        ?SupplierInvoiceLink $invoiceLink,
    ): void {
        foreach ([$orderItemRoute, $productMapping, $serviceLink, $invoiceLink] as $supplierReference) {
            if ($supplierReference !== null
                && (string) $supplierReference->supplier_account_id !== (string) $account->getKey()) {
                throw new DomainException('Cross-account supplier references are not allowed.');
            }
        }

        if ($orderItemRoute !== null
            && ($productMapping === null
                || $orderItem === null
                || (string) $orderItemRoute->supplier_product_mapping_id !== (string) $productMapping->getKey()
                || (string) $orderItemRoute->order_item_id !== (string) $orderItem->getKey()
                || (string) $orderItemRoute->local_product_id !== (string) $orderItem->product_id
                || (string) $orderItemRoute->local_billing_cycle !== (string) $orderItem->billing_cycle
                || ($service?->product_id !== null
                    && (string) $orderItemRoute->local_product_id !== (string) $service->product_id)
                || ($service !== null
                    && (string) $orderItemRoute->local_billing_cycle !== (string) $service->billing_cycle))) {
            throw new DomainException('The supplier route does not match the operation references.');
        }

        if ($order !== null && $orderItem !== null
            && (string) $orderItem->order_id !== (string) $order->getKey()) {
            throw new DomainException('The order item does not belong to the supplied order.');
        }
        if ($order !== null && $invoice?->order_id !== null
            && (string) $invoice->order_id !== (string) $order->getKey()) {
            throw new DomainException('The invoice does not belong to the supplied order.');
        }
        if ($order !== null && $service?->order_id !== null
            && (string) $service->order_id !== (string) $order->getKey()) {
            throw new DomainException('The service does not belong to the supplied order.');
        }
        if ($orderItem !== null && $service?->order_item_id !== null
            && (string) $service->order_item_id !== (string) $orderItem->getKey()) {
            throw new DomainException('The service does not belong to the supplied order item.');
        }
        if ($orderItem !== null && $service?->order_id !== null
            && (string) $service->order_id !== (string) $orderItem->order_id) {
            throw new DomainException('The service and order item belong to different orders.');
        }
        if ($orderItem !== null && $invoice?->order_id !== null
            && (string) $invoice->order_id !== (string) $orderItem->order_id) {
            throw new DomainException('The invoice and order item belong to different orders.');
        }
        if ($invoice?->order_id !== null && $service?->order_id !== null
            && (string) $invoice->order_id !== (string) $service->order_id) {
            throw new DomainException('The invoice and service belong to different orders.');
        }
        foreach ([[$order, $invoice], [$order, $service], [$invoice, $service]] as [$first, $second]) {
            if ($first !== null && $second !== null
                && (string) $first->user_id !== (string) $second->user_id) {
                throw new DomainException('Local supplier operation references belong to different users.');
            }
        }

        $effectiveServiceLink = $serviceLink ?? $invoiceLink?->serviceLink;
        $effectiveService = $service ?? $effectiveServiceLink?->service;
        if ($effectiveServiceLink !== null && $service !== null
            && (string) $effectiveServiceLink->service_id !== (string) $service->getKey()) {
            throw new DomainException('The supplier service link does not reference the supplied service.');
        }
        if ($invoiceLink !== null && $invoice !== null
            && (string) $invoiceLink->invoice_id !== (string) $invoice->getKey()) {
            throw new DomainException('The supplier invoice link does not reference the supplied invoice.');
        }
        if ($serviceLink !== null && $invoiceLink?->supplier_service_link_id !== null
            && (string) $invoiceLink->supplier_service_link_id !== (string) $serviceLink->getKey()) {
            throw new DomainException('The supplier invoice and service links do not match.');
        }
        if ($productMapping !== null && $effectiveServiceLink?->supplier_product_mapping_id !== null
            && (string) $effectiveServiceLink->supplier_product_mapping_id !== (string) $productMapping->getKey()
            && $action !== self::ACTION_RENEW) {
            throw new DomainException('The supplier service link does not reference the supplied product mapping.');
        }

        if ($orderItemRoute === null
            && $productMapping !== null && $orderItem?->product_id !== null
            && (string) $orderItem->product_id !== (string) $productMapping->product_id) {
            throw new DomainException('The order item does not reference the mapped product.');
        }
        if ($orderItemRoute === null
            && $productMapping !== null && $effectiveService?->product_id !== null
            && (string) $effectiveService->product_id !== (string) $productMapping->product_id) {
            throw new DomainException('The service does not reference the mapped product.');
        }
        if ($productMapping !== null && $orderItem !== null
            && $orderItemRoute === null
            && $productMapping->local_billing_cycle !== $orderItem->billing_cycle) {
            throw new DomainException('The order item does not use the mapped billing cycle.');
        }
        if ($productMapping !== null && $effectiveService !== null
            && $orderItemRoute === null
            && $productMapping->local_billing_cycle !== $effectiveService->billing_cycle
            && $action !== self::ACTION_RENEW) {
            throw new DomainException('The service does not use the mapped billing cycle.');
        }
        if ($orderItem?->product_id !== null && $service?->product_id !== null
            && (string) $orderItem->product_id !== (string) $service->product_id) {
            throw new DomainException('The order item and service reference different products.');
        }
    }
}
