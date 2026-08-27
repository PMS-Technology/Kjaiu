<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SupplierAccount;
use App\Models\SupplierErrorSanitizer;
use App\Models\SupplierOperation;
use App\Services\SupplierProvisioningProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class SupplierOperationController extends Controller
{
    private const STATUS_LABELS = [
        SupplierOperation::STATUS_QUEUED => '排队中',
        SupplierOperation::STATUS_RUNNING => '处理中',
        SupplierOperation::STATUS_AWAITING_CONFIRMATION => '等待确认',
        SupplierOperation::STATUS_BLOCKED_CREDIT => '余额受阻',
        SupplierOperation::STATUS_AMBIGUOUS => '结果不明确',
        SupplierOperation::STATUS_FAILED => '失败',
        SupplierOperation::STATUS_SUCCEEDED => '已完成',
    ];

    private const ACTION_LABELS = [
        SupplierOperation::ACTION_PROVISION => '开通',
        SupplierOperation::ACTION_RENEW => '续费',
        SupplierOperation::ACTION_SUSPEND => '暂停',
        SupplierOperation::ACTION_UNSUSPEND => '恢复',
        SupplierOperation::ACTION_CANCEL => '取消',
        SupplierOperation::ACTION_SYNC => '同步',
    ];

    public function index(Request $request): View
    {
        $status = $this->allowedFilter($request->query('status'), SupplierOperation::STATUSES);
        $action = $this->allowedFilter($request->query('action'), SupplierOperation::ACTIONS);
        $supplierId = $this->positiveInteger($request->query('supplier'));

        $accounts = SupplierAccount::query()
            ->where('driver', SupplierAccount::DRIVER_IDCSMART_FINANCE)
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (SupplierAccount $account): array => [
                'id' => (int) $account->id,
                'name' => $this->safeText(
                    $account->name,
                    $this->credentialValues($account),
                    '供应商名称已隐藏',
                    191,
                ),
            ]);

        $operations = SupplierOperation::query()
            ->select([
                'id',
                'supplier_account_id',
                'supplier_product_mapping_id',
                'supplier_service_link_id',
                'supplier_invoice_link_id',
                'order_id',
                'invoice_id',
                'service_id',
                'action',
                'status',
                'step',
                'last_error_code',
                'last_error',
                'attempts',
                'metadata',
                'response_payload',
                'available_at',
                'started_at',
                'finished_at',
                'created_at',
                'updated_at',
            ])
            ->whereHas('account', fn ($query) => $query
                ->where('driver', SupplierAccount::DRIVER_IDCSMART_FINANCE))
            ->with([
                'account',
                'service:id,status',
                'serviceLink:id,supplier_account_id,supplier_product_mapping_id,service_id,upstream_service_id',
                'invoiceLink:id,supplier_account_id,supplier_service_link_id,invoice_id,upstream_order_id,upstream_invoice_id,upstream_status',
            ])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($action !== '', fn ($query) => $query->where('action', $action))
            ->when($supplierId !== null, fn ($query) => $query
                ->where('supplier_account_id', $supplierId))
            ->latest('id')
            ->paginate(25)
            ->appends(array_filter([
                'status' => $status,
                'supplier' => $supplierId,
                'action' => $action,
            ], fn (mixed $value): bool => $value !== null && $value !== ''));
        $operations->setCollection($operations->getCollection()->map(
            fn (SupplierOperation $operation): array => $this->operationData($operation),
        ));

        return view('admin.supplier-operations.index', [
            'operations' => $operations,
            'accounts' => $accounts,
            'status' => $status,
            'action' => $action,
            'supplierId' => $supplierId,
            'statusLabels' => self::STATUS_LABELS,
            'actionLabels' => self::ACTION_LABELS,
        ]);
    }

    public function resumeCredit(
        Request $request,
        SupplierOperation $supplierOperation,
        SupplierProvisioningProcessor $processor,
    ): RedirectResponse {
        $this->requireSupportedOperation($supplierOperation);
        $auditAction = 'supplier.operation.credit_resume';
        $input = $this->takeRecoveryInput($request);
        $this->authorizeRecovery($request, $supplierOperation, $input, $auditAction);

        return $this->runRecovery(
            $request,
            $supplierOperation,
            $auditAction,
            fn (): bool => $processor->resumeBlockedCredit((int) $supplierOperation->id),
            '上游余额续付已处理，请复核操作的最新状态',
        );
    }

    public function recoverPoll(
        Request $request,
        SupplierOperation $supplierOperation,
        SupplierProvisioningProcessor $processor,
    ): RedirectResponse {
        $this->requireSupportedOperation($supplierOperation);
        $auditAction = 'supplier.operation.poll_recovery';
        $input = $this->takeRecoveryInput($request);
        $this->authorizeRecovery($request, $supplierOperation, $input, $auditAction);

        return $this->runRecovery(
            $request,
            $supplierOperation,
            $auditAction,
            fn (): bool => $processor->recoverPoll((int) $supplierOperation->id),
            '安全轮询已执行，请复核操作和本地服务状态',
        );
    }

    public function reconcileHost(
        Request $request,
        SupplierOperation $supplierOperation,
        SupplierProvisioningProcessor $processor,
    ): RedirectResponse {
        $this->requireSupportedOperation($supplierOperation);
        $auditAction = 'supplier.operation.host_reconciliation';
        $input = $this->takeRecoveryInput($request, true);
        $this->authorizeRecovery($request, $supplierOperation, $input, $auditAction);
        try {
            $hostId = $this->validatedHostId($supplierOperation, $input);
        } catch (ValidationException $exception) {
            $this->recordAudit(
                $request,
                $auditAction,
                $supplierOperation,
                $this->auditState($supplierOperation),
                ['outcome' => 'validation_rejected'] + $this->auditState($supplierOperation),
            );

            throw $exception;
        }

        return $this->runRecovery(
            $request,
            $supplierOperation,
            $auditAction,
            fn (): bool => $processor->reconcileHost((int) $supplierOperation->id, $hostId),
            '上游主机证据已验证并关联，请复核付款证据和操作最新状态',
        );
    }

    public function manualAttestation(
        Request $request,
        SupplierOperation $supplierOperation,
        SupplierProvisioningProcessor $processor,
    ): RedirectResponse {
        $this->requireSupportedOperation($supplierOperation);
        $auditAction = 'supplier.operation.manual_payment_attested';
        $input = $this->takeRecoveryInput($request, true);
        $this->authorizeRecovery($request, $supplierOperation, $input, $auditAction);
        try {
            $hostId = $this->validatedHostId($supplierOperation, $input);
        } catch (ValidationException $exception) {
            $this->recordAudit(
                $request,
                $auditAction,
                $supplierOperation,
                $this->auditState($supplierOperation),
                ['outcome' => 'validation_rejected'] + $this->auditState($supplierOperation),
            );

            throw $exception;
        }

        return $this->runRecovery(
            $request,
            $supplierOperation,
            $auditAction,
            fn (): bool => $processor->attestManualPayment(
                (int) $supplierOperation->id,
                $hostId,
                (int) $request->user()->id,
            ),
            '已验证可读主机并记录人工付款确认；后续只读轮询仅在主机为 Active 时激活服务',
        );
    }

    private function runRecovery(
        Request $request,
        SupplierOperation $operation,
        string $auditAction,
        callable $callback,
        string $successMessage,
    ): RedirectResponse {
        $before = $this->auditState($operation);

        try {
            $processed = $callback();
        } catch (Throwable) {
            $operation->refresh();
            $this->recordAudit($request, $auditAction, $operation, $before, [
                'outcome' => 'failed',
            ] + $this->auditState($operation));

            throw ValidationException::withMessages([
                'operation' => '安全恢复未完成，请检查上游连接、操作状态和关联记录后重试',
            ]);
        }

        $operation->refresh();
        if (! $processed) {
            $this->recordAudit($request, $auditAction, $operation, $before, [
                'outcome' => 'state_rejected',
            ] + $this->auditState($operation));

            throw ValidationException::withMessages([
                'operation' => '操作状态或关联记录已变化，本次安全恢复未执行',
            ]);
        }

        $this->recordAudit($request, $auditAction, $operation, $before, [
            'outcome' => 'processed',
        ] + $this->auditState($operation));

        return redirect()->route('admin.supplier-operations.index')
            ->with('success', $successMessage);
    }

    private function authorizeRecovery(
        Request $request,
        SupplierOperation $operation,
        array $input,
        string $auditAction,
    ): void {
        $confirmed = in_array($input['confirmation'], ['1', 1, true, 'yes', 'on'], true);
        if ($confirmed) {
            return;
        }

        $this->recordAudit(
            $request,
            $auditAction,
            $operation,
            $this->auditState($operation),
            ['outcome' => 'validation_rejected'] + $this->auditState($operation),
        );

        $errors = [];
        if (! $confirmed) {
            $errors['confirmation'] = '请勾选明确确认后再执行安全恢复';
        }
        throw ValidationException::withMessages($errors);
    }

    private function takeRecoveryInput(Request $request, bool $withHostId = false): array
    {
        $input = [
            'confirmation' => $request->input('confirmation'),
            'upstream_host_id' => $withHostId ? $request->input('upstream_host_id') : null,
        ];
        $request->replace(array_filter([
            '_token' => $request->input('_token'),
            '_form' => $request->input('_form'),
        ], fn (mixed $value): bool => is_string($value) && $value !== ''));

        return $input;
    }

    private function validatedHostId(SupplierOperation $operation, array $input): string
    {
        $hostId = $input['upstream_host_id'];
        if (! is_string($hostId) && ! is_int($hostId)) {
            throw ValidationException::withMessages([
                'upstream_host_id' => '请输入有效的上游主机 ID',
            ]);
        }
        $hostId = trim((string) $hostId);
        $sensitive = $this->credentialValues($operation->account);
        $containsSensitiveValue = collect($sensitive)->contains(
            fn (string $value): bool => hash_equals($hostId, $value)
                || (strlen($value) >= 8 && str_contains($hostId, $value)),
        );

        if ($hostId === ''
            || $hostId === '0'
            || strlen($hostId) > 128
            || preg_match('/[\x00-\x1f\x7f]/', $hostId)
            || preg_match('/[^\x20-\x7e]/', $hostId)
            || preg_match('/eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)?/', $hostId)
            || $containsSensitiveValue) {
            throw ValidationException::withMessages([
                'upstream_host_id' => '请输入有效且不含凭据的上游主机 ID',
            ]);
        }

        return $hostId;
    }

    private function operationData(SupplierOperation $operation): array
    {
        $credentials = $this->credentialValues($operation->account);
        $service = $operation->service;
        $serviceLink = $operation->serviceLink;
        $invoiceLink = $operation->invoiceLink;
        $serviceLinkIsConsistent = $serviceLink !== null
            && (string) $serviceLink->supplier_account_id === (string) $operation->supplier_account_id
            && (string) $serviceLink->service_id === (string) $operation->service_id;
        $invoiceLinkIsConsistent = $invoiceLink !== null
            && (string) $invoiceLink->supplier_account_id === (string) $operation->supplier_account_id
            && (string) $invoiceLink->invoice_id === (string) $operation->invoice_id;
        $status = in_array($operation->status, SupplierOperation::STATUSES, true)
            ? $operation->status
            : 'unknown';
        $action = in_array($operation->action, SupplierOperation::ACTIONS, true)
            ? $operation->action
            : 'unknown';
        $accountIsActive = (bool) $operation->account?->is_active;
        $allowsLegacyCredit = $operation->account?->allowsLegacyUnboundedCreditPayment() === true;
        $paymentIsConfirmed = $this->paymentIsConfirmed($operation, $invoiceLinkIsConsistent
            ? $invoiceLink
            : null);

        return [
            'id' => (int) $operation->id,
            'supplier_name' => $this->safeText(
                $operation->account?->name,
                $credentials,
                '供应商名称已隐藏',
                191,
            ),
            'service_id' => $operation->service_id === null ? null : (int) $operation->service_id,
            'invoice_id' => $operation->invoice_id === null ? null : (int) $operation->invoice_id,
            'order_id' => $operation->order_id === null ? null : (int) $operation->order_id,
            'action' => $action,
            'action_label' => self::ACTION_LABELS[$action] ?? '未知动作',
            'status' => $status,
            'status_label' => self::STATUS_LABELS[$status] ?? '未知状态',
            'status_class' => $this->statusClass($status),
            'step' => $this->safeCode($operation->step, 64, '—', $credentials),
            'attempts' => max(0, (int) $operation->attempts),
            'upstream_host_id' => $serviceLinkIsConsistent
                ? $this->safeReference($serviceLink->upstream_service_id, $credentials)
                : '—',
            'upstream_invoice_id' => $invoiceLinkIsConsistent
                ? $this->safeReference($invoiceLink->upstream_invoice_id, $credentials)
                : '—',
            'upstream_order_id' => $invoiceLinkIsConsistent
                ? $this->safeReference($invoiceLink->upstream_order_id, $credentials)
                : '—',
            'error_code' => $this->safeCode(
                $operation->last_error_code,
                64,
                '—',
                $credentials,
            ),
            'error_message' => $this->safeText(
                $operation->last_error,
                $credentials,
                '—',
                600,
            ),
            'available_at' => $operation->available_at?->format('Y-m-d H:i:s'),
            'started_at' => $operation->started_at?->format('Y-m-d H:i:s'),
            'finished_at' => $operation->finished_at?->format('Y-m-d H:i:s'),
            'created_at' => $operation->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $operation->updated_at?->format('Y-m-d H:i:s'),
            'can_resume_credit' => $accountIsActive
                && $allowsLegacyCredit
                && $action === SupplierOperation::ACTION_PROVISION
                && $status === SupplierOperation::STATUS_BLOCKED_CREDIT
                && $operation->step === 'blocked_credit'
                && $operation->last_error_code === 'upstream_credit_insufficient'
                && $invoiceLinkIsConsistent
                && ! $paymentIsConfirmed
                && ! $this->hasPaymentConfirmationMarker($operation)
                && $this->validReference($invoiceLink?->upstream_invoice_id)
                && strtolower((string) $invoiceLink?->upstream_status) === 'unpaid'
                && $service?->status === 'Pending'
                && $operation->supplier_product_mapping_id !== null,
            'can_attest_payment' => $accountIsActive
                && $action === SupplierOperation::ACTION_PROVISION
                && $status === SupplierOperation::STATUS_BLOCKED_CREDIT
                && $operation->step === 'awaiting_manual_supplier_payment'
                && $invoiceLinkIsConsistent
                && ! $paymentIsConfirmed
                && ! $this->hasPaymentConfirmationMarker($operation)
                && $this->validReference($invoiceLink?->upstream_invoice_id)
                && strtolower((string) $invoiceLink?->upstream_status) === 'unpaid'
                && $service?->status === 'Pending'
                && $operation->supplier_product_mapping_id !== null,
            'can_recover_poll' => $accountIsActive
                && $action === SupplierOperation::ACTION_PROVISION
                && $serviceLinkIsConsistent
                && $paymentIsConfirmed
                && in_array($service?->status, ['Pending', 'Failed', 'Suspended'], true)
                && ($status === SupplierOperation::STATUS_FAILED
                    || ($service?->status === 'Suspended'
                        && in_array($status, [
                            SupplierOperation::STATUS_AWAITING_CONFIRMATION,
                            SupplierOperation::STATUS_AMBIGUOUS,
                            SupplierOperation::STATUS_SUCCEEDED,
                        ], true))),
            'can_reconcile_host' => $accountIsActive
                && $action === SupplierOperation::ACTION_PROVISION
                && in_array($status, [
                    SupplierOperation::STATUS_BLOCKED_CREDIT,
                    SupplierOperation::STATUS_AMBIGUOUS,
                    SupplierOperation::STATUS_FAILED,
                ], true)
                && in_array($service?->status, ['Pending', 'Failed', 'Suspended'], true)
                && $operation->supplier_product_mapping_id !== null,
        ];
    }

    private function safeText(mixed $value, array $sensitive, string $fallback, int $limit): string
    {
        if (! is_string($value) && ! is_int($value)) {
            return $fallback;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return $fallback;
        }
        foreach ($sensitive as $secret) {
            if ($secret !== '' && str_contains($value, $secret)) {
                return $fallback;
            }
        }

        $value = SupplierErrorSanitizer::sanitize($value, [['password' => $sensitive]]) ?? '';
        $value = preg_replace(
            '/eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)?/',
            '[REDACTED]',
            $value,
        ) ?? '';
        $value = preg_replace('/[0-9a-f]{32}/i', '[REDACTED]', $value) ?? '';

        return $value === '' ? $fallback : mb_substr($value, 0, $limit);
    }

    private function safeReference(mixed $value, array $sensitive): string
    {
        if (! $this->validReference($value)) {
            return '—';
        }
        $value = trim((string) $value);
        foreach ($sensitive as $secret) {
            if ($secret !== '' && str_contains($value, $secret)) {
                return '已安全隐藏';
            }
        }
        if (preg_match('/eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)?/', $value)
            || preg_match('/[0-9a-f]{32}/i', $value)) {
            return '已安全隐藏';
        }

        $sanitized = SupplierErrorSanitizer::sanitize($value, [['password' => $sensitive]]);
        if ($sanitized === null || ! hash_equals($value, $sanitized)) {
            return '已安全隐藏';
        }

        return $value;
    }

    private function validReference(mixed $value): bool
    {
        if (! is_string($value) && ! is_int($value)) {
            return false;
        }
        $value = trim((string) $value);

        return $value !== ''
            && $value !== '0'
            && strlen($value) <= 128
            && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;
    }

    private function paymentIsConfirmed(
        SupplierOperation $operation,
        mixed $invoiceLink,
    ): bool {
        $metadata = is_array($operation->metadata) ? $operation->metadata : [];
        $paymentInvoiceId = $metadata['payment_invoice_id'] ?? null;
        $ownedInvoice = $invoiceLink !== null
            && (string) $operation->supplier_invoice_link_id === (string) $invoiceLink->id
            && (string) $operation->supplier_account_id === (string) $invoiceLink->supplier_account_id
            && (string) $operation->invoice_id === (string) $invoiceLink->invoice_id
            && $this->validReference($paymentInvoiceId)
            && $this->validReference($invoiceLink->upstream_invoice_id)
            && hash_equals(
                trim((string) $invoiceLink->upstream_invoice_id),
                trim((string) $paymentInvoiceId),
            )
            && strtolower((string) $invoiceLink->upstream_status) === 'paid';
        if (! $ownedInvoice) {
            return false;
        }

        if (! array_key_exists('payment_confirmation', $metadata)) {
            return ($metadata['payment_confirmed'] ?? null) === true
                && ($metadata['payment_application_status'] ?? null) === 1001
                && ! array_key_exists('payment_confirmed_by', $metadata)
                && ! array_key_exists('payment_confirmed_at', $metadata)
                && ! array_key_exists('payment_host_id', $metadata);
        }

        $serviceLink = $operation->serviceLink;
        $confirmedBy = $metadata['payment_confirmed_by'] ?? null;
        $confirmedAt = $metadata['payment_confirmed_at'] ?? null;
        $paymentHostId = $metadata['payment_host_id'] ?? null;

        return ($metadata['payment_confirmed'] ?? null) === true
            && ($metadata['payment_confirmation'] ?? null) === 'admin_attested'
            && ! array_key_exists('payment_application_status', $metadata)
            && is_int($confirmedBy)
            && $confirmedBy > 0
            && is_string($confirmedAt)
            && trim($confirmedAt) !== ''
            && $this->validReference($paymentHostId)
            && $serviceLink !== null
            && (string) $operation->supplier_service_link_id === (string) $serviceLink->id
            && (string) $operation->supplier_account_id === (string) $serviceLink->supplier_account_id
            && (string) $operation->service_id === (string) $serviceLink->service_id
            && hash_equals(
                trim((string) $serviceLink->upstream_service_id),
                trim((string) $paymentHostId),
            );
    }

    private function hasPaymentConfirmationMarker(SupplierOperation $operation): bool
    {
        $metadata = is_array($operation->metadata) ? $operation->metadata : [];
        $response = is_array($operation->response_payload) ? $operation->response_payload : [];

        return array_key_exists('payment_confirmed', $metadata)
            || array_key_exists('payment_application_status', $metadata)
            || array_key_exists('payment_invoice_id', $metadata)
            || array_key_exists('payment_confirmation', $metadata)
            || array_key_exists('payment_confirmed_by', $metadata)
            || array_key_exists('payment_host_id', $metadata)
            || array_key_exists('payment_confirmed_at', $metadata)
            || (in_array($response['endpoint'] ?? null, ['apply_credit', 'apply_credit_recovery'], true)
                && ($response['status'] ?? null) === 1001);
    }

    private function safeCode(
        mixed $value,
        int $limit,
        string $fallback,
        array $sensitive = [],
    ): string {
        if (! is_string($value)) {
            return $fallback;
        }
        $value = trim($value);

        foreach ($sensitive as $secret) {
            if ($secret !== '' && str_contains($value, $secret)) {
                return $fallback;
            }
        }

        return $value !== ''
            && strlen($value) <= $limit
            && preg_match('/\A[A-Za-z0-9_.-]+\z/', $value) === 1
            && preg_match('/[0-9a-f]{32}/i', $value) !== 1
            && preg_match('/eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)?/', $value) !== 1
                ? $value
                : $fallback;
    }

    private function credentialValues(?SupplierAccount $account): array
    {
        if ($account === null) {
            return [];
        }

        try {
            $credentials = $account->credentials;
        } catch (Throwable) {
            return [];
        }
        if (! is_array($credentials)) {
            return [];
        }

        $values = [];
        array_walk_recursive($credentials, function (mixed $value) use (&$values): void {
            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        });

        return array_values(array_unique($values));
    }

    private function allowedFilter(mixed $value, array $allowed): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : '';
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $value === false ? null : $value;
    }

    private function statusClass(string $status): string
    {
        return match ($status) {
            SupplierOperation::STATUS_SUCCEEDED => 'status-active',
            SupplierOperation::STATUS_FAILED,
            SupplierOperation::STATUS_AMBIGUOUS => 'status-failed',
            SupplierOperation::STATUS_BLOCKED_CREDIT => 'status-unpaid',
            SupplierOperation::STATUS_QUEUED,
            SupplierOperation::STATUS_RUNNING,
            SupplierOperation::STATUS_AWAITING_CONFIRMATION => 'status-pending',
            default => 'status-suspended',
        };
    }

    private function requireSupportedOperation(SupplierOperation $operation): void
    {
        abort_unless(
            $operation->account?->driver === SupplierAccount::DRIVER_IDCSMART_FINANCE,
            404,
        );
    }

    private function auditState(SupplierOperation $operation): array
    {
        $metadata = is_array($operation->metadata) ? $operation->metadata : [];
        $serviceStatus = $operation->service()->value('status');
        $serviceLink = $operation->serviceLink()->first(['id', 'upstream_status']);
        $invoiceLink = $operation->invoiceLink()->first(['id', 'upstream_status']);

        return [
            'operation_id' => (int) $operation->id,
            'supplier_account_id' => (int) $operation->supplier_account_id,
            'service_id' => $operation->service_id === null ? null : (int) $operation->service_id,
            'invoice_id' => $operation->invoice_id === null ? null : (int) $operation->invoice_id,
            'supplier_service_link_id' => $serviceLink?->id === null
                ? null
                : (int) $serviceLink->id,
            'supplier_invoice_link_id' => $invoiceLink?->id === null
                ? null
                : (int) $invoiceLink->id,
            'status' => in_array($operation->status, SupplierOperation::STATUSES, true)
                ? $operation->status
                : 'unknown',
            'action' => in_array($operation->action, SupplierOperation::ACTIONS, true)
                ? $operation->action
                : 'unknown',
            'step' => $this->safeCode($operation->step, 64, 'unknown'),
            'local_service_status' => $this->safeCode($serviceStatus, 32, 'unknown'),
            'upstream_host_status' => $this->safeCode(
                $serviceLink?->upstream_status,
                32,
                'unknown',
            ),
            'upstream_invoice_status' => $this->safeCode(
                $invoiceLink?->upstream_status,
                32,
                'unknown',
            ),
        ] + array_filter([
            'payment_confirmed' => ($metadata['payment_confirmed'] ?? null) === true
                ? true
                : null,
            'payment_confirmation' => ($metadata['payment_confirmation'] ?? null) === 'admin_attested'
                ? 'admin_attested'
                : null,
            'payment_confirmed_by' => is_int($metadata['payment_confirmed_by'] ?? null)
                ? $metadata['payment_confirmed_by']
                : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function recordAudit(
        Request $request,
        string $action,
        SupplierOperation $operation,
        ?array $before,
        ?array $after,
    ): void {
        $auditRequest = clone $request;
        $auditRequest->headers->set('User-Agent', '[REDACTED]');
        AuditLog::record($auditRequest, $action, $operation, $before, $after);
    }
}
