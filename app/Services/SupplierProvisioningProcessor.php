<?php

namespace App\Services;

use App\Integrations\Idcsmart\FinanceClientFactory;
use App\Integrations\Idcsmart\FinanceException;
use App\Integrations\Idcsmart\FinanceResponse;
use App\Models\AuditLog;
use App\Models\Service;
use App\Models\SupplierErrorSanitizer;
use App\Models\SupplierInvoiceLink;
use App\Models\SupplierOperation;
use App\Models\SupplierOrderItemRoute;
use App\Models\SupplierServiceLink;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Throwable;
use UnexpectedValueException;

class SupplierProvisioningProcessor
{
    public const STALE_RUNNING_MINUTES = 15;

    public const MAX_PREFLIGHT_ATTEMPTS = 3;

    public const PREFLIGHT_BACKOFF_SECONDS = 60;

    private const MAX_DATABASE_AMOUNT_MINOR = 999_999_999_999_999_999;

    private const ACCOUNT_LOCK_SECONDS = 900;

    private const MIN_UPSTREAM_TIMESTAMP = 946684800;

    private const MAX_UPSTREAM_TIMESTAMP = 4102444800;

    private const MAX_POLL_ATTEMPTS = 10;

    private const PREFLIGHT_STEPS = [
        'claimed',
        'preflight',
        'validation',
        'quote_validated',
        'preflight_retry_backoff',
        'preflight_retry_exhausted',
    ];

    public function __construct(
        private readonly FinanceClientFactory $clients,
    ) {}

    public function processQueued(int $limit = 20): array
    {
        $operations = SupplierOperation::query()
            ->where('action', SupplierOperation::ACTION_PROVISION)
            ->where('status', SupplierOperation::STATUS_QUEUED)
            ->where(fn ($query) => $query
                ->whereNull('available_at')
                ->orWhere('available_at', '<=', now()))
            ->orderBy('id')
            ->limit($this->limit($limit))
            ->get(['id', 'supplier_account_id']);
        $processed = 0;

        foreach ($operations as $operation) {
            $processed += (int) $this->withAccountLock(
                (int) $operation->supplier_account_id,
                fn (): bool => $this->processLocked((int) $operation->id),
            );
        }

        return ['selected' => $operations->count(), 'processed' => $processed];
    }

    public function pollAwaiting(int $limit = 50): array
    {
        $operations = SupplierOperation::query()
            ->where('action', SupplierOperation::ACTION_PROVISION)
            ->where('status', SupplierOperation::STATUS_AWAITING_CONFIRMATION)
            ->where(fn ($query) => $query
                ->whereNull('available_at')
                ->orWhere('available_at', '<=', now()))
            ->orderBy('id')
            ->limit($this->limit($limit))
            ->get(['id', 'supplier_account_id']);
        $polled = 0;

        foreach ($operations as $operation) {
            $polled += (int) $this->withAccountLock(
                (int) $operation->supplier_account_id,
                fn (): bool => $this->pollLocked((int) $operation->id),
            );
        }

        return ['selected' => $operations->count(), 'polled' => $polled];
    }

    public function recoverStaleRunning(int $limit = 100): array
    {
        $cutoff = now()->subMinutes(self::STALE_RUNNING_MINUTES);
        $operations = SupplierOperation::query()
            ->where('action', SupplierOperation::ACTION_PROVISION)
            ->where('status', SupplierOperation::STATUS_RUNNING)
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($this->limit($limit))
            ->get(['id', 'supplier_account_id']);
        $counts = [
            'selected' => $operations->count(),
            'requeued' => 0,
            'awaiting_confirmation' => 0,
            'ambiguous' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        foreach ($operations as $operation) {
            $outcome = null;
            try {
                $recovered = $this->withAccountLock(
                    (int) $operation->supplier_account_id,
                    function () use ($operation, $cutoff, &$outcome): bool {
                        $outcome = $this->recoverStaleRunningLocked((int) $operation->id, $cutoff);

                        return $outcome !== null;
                    },
                );
            } catch (Throwable $exception) {
                logger()->warning('Stale supplier operation recovery failed.', [
                    'operation_id' => (int) $operation->id,
                    'exception' => class_basename($exception),
                ]);
                $counts['errors']++;

                continue;
            }
            if (! $recovered || ! is_string($outcome) || ! array_key_exists($outcome, $counts)) {
                $counts['skipped']++;

                continue;
            }

            $counts[$outcome]++;
        }

        return $counts;
    }

    public function reconcileUnsupportedRenewals(int $limit = 500): array
    {
        $operationIds = SupplierOperation::query()
            ->where('action', SupplierOperation::ACTION_RENEW)
            ->where('status', SupplierOperation::STATUS_QUEUED)
            ->orderBy('id')
            ->limit($this->limit($limit))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $counts = [
            'selected' => count($operationIds),
            'reconciled' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        foreach ($operationIds as $operationId) {
            try {
                $reconciled = DB::transaction(function () use ($operationId): bool {
                    $operation = SupplierOperation::query()->lockForUpdate()->find($operationId);
                    if ($operation === null
                        || $operation->action !== SupplierOperation::ACTION_RENEW
                        || $operation->status !== SupplierOperation::STATUS_QUEUED) {
                        return false;
                    }

                    $operation->update([
                        'status' => SupplierOperation::STATUS_FAILED,
                        'step' => 'unsupported_supplier_renewal',
                        'last_error_code' => 'unsupported_supplier_renewal',
                        'last_error' => 'Supplier renewal is not supported by this release.',
                        'available_at' => null,
                        'finished_at' => now(),
                    ]);
                    $this->audit($operation, 'supplier.renewal.unsupported_reconciled', [
                        'error_code' => 'unsupported_supplier_renewal',
                    ]);

                    return true;
                }, 3);
            } catch (Throwable $exception) {
                logger()->warning('Unsupported supplier renewal reconciliation failed.', [
                    'operation_id' => $operationId,
                    'exception' => class_basename($exception),
                ]);
                $counts['errors']++;

                continue;
            }

            $counts[$reconciled ? 'reconciled' : 'skipped']++;
        }

        return $counts;
    }

    public function process(int $operationId): bool
    {
        $accountId = SupplierOperation::query()
            ->whereKey($operationId)
            ->where('action', SupplierOperation::ACTION_PROVISION)
            ->where('status', SupplierOperation::STATUS_QUEUED)
            ->value('supplier_account_id');

        return $accountId !== null
            && $this->withAccountLock((int) $accountId, fn (): bool => $this->processLocked($operationId));
    }

    public function poll(int $operationId): bool
    {
        $accountId = SupplierOperation::query()
            ->whereKey($operationId)
            ->where('action', SupplierOperation::ACTION_PROVISION)
            ->where('status', SupplierOperation::STATUS_AWAITING_CONFIRMATION)
            ->value('supplier_account_id');

        return $accountId !== null
            && $this->withAccountLock((int) $accountId, fn (): bool => $this->pollLocked($operationId));
    }

    public function resumeBlockedCredit(int $operationId): bool
    {
        $accountId = SupplierOperation::query()
            ->whereKey($operationId)
            ->where('action', SupplierOperation::ACTION_PROVISION)
            ->where('status', SupplierOperation::STATUS_BLOCKED_CREDIT)
            ->value('supplier_account_id');

        return $accountId !== null
            && $this->withAccountLock(
                (int) $accountId,
                fn (): bool => $this->resumeBlockedCreditLocked($operationId),
            );
    }

    public function recoverPoll(int $operationId): bool
    {
        $accountId = SupplierOperation::query()
            ->whereKey($operationId)
            ->where('action', SupplierOperation::ACTION_PROVISION)
            ->value('supplier_account_id');

        return $accountId !== null
            && $this->withAccountLock(
                (int) $accountId,
                fn (): bool => $this->recoverPollLocked($operationId),
            );
    }

    public function reconcileHost(int $operationId, string|int $hostId): bool
    {
        $hostId = $this->recoveryHostIdentifier($hostId);
        $accountId = SupplierOperation::query()
            ->whereKey($operationId)
            ->where('action', SupplierOperation::ACTION_PROVISION)
            ->whereIn('status', [
                SupplierOperation::STATUS_BLOCKED_CREDIT,
                SupplierOperation::STATUS_AMBIGUOUS,
                SupplierOperation::STATUS_FAILED,
            ])
            ->value('supplier_account_id');

        return $accountId !== null
            && $this->withAccountLock(
                (int) $accountId,
                fn (): bool => $this->reconcileHostLocked($operationId, $hostId),
            );
    }

    public function attestManualPayment(
        int $operationId,
        string|int $hostId,
        int $administratorId,
    ): bool {
        $hostId = $this->recoveryHostIdentifier($hostId);
        if ($administratorId < 1) {
            throw new InvalidArgumentException('A valid administrator is required for payment attestation.');
        }
        $accountId = SupplierOperation::query()
            ->whereKey($operationId)
            ->where('action', SupplierOperation::ACTION_PROVISION)
            ->where('status', SupplierOperation::STATUS_BLOCKED_CREDIT)
            ->value('supplier_account_id');

        return $accountId !== null
            && $this->withAccountLock(
                (int) $accountId,
                fn (): bool => $this->reconcileHostLocked(
                    $operationId,
                    $hostId,
                    $administratorId,
                ),
            );
    }

    private function processLocked(int $operationId): bool
    {
        $operation = $this->claim($operationId);
        if ($operation === null) {
            return false;
        }

        $claimAttempt = (int) $operation->attempts;
        $mutationAttempted = false;
        $preflightPhase = 'snapshot_validation';
        try {
            $payload = $this->validateProvisionSnapshot($operation);
            $upstream = $payload['route']['upstream'];
            $preflightPhase = 'client_construction';
            $client = $this->clients->make($operation->account);
            $correlation = $payload['correlation'];
            $quoteParameters = [
                'pid' => $upstream['product_id'],
                'billingcycle' => $upstream['billing_cycle'],
                'qty' => 1,
                'configoption' => $upstream['configoption'],
            ];
            $preflightPhase = 'set_config';
            $configuration = $client->setConfig([
                'pid' => $upstream['product_id'],
                'billingcycle' => $upstream['billing_cycle'],
            ]);
            $preflightPhase = 'quote';
            $quote = $client->quote($quoteParameters);
            $preflightPhase = 'quote_validation';
            $quoteEvidence = $this->validateQuote($payload['route'], $quote, $quoteParameters);
            $metadata = is_array($operation->metadata) ? $operation->metadata : [];
            $metadata['quote_evidence'] = $quoteEvidence + [
                'set_config_status' => $configuration->status,
            ];
            $metadata['quote_hash'] = hash('sha256', json_encode(
                $metadata['quote_evidence'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
            $preflightPhase = 'quote_checkpoint';
            $this->checkpoint(
                $operation->id,
                $claimAttempt,
                'quote_validated',
                ['metadata' => $metadata],
                true,
            );

            $preflightPhase = 'mutation_checkpoint';
            $clear = $this->mutate(
                $operation->id,
                'clear_cart',
                fn (): FinanceResponse => $client->clearCart($correlation),
                $payload,
                $mutationAttempted,
                $claimAttempt,
            );
            $preflightPhase = 'mutation';
            [$invoiceId, $hostId] = $this->references($clear);
            if ($invoiceId !== null || $hostId !== null) {
                $this->persistReferences(
                    $operation->id,
                    $invoiceId,
                    $hostId,
                    'clear_cart',
                    $clear,
                    'Unpaid',
                    $claimAttempt,
                );
            } elseif ($clear->status === 400) {
                $this->finishFailed(
                    $operation->id,
                    'clear_cart_rejected',
                    $clear->message ?: 'The supplier rejected cart recovery.',
                    $claimAttempt,
                );

                return true;
            } else {
                $this->mutate(
                    $operation->id,
                    'add_to_cart',
                    fn (): FinanceResponse => $client->addToCart($correlation + [
                        'pid' => $upstream['product_id'],
                        'billingcycle' => $upstream['billing_cycle'],
                        'qty' => 1,
                        'configoption' => $upstream['configoption'],
                    ]),
                    $payload,
                    $mutationAttempted,
                    $claimAttempt,
                );
                $settlement = $this->mutate(
                    $operation->id,
                    'settle_cart',
                    fn (): FinanceResponse => $client->settleCart($correlation),
                    $payload,
                    $mutationAttempted,
                    $claimAttempt,
                );
                [$invoiceId, $hostId] = $this->references($settlement);
                $this->persistReferences(
                    $operation->id,
                    $invoiceId,
                    $hostId,
                    'settle_cart',
                    $settlement,
                    'Unpaid',
                    $claimAttempt,
                );
            }

            if ($invoiceId !== null && ! $this->legacyCreditPaymentIsAllowed(
                $operation->id,
                $claimAttempt,
            )) {
                $this->finishManualPaymentReview($operation->id, $claimAttempt);

                return true;
            }

            if ($invoiceId !== null) {
                $credit = $this->mutate(
                    $operation->id,
                    'apply_credit',
                    fn (): FinanceResponse => $client->applyCredit([
                        'invoiceid' => $invoiceId,
                        'use_credit' => 1,
                        'enough' => 1,
                    ]),
                    $payload,
                    $mutationAttempted,
                    $claimAttempt,
                );
                [$creditInvoiceId, $creditHostId] = $this->references($credit);
                if ($creditInvoiceId !== null && $creditInvoiceId !== $invoiceId) {
                    throw new DomainException('The supplier credit response referenced another invoice.');
                }
                if ($creditHostId !== null && $hostId !== null && $creditHostId !== $hostId) {
                    throw new DomainException('The supplier credit response referenced another host.');
                }
                $hostId ??= $creditHostId;
                $this->persistReferences(
                    $operation->id,
                    $invoiceId,
                    $hostId,
                    'apply_credit',
                    $credit,
                    'Paid',
                    $claimAttempt,
                    true,
                );

                if ($credit->status !== 1001) {
                    $this->finishAmbiguous(
                        $operation->id,
                        'credit_outcome_unknown',
                        'The supplier credit response did not explicitly confirm payment or insufficient credit.',
                        $claimAttempt,
                    );

                    return true;
                }
            } else {
                $this->finishAmbiguous(
                    $operation->id,
                    'payment_unconfirmed',
                    'The supplier returned a host without an invoice that can be explicitly paid.',
                    $claimAttempt,
                );

                return true;
            }

            if ($hostId === null) {
                $this->finishAmbiguous(
                    $operation->id,
                    'host_reconciliation_required',
                    'Supplier payment was confirmed, but no host identifier is available.',
                    $claimAttempt,
                );

                return true;
            }

            $this->awaitConfirmation($operation->id, $claimAttempt);
        } catch (FinanceException $exception) {
            if (! $this->claimIsCurrent($operation->id, $claimAttempt)) {
                return true;
            }
            $endpoint = $exception->safeContext()['endpoint'] ?? null;
            if (! $mutationAttempted
                && $this->retryablePreflightFinanceFailure($exception, $preflightPhase)) {
                $this->deferOrExhaustPreflight(
                    $operation->id,
                    $claimAttempt,
                    $this->safePreflightError($operation, $exception),
                );
            } elseif (! $mutationAttempted && $preflightPhase !== 'snapshot_validation') {
                $this->finishFailed(
                    $operation->id,
                    'quote_preflight_failed',
                    $exception->getMessage(),
                    $claimAttempt,
                );
            } elseif ($endpoint === '/apply_credit'
                && $this->creditIsInsufficient(
                    $exception->applicationStatus(),
                    $exception->getMessage(),
                )) {
                $this->finishBlockedCredit(
                    $operation->id,
                    $exception->getMessage(),
                    $claimAttempt,
                );
            } elseif ($mutationAttempted && $endpoint === '/apply_credit') {
                $this->finishAmbiguous(
                    $operation->id,
                    'credit_outcome_unknown',
                    $exception->getMessage(),
                    $claimAttempt,
                );
            } elseif ($mutationAttempted && $exception->outcomeIsAmbiguous()) {
                $this->finishAmbiguous(
                    $operation->id,
                    'supplier_mutation_ambiguous',
                    $exception->getMessage(),
                    $claimAttempt,
                );
            } else {
                $this->finishFailed(
                    $operation->id,
                    'supplier_rejected',
                    $exception->getMessage(),
                    $claimAttempt,
                );
            }
        } catch (InvalidArgumentException|DomainException $exception) {
            if (! $this->claimIsCurrent($operation->id, $claimAttempt)) {
                return true;
            }
            if (! $mutationAttempted && $preflightPhase !== 'snapshot_validation') {
                $this->finishFailed(
                    $operation->id,
                    'quote_preflight_failed',
                    $exception->getMessage(),
                    $claimAttempt,
                );
            } elseif ($mutationAttempted) {
                $this->finishAmbiguous(
                    $operation->id,
                    'local_failure_after_mutation',
                    $exception->getMessage(),
                    $claimAttempt,
                );
            } else {
                $this->finishFailed(
                    $operation->id,
                    'snapshot_validation_failed',
                    $exception->getMessage(),
                    $claimAttempt,
                );
            }
        } catch (Throwable $exception) {
            if (! $this->claimIsCurrent($operation->id, $claimAttempt)) {
                return true;
            }
            if (! $mutationAttempted && in_array($preflightPhase, [
                'client_construction',
                'set_config',
                'quote',
                'quote_checkpoint',
                'mutation_checkpoint',
            ], true)) {
                $this->deferOrExhaustPreflight(
                    $operation->id,
                    $claimAttempt,
                    $this->safePreflightError($operation, $exception),
                );
            } elseif (! $mutationAttempted && $preflightPhase !== 'snapshot_validation') {
                $this->finishFailed(
                    $operation->id,
                    'quote_preflight_failed',
                    $exception->getMessage(),
                    $claimAttempt,
                );
            } elseif ($mutationAttempted) {
                $this->finishAmbiguous(
                    $operation->id,
                    'unexpected_failure_after_mutation',
                    $exception->getMessage(),
                    $claimAttempt,
                );
            } else {
                $this->finishFailed(
                    $operation->id,
                    'processor_failure',
                    $exception->getMessage(),
                    $claimAttempt,
                );
            }
        }

        return true;
    }

    private function pollLocked(int $operationId): bool
    {
        $claim = $this->claimPoll($operationId);
        if ($claim === null) {
            return false;
        }
        if ($claim['skip_http']) {
            return true;
        }

        $operation = $claim['operation'];
        $attempt = $claim['attempt'];
        try {
            $serviceLink = $operation->serviceLink;
            if ($serviceLink === null
                || (string) $serviceLink->supplier_account_id !== (string) $operation->supplier_account_id) {
                throw new DomainException('The supplier service link is missing or account-inconsistent.');
            }

            $response = $this->clients->make($operation->account)
                ->hostHeader($serviceLink->upstream_service_id);
            $host = $response->data['host_data'];
            $status = is_string($host['domainstatus'] ?? null)
                ? trim($host['domainstatus'])
                : '';

            try {
                $status = $this->validatedHostStatus(
                    $host,
                    (string) $serviceLink->upstream_service_id,
                    true,
                );
            } catch (UnexpectedValueException) {
                $this->finishUnverifiedHostPoll($operation->id);

                return true;
            } catch (DomainException $exception) {
                if ($status !== '') {
                    throw $exception;
                }

                $this->deferOrExhaustPoll(
                    $operation->id,
                    $attempt,
                    'host_status_missing',
                    'The supplier host response did not contain a status.',
                    $this->safeHostResponse($response, $host),
                );

                return true;
            }

            $this->applyHostStatus($operation->id, $attempt, $status, $host, $response);
        } catch (Throwable $exception) {
            $this->deferOrExhaustPoll(
                $operation->id,
                $attempt,
                'host_poll_error',
                $exception->getMessage(),
            );
        }

        return true;
    }

    private function resumeBlockedCreditLocked(int $operationId): bool
    {
        $operation = SupplierOperation::query()->with([
            'account',
            'orderItemRoute',
            'productMapping',
            'order',
            'orderItem',
            'invoice',
            'service',
            'invoiceLink',
        ])->find($operationId);
        if ($operation === null || $this->blockedCreditInvoiceId($operation) === null) {
            return false;
        }

        $client = $this->clients->make($operation->account);
        $claim = DB::transaction(function () use ($operationId): ?array {
            $operation = SupplierOperation::query()->lockForUpdate()->find($operationId);
            if ($operation === null
                || $operation->action !== SupplierOperation::ACTION_PROVISION
                || $operation->status !== SupplierOperation::STATUS_BLOCKED_CREDIT
                || $operation->step !== 'blocked_credit'
                || $operation->last_error_code !== 'upstream_credit_insufficient') {
                return null;
            }
            $this->lockProvisionReferences($operation);
            try {
                $this->validateProvisionSnapshot($operation);
            } catch (InvalidArgumentException|DomainException) {
                return null;
            }
            $account = $operation->account;
            $invoice = $operation->invoice;
            $invoiceLink = $operation->invoiceLink()->lockForUpdate()->first();
            $service = $operation->service;
            $mapping = $operation->productMapping;
            $route = $operation->orderItemRoute;
            $operationServiceLink = $operation->serviceLink()->lockForUpdate()->first();
            $serviceLink = $service === null
                ? null
                : SupplierServiceLink::query()
                    ->where('supplier_account_id', $operation->supplier_account_id)
                    ->where('service_id', $service->id)
                    ->lockForUpdate()
                    ->first();
            $invoiceId = $this->firstIdentifier($invoiceLink?->upstream_invoice_id);
            if ($account === null
                || ! $account->is_active
                || ! $account->allowsLegacyUnboundedCreditPayment()
                || $invoice === null
                || $invoice->status !== 'Paid'
                || $invoiceLink === null
                || $invoiceId === null
                || strtolower((string) $invoiceLink->upstream_status) !== 'unpaid'
                || $service === null
                || $service->status !== 'Pending'
                || $mapping === null
                || $route === null
                || (string) $invoiceLink->supplier_account_id !== (string) $operation->supplier_account_id
                || (string) $invoiceLink->invoice_id !== (string) $invoice->id
                || (string) $mapping->supplier_account_id !== (string) $operation->supplier_account_id
                || (string) $route->supplier_account_id !== (string) $operation->supplier_account_id
                || (string) $route->supplier_product_mapping_id !== (string) $mapping->id
                || (string) $route->local_product_id !== (string) $service->product_id
                || (string) $route->local_billing_cycle !== (string) $service->billing_cycle
                || ! hash_equals(
                    (string) $route->account_identity_hash,
                    SupplierOrderItemRoute::accountIdentityHash($account),
                )
                || ($operationServiceLink !== null
                    && ($serviceLink === null
                        || (string) $operationServiceLink->id !== (string) $serviceLink->id))
                || ($invoiceLink->supplier_service_link_id !== null
                    && ($serviceLink === null
                        || (string) $invoiceLink->supplier_service_link_id !== (string) $serviceLink->id))
                || ($serviceLink !== null
                    && ((string) $serviceLink->supplier_account_id !== (string) $operation->supplier_account_id
                        || (string) $serviceLink->service_id !== (string) $service->id
                        || (string) $serviceLink->supplier_product_mapping_id !== (string) $mapping->id))) {
                return null;
            }
            $knownHostId = $this->firstIdentifier($serviceLink?->upstream_service_id);
            if ($this->hasPaymentConfirmationEvidence($operation, $invoiceLink)) {
                return null;
            }

            $operation->update([
                'status' => SupplierOperation::STATUS_RUNNING,
                'step' => 'apply_credit_recovery_mutation_started',
                'attempts' => $operation->attempts + 1,
                'available_at' => null,
                'started_at' => now(),
                'finished_at' => null,
                'last_error_code' => null,
                'last_error' => null,
            ]);

            return [
                'invoice_id' => $invoiceId,
                'host_id' => $knownHostId,
                'attempt' => (int) $operation->attempts,
            ];
        }, 3);
        if ($claim === null) {
            return false;
        }
        $invoiceId = $claim['invoice_id'];
        $knownHostId = $claim['host_id'];
        $claimAttempt = $claim['attempt'];

        try {
            $response = $client->applyCredit([
                'invoiceid' => $invoiceId,
                'use_credit' => 1,
                'enough' => 1,
            ]);
            [$responseInvoiceId, $responseHostId] = $this->references($response);
            if ($responseInvoiceId !== null && $responseInvoiceId !== $invoiceId) {
                throw new DomainException('The supplier credit response referenced another invoice.');
            }

            if ($responseHostId !== null
                && $knownHostId !== null
                && $responseHostId !== $knownHostId) {
                throw new DomainException('The supplier credit response referenced another host.');
            }
            $hostId = $responseHostId ?? $knownHostId;
            $this->persistReferences(
                $operationId,
                $invoiceId,
                $hostId,
                'apply_credit_recovery',
                $response,
                'Paid',
                $claimAttempt,
                true,
            );

            if ($response->status !== 1001) {
                $this->finishAmbiguous(
                    $operationId,
                    'credit_outcome_unknown',
                    'The supplier credit response did not explicitly confirm payment or insufficient credit.',
                    $claimAttempt,
                );

                return true;
            }
            $hostId === null
                ? $this->finishAmbiguous(
                    $operationId,
                    'host_reconciliation_required',
                    'Supplier payment was confirmed, but no host identifier is available.',
                    $claimAttempt,
                )
                : $this->awaitConfirmation($operationId, $claimAttempt);
        } catch (FinanceException $exception) {
            $endpoint = $exception->safeContext()['endpoint'] ?? null;
            if ($endpoint === '/zjmf_api_login' && ! $exception->outcomeIsAmbiguous()) {
                $this->finishAmbiguous(
                    $operationId,
                    'credit_outcome_unknown',
                    $exception->getMessage(),
                    $claimAttempt,
                );
            } elseif ($endpoint === '/apply_credit'
                && $this->creditIsInsufficient(
                    $exception->applicationStatus(),
                    $exception->getMessage(),
                )) {
                $this->finishBlockedCredit($operationId, $exception->getMessage(), $claimAttempt);
            } elseif ($endpoint === '/apply_credit' || $exception->outcomeIsAmbiguous()) {
                $this->finishAmbiguous(
                    $operationId,
                    'credit_outcome_unknown',
                    $exception->getMessage(),
                    $claimAttempt,
                );
            } else {
                $this->finishFailed(
                    $operationId,
                    'credit_resume_rejected',
                    $exception->getMessage(),
                    $claimAttempt,
                );
            }
        } catch (Throwable $exception) {
            $this->finishAmbiguous(
                $operationId,
                'credit_recovery_local_failure',
                $exception->getMessage(),
                $claimAttempt,
            );
        }

        return true;
    }

    private function recoverPollLocked(int $operationId): bool
    {
        $prepared = DB::transaction(function () use ($operationId): bool {
            $operation = SupplierOperation::query()->lockForUpdate()->find($operationId);
            if ($operation === null
                || $operation->action !== SupplierOperation::ACTION_PROVISION) {
                return false;
            }
            $this->lockProvisionReferences($operation);
            try {
                $this->validateProvisionSnapshot(
                    $operation,
                    ['Pending', 'Failed', 'Suspended'],
                );
            } catch (InvalidArgumentException|DomainException) {
                return false;
            }
            $account = $operation->account;
            $service = $operation->service;
            $serviceLink = $operation->serviceLink()->lockForUpdate()->first();
            $invoiceLink = $operation->invoiceLink()->lockForUpdate()->first();
            if ($account === null
                || ! $account->is_active
                || $service === null
                || $serviceLink === null
                || ! $this->paymentIsConfirmed($operation, $invoiceLink)
                || (string) $serviceLink->supplier_account_id !== (string) $operation->supplier_account_id
                || (string) $serviceLink->service_id !== (string) $service->id
                || ! in_array($service->status, ['Pending', 'Failed', 'Suspended'], true)) {
                return false;
            }

            $recoverableOperation = $operation->status === SupplierOperation::STATUS_FAILED;
            $recoverableService = $service->status === 'Suspended'
                && in_array($operation->status, [
                    SupplierOperation::STATUS_AWAITING_CONFIRMATION,
                    SupplierOperation::STATUS_AMBIGUOUS,
                    SupplierOperation::STATUS_SUCCEEDED,
                ], true);
            if (! $recoverableOperation && ! $recoverableService) {
                return false;
            }

            if (in_array($service->status, ['Failed', 'Suspended'], true)) {
                $service->update(['status' => 'Pending']);
            }
            $metadata = is_array($operation->metadata) ? $operation->metadata : [];
            unset($metadata['poll_attempts']);
            $operation->update([
                'status' => SupplierOperation::STATUS_AWAITING_CONFIRMATION,
                'step' => 'awaiting_confirmation',
                'metadata' => $metadata,
                'available_at' => now(),
                'finished_at' => null,
                'last_error_code' => null,
                'last_error' => null,
            ]);

            return true;
        }, 3);

        return $prepared && $this->pollLocked($operationId);
    }

    private function reconcileHostLocked(
        int $operationId,
        string $hostId,
        ?int $paymentAttestedBy = null,
    ): bool {
        $operation = SupplierOperation::query()->with([
            'account',
            'service',
            'serviceLink',
            'productMapping',
            'orderItemRoute',
            'order',
            'orderItem',
            'invoice',
            'invoiceLink',
        ])->find($operationId);
        if ($paymentAttestedBy === null
            ? ! $this->canReconcileHost($operation)
            : ! $this->canAttestManualPayment($operation)) {
            return false;
        }

        $accountSnapshot = [
            'driver' => $operation->account->getRawOriginal('driver'),
            'base_url' => $operation->account->getRawOriginal('base_url'),
            'credentials' => $operation->account->getRawOriginal('credentials'),
            'options' => $operation->account->getRawOriginal('options'),
            'is_active' => $operation->account->getRawOriginal('is_active'),
        ];
        $response = $this->clients->make($operation->account)->hostHeader($hostId);
        $host = $response->data['host_data'];
        $status = $this->validatedHostStatus($host, $hostId);
        if (in_array(strtolower($status), [
            'failed',
            'cancelled',
            'canceled',
            'deleted',
            'suspended',
            'terminated',
            'fraud',
        ], true)) {
            throw new DomainException('The supplier host is not usable for reconciliation.');
        }
        $safeHost = $this->safeHostData($host);

        return DB::transaction(function () use (
            $operationId,
            $hostId,
            $status,
            $safeHost,
            $response,
            $accountSnapshot,
            $paymentAttestedBy,
        ): bool {
            $operation = SupplierOperation::query()->lockForUpdate()->find($operationId);
            $administrator = $paymentAttestedBy === null
                ? null
                : User::query()->lockForUpdate()->find($paymentAttestedBy);
            $statusIsAllowed = $paymentAttestedBy === null
                ? in_array($operation?->status, [
                    SupplierOperation::STATUS_BLOCKED_CREDIT,
                    SupplierOperation::STATUS_AMBIGUOUS,
                    SupplierOperation::STATUS_FAILED,
                ], true)
                : $operation?->status === SupplierOperation::STATUS_BLOCKED_CREDIT;
            if ($operation === null
                || $operation->action !== SupplierOperation::ACTION_PROVISION
                || $statusIsAllowed === false
                || ($paymentAttestedBy !== null
                    && ($administrator?->status !== 'Active'
                        || $administrator->isAdministrator() === false))) {
                return false;
            }
            $this->lockProvisionReferences($operation);
            try {
                $this->validateProvisionSnapshot(
                    $operation,
                    ['Pending', 'Failed', 'Suspended'],
                );
            } catch (InvalidArgumentException|DomainException) {
                return false;
            }
            $account = $operation->account;
            $service = $operation->service;
            $mapping = $operation->productMapping;
            $route = $operation->orderItemRoute;
            $invoiceLink = $operation->invoiceLink()->lockForUpdate()->first();
            if ($account === null
                || ! $account->is_active
                || ! hash_equals(
                    json_encode($accountSnapshot, JSON_THROW_ON_ERROR),
                    json_encode([
                        'driver' => $account->getRawOriginal('driver'),
                        'base_url' => $account->getRawOriginal('base_url'),
                        'credentials' => $account->getRawOriginal('credentials'),
                        'options' => $account->getRawOriginal('options'),
                        'is_active' => $account->getRawOriginal('is_active'),
                    ], JSON_THROW_ON_ERROR),
                )
                || $service === null
                || $mapping === null
                || $route === null
                || ! in_array($service->status, ['Pending', 'Failed', 'Suspended'], true)
                || (string) $mapping->supplier_account_id !== (string) $operation->supplier_account_id
                || (string) $route->supplier_account_id !== (string) $operation->supplier_account_id
                || (string) $route->supplier_product_mapping_id !== (string) $mapping->id
                || (string) $route->local_product_id !== (string) $service->product_id
                || (string) $route->local_billing_cycle !== (string) $service->billing_cycle
                || ! hash_equals(
                    (string) $route->account_identity_hash,
                    SupplierOrderItemRoute::accountIdentityHash($account),
                )
                || ($paymentAttestedBy !== null
                    && ($operation->step !== 'awaiting_manual_supplier_payment'
                        || $service->status !== 'Pending'
                        || $invoiceLink === null
                        || $this->firstIdentifier($invoiceLink->upstream_invoice_id) === null
                        || strtolower((string) $invoiceLink->upstream_status) !== 'unpaid'
                        || $this->hasPaymentConfirmationEvidence($operation, $invoiceLink)
                        || (string) $operation->supplier_invoice_link_id !== (string) $invoiceLink->id
                        || (string) $invoiceLink->supplier_account_id
                            !== (string) $operation->supplier_account_id
                        || (string) $invoiceLink->invoice_id !== (string) $operation->invoice_id))) {
                return false;
            }

            $source = $paymentAttestedBy === null
                ? 'admin_reconciliation'
                : 'admin_payment_attestation';
            $operationServiceLink = $operation->serviceLink()->lockForUpdate()->first();
            if ($operationServiceLink !== null
                && ((string) $operationServiceLink->supplier_account_id !== (string) $operation->supplier_account_id
                    || (string) $operationServiceLink->service_id !== (string) $service->id
                    || (string) $operationServiceLink->upstream_service_id !== $hostId)) {
                throw new DomainException('The operation is already linked to another supplier host.');
            }

            $serviceLink = SupplierServiceLink::query()
                ->where('supplier_account_id', $operation->supplier_account_id)
                ->where('service_id', $service->id)
                ->lockForUpdate()
                ->first();
            $remoteLink = SupplierServiceLink::query()
                ->where('supplier_account_id', $operation->supplier_account_id)
                ->where('upstream_service_id', $hostId)
                ->lockForUpdate()
                ->first();
            if ($serviceLink !== null && (string) $serviceLink->upstream_service_id !== $hostId) {
                throw new DomainException('The local service is already linked to another supplier host.');
            }
            if ($remoteLink !== null && (string) $remoteLink->service_id !== (string) $service->id) {
                throw new DomainException('The supplier host is already linked to another local service.');
            }

            $serviceLink ??= $remoteLink;
            if ($serviceLink === null) {
                $serviceLink = SupplierServiceLink::createFor($account, $service, $mapping, [
                    'upstream_service_id' => $hostId,
                    'upstream_status' => mb_substr($status, 0, 32),
                    'metadata' => $safeHost + ['source' => $source],
                    'synced_at' => now(),
                ]);
            }
            if ($serviceLink->supplier_product_mapping_id === null) {
                $serviceLink->supplier_product_mapping_id = $mapping->id;
            } elseif ((string) $serviceLink->supplier_product_mapping_id !== (string) $mapping->id) {
                throw new DomainException('The supplier service link uses another product mapping.');
            }
            $serviceLink->upstream_status = mb_substr($status, 0, 32);
            $serviceLink->metadata = $safeHost + ['source' => $source];
            $serviceLink->synced_at = now();
            $serviceLink->save();

            if ($invoiceLink !== null) {
                if ((string) $invoiceLink->supplier_account_id !== (string) $operation->supplier_account_id) {
                    throw new DomainException('The supplier invoice link belongs to another account.');
                }
                if ((string) $invoiceLink->invoice_id !== (string) $operation->invoice_id) {
                    throw new DomainException('The supplier invoice link references another local invoice.');
                }
                if ($invoiceLink->supplier_service_link_id === null) {
                    $invoiceLink->supplier_service_link_id = $serviceLink->id;
                    $invoiceLink->save();
                } elseif ((string) $invoiceLink->supplier_service_link_id !== (string) $serviceLink->id) {
                    throw new DomainException('The supplier invoice is linked to another supplier service.');
                }
            }

            $metadata = is_array($operation->metadata) ? $operation->metadata : [];
            if ($paymentAttestedBy !== null) {
                $invoiceId = $this->firstIdentifier($invoiceLink?->upstream_invoice_id);
                if ($invoiceId === null) {
                    throw new DomainException(
                        'Manual payment attestation requires a pending service and a frozen supplier invoice.',
                    );
                }
                $invoiceLink->upstream_status = 'Paid';
                $invoiceLink->save();
                $metadata['payment_confirmed'] = true;
                $metadata['payment_confirmation'] = 'admin_attested';
                $metadata['payment_confirmed_by'] = $paymentAttestedBy;
                $metadata['payment_invoice_id'] = $invoiceId;
                $metadata['payment_host_id'] = $hostId;
                $metadata['payment_confirmed_at'] = now()->toIso8601String();
            }

            $operation->metadata = $metadata;
            $operation->supplier_service_link_id = $serviceLink->id;
            $operation->save();
            $operation->setRelation('serviceLink', $serviceLink);
            $operation->setRelation('invoiceLink', $invoiceLink);
            $paymentConfirmed = $this->paymentIsConfirmed($operation, $invoiceLink);
            if ($paymentConfirmed && in_array($service->status, ['Failed', 'Suspended'], true)) {
                $service->update(['status' => 'Pending']);
            }
            unset($metadata['poll_attempts']);
            $operation->supplier_service_link_id = $serviceLink->id;
            $operation->upstream_reference ??= $hostId;
            $operation->response_payload = [
                'endpoint' => 'host_header',
                'status' => $response->status,
                'host' => $safeHost,
            ];
            $operation->status = $paymentConfirmed
                ? SupplierOperation::STATUS_AWAITING_CONFIRMATION
                : ($operation->status === SupplierOperation::STATUS_BLOCKED_CREDIT
                    ? SupplierOperation::STATUS_BLOCKED_CREDIT
                    : SupplierOperation::STATUS_AMBIGUOUS);
            $operation->step = $paymentConfirmed
                ? 'awaiting_confirmation'
                : ($operation->status === SupplierOperation::STATUS_BLOCKED_CREDIT
                    ? ($operation->step === 'awaiting_manual_supplier_payment'
                        ? 'awaiting_manual_supplier_payment'
                        : 'blocked_credit')
                    : 'host_observed_payment_unconfirmed');
            $operation->metadata = $metadata;
            $operation->available_at = $paymentConfirmed ? now() : null;
            $operation->finished_at = $paymentConfirmed ? null : now();
            if ($paymentConfirmed) {
                $operation->last_error_code = null;
                $operation->last_error = null;
            } elseif ($operation->status !== SupplierOperation::STATUS_BLOCKED_CREDIT) {
                $operation->last_error_code = 'payment_unconfirmed';
                $operation->last_error = 'The supplier host was observed, but supplier payment is not confirmed.';
            }
            $operation->save();

            return true;
        }, 3);
    }

    private function blockedCreditInvoiceId(?SupplierOperation $operation): ?string
    {
        if ($operation === null
            || $operation->action !== SupplierOperation::ACTION_PROVISION
            || $operation->status !== SupplierOperation::STATUS_BLOCKED_CREDIT
            || $operation->step !== 'blocked_credit'
            || $operation->last_error_code !== 'upstream_credit_insufficient'
            || ! $operation->account?->is_active
            || ! $operation->account->allowsLegacyUnboundedCreditPayment()
            || $operation->service === null
            || $operation->productMapping === null
            || $operation->orderItemRoute === null
            || $operation->invoiceLink === null
            || strtolower((string) $operation->invoiceLink->upstream_status) !== 'unpaid'
            || $this->hasPaymentConfirmationEvidence($operation, $operation->invoiceLink)
            || (string) $operation->invoiceLink->supplier_account_id !== (string) $operation->supplier_account_id
            || (string) $operation->productMapping->supplier_account_id !== (string) $operation->supplier_account_id
            || (string) $operation->orderItemRoute->supplier_account_id !== (string) $operation->supplier_account_id
            || (string) $operation->orderItemRoute->supplier_product_mapping_id
                !== (string) $operation->supplier_product_mapping_id
            || (string) $operation->orderItemRoute->local_product_id !== (string) $operation->service->product_id
            || (string) $operation->orderItemRoute->local_billing_cycle !== (string) $operation->service->billing_cycle
            || ! hash_equals(
                (string) $operation->orderItemRoute->account_identity_hash,
                SupplierOrderItemRoute::accountIdentityHash($operation->account),
            )
            || (string) $operation->invoiceLink->invoice_id !== (string) $operation->invoice_id) {
            return null;
        }

        try {
            $this->validateProvisionSnapshot($operation);
        } catch (InvalidArgumentException|DomainException) {
            return null;
        }

        return $this->firstIdentifier($operation->invoiceLink->upstream_invoice_id);
    }

    private function canReconcileHost(?SupplierOperation $operation): bool
    {
        if ($operation === null
            || $operation->action !== SupplierOperation::ACTION_PROVISION
            || ! in_array($operation->status, [
                SupplierOperation::STATUS_BLOCKED_CREDIT,
                SupplierOperation::STATUS_AMBIGUOUS,
                SupplierOperation::STATUS_FAILED,
            ], true)
            || ! $operation->account?->is_active
            || $operation->service === null
            || $operation->productMapping === null
            || $operation->orderItemRoute === null) {
            return false;
        }

        try {
            $this->validateProvisionSnapshot($operation, ['Pending', 'Failed', 'Suspended']);
        } catch (InvalidArgumentException|DomainException) {
            return false;
        }

        return true;
    }

    private function canAttestManualPayment(?SupplierOperation $operation): bool
    {
        if ($operation === null
            || $operation->action !== SupplierOperation::ACTION_PROVISION
            || $operation->status !== SupplierOperation::STATUS_BLOCKED_CREDIT
            || $operation->step !== 'awaiting_manual_supplier_payment'
            || ! $operation->account?->is_active
            || $operation->service?->status !== 'Pending'
            || $operation->productMapping === null
            || $operation->orderItemRoute === null
            || $operation->invoiceLink === null
            || $this->firstIdentifier($operation->invoiceLink->upstream_invoice_id) === null
            || strtolower((string) $operation->invoiceLink->upstream_status) !== 'unpaid'
            || $this->hasPaymentConfirmationEvidence($operation, $operation->invoiceLink)
            || (string) $operation->supplier_invoice_link_id
                !== (string) $operation->invoiceLink->id
            || (string) $operation->invoiceLink->supplier_account_id
                !== (string) $operation->supplier_account_id
            || (string) $operation->invoiceLink->invoice_id !== (string) $operation->invoice_id) {
            return false;
        }

        try {
            $this->validateProvisionSnapshot($operation);
        } catch (InvalidArgumentException|DomainException) {
            return false;
        }

        return true;
    }

    private function opaqueIdentifier(string|int $identifier, string $label): string
    {
        $identifier = trim((string) $identifier);
        if ($identifier === ''
            || $identifier === '0'
            || strlen($identifier) > 128
            || preg_match('/[\x00-\x1f\x7f]/', $identifier)
            || preg_match('/eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)?/', $identifier)) {
            throw new InvalidArgumentException("The upstream {$label} identifier is invalid.");
        }

        return $identifier;
    }

    private function recoveryHostIdentifier(string|int $identifier): string
    {
        $identifier = $this->opaqueIdentifier($identifier, 'host');
        if (preg_match('/[^\x20-\x7e]/', $identifier)) {
            throw new InvalidArgumentException('The recovery host identifier is invalid.');
        }

        return $identifier;
    }

    private function recoverStaleRunningLocked(int $operationId, Carbon $cutoff): ?string
    {
        return DB::transaction(function () use ($operationId, $cutoff): ?string {
            $operation = SupplierOperation::query()
                ->with([
                    'account',
                    'productMapping',
                    'orderItemRoute',
                    'order',
                    'orderItem',
                    'invoice',
                    'service',
                    'serviceLink',
                    'invoiceLink',
                ])
                ->lockForUpdate()
                ->find($operationId);
            if ($operation === null
                || $operation->action !== SupplierOperation::ACTION_PROVISION
                || $operation->status !== SupplierOperation::STATUS_RUNNING
                || $operation->updated_at === null
                || $operation->updated_at->isAfter($cutoff)) {
                return null;
            }
            $this->lockProvisionReferences($operation);

            try {
                $hostIds = $this->knownIdentifiers([
                    $operation->serviceLink?->upstream_service_id,
                    is_array($operation->response_payload)
                        ? ($operation->response_payload['host_id'] ?? null)
                        : null,
                ], 'host');
                $invoiceIds = $this->knownIdentifiers([
                    $operation->invoiceLink?->upstream_invoice_id,
                    is_array($operation->response_payload)
                        ? ($operation->response_payload['invoice_id'] ?? null)
                        : null,
                ], 'invoice');
                $upstreamReference = $operation->upstream_reference === null
                    ? null
                    : $this->opaqueIdentifier($operation->upstream_reference, 'operation');
            } catch (InvalidArgumentException) {
                $this->setTerminalState(
                    $operation,
                    SupplierOperation::STATUS_AMBIGUOUS,
                    'stale_running_ambiguous',
                    'stale_recovery_reference_invalid',
                    'A stale supplier mutation has invalid persisted reference evidence.',
                );

                return 'ambiguous';
            }
            $structuredReferences = array_values(array_unique([...$hostIds, ...$invoiceIds]));
            if (count($hostIds) > 1
                || count($invoiceIds) > 1
                || ($upstreamReference !== null
                    && $structuredReferences !== []
                    && ! in_array($upstreamReference, $structuredReferences, true))) {
                $this->setTerminalState(
                    $operation,
                    SupplierOperation::STATUS_AMBIGUOUS,
                    'stale_running_ambiguous',
                    'stale_recovery_reference_conflict',
                    'A stale supplier mutation has conflicting persisted reference evidence.',
                );

                return 'ambiguous';
            }

            $hostId = $hostIds[0] ?? null;
            $invoiceId = $invoiceIds[0] ?? null;
            $preflight = in_array($operation->step, self::PREFLIGHT_STEPS, true)
                && ! $this->hasSupplierMutationEvidence($operation);
            if ($preflight && $hostId === null && $invoiceId === null) {
                try {
                    $this->validateProvisionSnapshot($operation);
                } catch (InvalidArgumentException|DomainException) {
                    $this->setTerminalState(
                        $operation,
                        SupplierOperation::STATUS_FAILED,
                        'failed',
                        'snapshot_validation_failed',
                        'The stale preflight claim has an invalid immutable request snapshot.',
                    );

                    return 'failed';
                }

                $operation->update([
                    'status' => SupplierOperation::STATUS_QUEUED,
                    'step' => 'queued',
                    'available_at' => now(),
                    'started_at' => null,
                    'finished_at' => null,
                    'last_error_code' => null,
                    'last_error' => null,
                ]);
                $this->audit($operation, 'supplier.provisioning.stale_recovered', [
                    'outcome' => 'preflight_requeued',
                ]);

                return 'requeued';
            }

            try {
                $this->validateProvisionSnapshot($operation);
                DB::transaction(
                    fn () => $this->preserveRecoveredReferences($operation, $invoiceId, $hostId),
                    3,
                );
                $this->recoverPaymentConfirmation($operation, $invoiceId);
            } catch (InvalidArgumentException|DomainException) {
                $operation->refresh();
                $this->setTerminalState(
                    $operation,
                    SupplierOperation::STATUS_AMBIGUOUS,
                    'stale_running_ambiguous',
                    'stale_recovery_reference_invalid',
                    'A stale supplier mutation could not be reconciled from persisted reference evidence.',
                );

                return 'ambiguous';
            }

            $invoiceLink = SupplierInvoiceLink::query()
                ->whereKey($operation->supplier_invoice_link_id)
                ->lockForUpdate()
                ->first();
            $paymentConfirmed = $this->paymentIsConfirmed($operation, $invoiceLink);
            if ($hostId !== null && $paymentConfirmed) {
                $metadata = is_array($operation->metadata) ? $operation->metadata : [];
                unset($metadata['poll_attempts']);
                $operation->update([
                    'status' => SupplierOperation::STATUS_AWAITING_CONFIRMATION,
                    'step' => 'awaiting_confirmation',
                    'metadata' => $metadata,
                    'available_at' => now(),
                    'finished_at' => null,
                    'last_error_code' => null,
                    'last_error' => null,
                ]);
                $this->audit($operation, 'supplier.provisioning.stale_recovered', [
                    'outcome' => 'known_host_awaiting_confirmation',
                ]);

                return 'awaiting_confirmation';
            }

            if ($hostId !== null) {
                $this->setTerminalState(
                    $operation,
                    SupplierOperation::STATUS_AMBIGUOUS,
                    'stale_running_ambiguous',
                    'payment_unconfirmed',
                    'A supplier host is known, but supplier payment is not confirmed.',
                );

                return 'ambiguous';
            }

            if ($paymentConfirmed) {
                $this->setTerminalState(
                    $operation,
                    SupplierOperation::STATUS_AMBIGUOUS,
                    'stale_running_ambiguous',
                    'host_reconciliation_required',
                    'Supplier payment was confirmed, but no host identifier is available.',
                );

                return 'ambiguous';
            }

            $this->setTerminalState(
                $operation,
                SupplierOperation::STATUS_AMBIGUOUS,
                'stale_running_ambiguous',
                'stale_running_mutation_ambiguous',
                'A stale supplier mutation has no proven host outcome and will not be replayed.',
            );

            return 'ambiguous';
        }, 3);
    }

    private function hasSupplierMutationEvidence(SupplierOperation $operation): bool
    {
        $metadata = is_array($operation->metadata) ? $operation->metadata : [];

        return ! in_array($operation->step, self::PREFLIGHT_STEPS, true)
            || $operation->supplier_service_link_id !== null
            || $operation->supplier_invoice_link_id !== null
            || $operation->upstream_reference !== null
            || array_key_exists('payment_confirmed', $metadata)
            || array_key_exists('payment_application_status', $metadata)
            || array_key_exists('payment_invoice_id', $metadata)
            || (is_array($operation->response_payload) && $operation->response_payload !== []);
    }

    private function knownIdentifiers(array $values, string $label): array
    {
        $identifiers = [];
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            if (! is_string($value) && ! is_int($value)) {
                throw new InvalidArgumentException("The upstream {$label} reference evidence is invalid.");
            }
            $identifiers[] = $this->opaqueIdentifier($value, $label);
        }

        return array_values(array_unique($identifiers));
    }

    private function preserveRecoveredReferences(
        SupplierOperation $operation,
        ?string $invoiceId,
        ?string $hostId,
    ): void {
        $account = $operation->account;
        $invoice = $operation->invoice;
        $service = $operation->service;
        $mapping = $operation->productMapping;
        if ($account === null || $invoice === null || $service === null || $mapping === null) {
            throw new DomainException('The stale supplier operation references are incomplete.');
        }

        $serviceLink = $operation->serviceLink;
        if ($hostId !== null) {
            $localLink = SupplierServiceLink::query()
                ->where('supplier_account_id', $operation->supplier_account_id)
                ->where('service_id', $service->id)
                ->lockForUpdate()
                ->first();
            $remoteLink = SupplierServiceLink::query()
                ->where('supplier_account_id', $operation->supplier_account_id)
                ->where('upstream_service_id', $hostId)
                ->lockForUpdate()
                ->first();
            foreach ([$serviceLink, $localLink, $remoteLink] as $candidate) {
                if ($candidate !== null
                    && ((string) $candidate->supplier_account_id !== (string) $operation->supplier_account_id
                        || (string) $candidate->service_id !== (string) $service->id
                        || (string) $candidate->upstream_service_id !== $hostId)) {
                    throw new DomainException('The stale supplier host reference conflicts with local ownership.');
                }
            }

            $serviceLink ??= $localLink ?? $remoteLink;
            if ($serviceLink === null) {
                $serviceLink = SupplierServiceLink::createFor($account, $service, null, [
                    'upstream_service_id' => $hostId,
                    'upstream_status' => 'Pending',
                    'metadata' => ['source' => 'stale_recovery'],
                ]);
                $serviceLink->supplier_product_mapping_id = $mapping->id;
                $serviceLink->save();
            } elseif ($serviceLink->supplier_product_mapping_id === null) {
                $serviceLink->supplier_product_mapping_id = $mapping->id;
                $serviceLink->save();
            } elseif ((string) $serviceLink->supplier_product_mapping_id !== (string) $mapping->id) {
                throw new DomainException('The stale supplier host uses another product mapping.');
            }
            $operation->supplier_service_link_id = $serviceLink->id;
        }

        $invoiceLink = $operation->invoiceLink;
        if ($invoiceId !== null) {
            $remoteInvoiceLink = SupplierInvoiceLink::query()
                ->where('supplier_account_id', $operation->supplier_account_id)
                ->where('upstream_invoice_id', $invoiceId)
                ->lockForUpdate()
                ->first();
            foreach ([$invoiceLink, $remoteInvoiceLink] as $candidate) {
                if ($candidate !== null
                    && ((string) $candidate->supplier_account_id !== (string) $operation->supplier_account_id
                        || (string) $candidate->invoice_id !== (string) $invoice->id
                        || (string) $candidate->upstream_invoice_id !== $invoiceId)) {
                    throw new DomainException('The stale supplier invoice reference conflicts with local ownership.');
                }
            }

            $invoiceLink ??= $remoteInvoiceLink;
            $invoiceLink ??= SupplierInvoiceLink::createFor($account, $invoice, $serviceLink, [
                'upstream_invoice_id' => $invoiceId,
                'upstream_status' => 'Unpaid',
                'metadata' => ['source' => 'stale_recovery'],
            ]);
            if ($serviceLink !== null) {
                if ($invoiceLink->supplier_service_link_id === null) {
                    $invoiceLink->supplier_service_link_id = $serviceLink->id;
                    $invoiceLink->save();
                } elseif ((string) $invoiceLink->supplier_service_link_id !== (string) $serviceLink->id) {
                    throw new DomainException('The stale supplier invoice is linked to another host.');
                }
            }
            $operation->supplier_invoice_link_id = $invoiceLink->id;
        }

        $operation->upstream_reference ??= $invoiceId ?? $hostId;
        $operation->save();
    }

    private function recoverPaymentConfirmation(SupplierOperation $operation, ?string $invoiceId): void
    {
        $response = is_array($operation->response_payload) ? $operation->response_payload : [];
        if (! in_array($response['endpoint'] ?? null, ['apply_credit', 'apply_credit_recovery'], true)
            || ($response['status'] ?? null) !== 1001) {
            return;
        }
        if ($invoiceId === null
            || (isset($response['invoice_id']) && (string) $response['invoice_id'] !== $invoiceId)) {
            throw new DomainException('The stale supplier payment confirmation has no matching invoice.');
        }

        $invoiceLink = SupplierInvoiceLink::query()
            ->whereKey($operation->supplier_invoice_link_id)
            ->lockForUpdate()
            ->first();
        if ($invoiceLink === null
            || (string) $invoiceLink->supplier_account_id !== (string) $operation->supplier_account_id
            || (string) $invoiceLink->invoice_id !== (string) $operation->invoice_id
            || (string) $invoiceLink->upstream_invoice_id !== $invoiceId) {
            throw new DomainException('The stale supplier payment confirmation conflicts with local ownership.');
        }

        $invoiceLink->upstream_status = 'Paid';
        $invoiceLink->save();
        $metadata = is_array($operation->metadata) ? $operation->metadata : [];
        $metadata['payment_confirmed'] = true;
        $metadata['payment_application_status'] = 1001;
        $metadata['payment_invoice_id'] = $invoiceId;
        $operation->metadata = $metadata;
        $operation->save();
    }

    private function claim(int $operationId): ?SupplierOperation
    {
        $claimed = DB::transaction(function () use ($operationId): bool {
            $operation = SupplierOperation::query()->lockForUpdate()->find($operationId);
            if ($operation === null
                || $operation->action !== SupplierOperation::ACTION_PROVISION
                || $operation->status !== SupplierOperation::STATUS_QUEUED
                || ($operation->available_at !== null && $operation->available_at->isFuture())) {
                return false;
            }

            $operation->update([
                'status' => SupplierOperation::STATUS_RUNNING,
                'step' => 'validation',
                'attempts' => $operation->attempts + 1,
                'available_at' => null,
                'started_at' => now(),
                'finished_at' => null,
                'last_error_code' => null,
                'last_error' => null,
            ]);

            return true;
        }, 3);

        return $claimed
            ? SupplierOperation::query()->with([
                'account',
                'productMapping',
                'orderItemRoute',
                'order',
                'orderItem',
                'invoice',
                'service',
            ])->find($operationId)
            : null;
    }

    private function claimPoll(int $operationId): ?array
    {
        return DB::transaction(function () use ($operationId): ?array {
            $operation = SupplierOperation::query()
                ->lockForUpdate()
                ->find($operationId);
            if ($operation === null
                || $operation->action !== SupplierOperation::ACTION_PROVISION
                || $operation->status !== SupplierOperation::STATUS_AWAITING_CONFIRMATION
                || $operation->supplier_service_link_id === null
                || ($operation->available_at !== null && $operation->available_at->isFuture())) {
                return null;
            }
            $this->lockProvisionReferences($operation);
            $operation->setRelation(
                'serviceLink',
                $operation->serviceLink()->lockForUpdate()->first(),
            );
            $operation->setRelation(
                'invoiceLink',
                $operation->invoiceLink()->lockForUpdate()->first(),
            );
            try {
                $this->validateProvisionSnapshot($operation);
            } catch (InvalidArgumentException|DomainException $exception) {
                $this->setTerminalState(
                    $operation,
                    SupplierOperation::STATUS_FAILED,
                    'snapshot_validation_failed',
                    'snapshot_validation_failed',
                    $exception->getMessage(),
                );

                return ['skip_http' => true, 'operation' => $operation, 'attempt' => 0];
            }
            if (! $this->paymentIsConfirmed($operation, $operation->invoiceLink)) {
                $this->setTerminalState(
                    $operation,
                    SupplierOperation::STATUS_AMBIGUOUS,
                    'payment_unconfirmed',
                    'payment_unconfirmed',
                    'Supplier payment is not confirmed, so automatic host polling is disabled.',
                );

                return ['skip_http' => true, 'operation' => $operation, 'attempt' => 0];
            }

            $metadata = is_array($operation->metadata) ? $operation->metadata : [];
            $attempt = (int) ($metadata['poll_attempts'] ?? 0) + 1;
            if ($attempt > self::MAX_POLL_ATTEMPTS) {
                $this->setTerminalState(
                    $operation,
                    SupplierOperation::STATUS_FAILED,
                    'poll_exhausted',
                    'poll_exhausted',
                    'Supplier host confirmation exhausted its bounded polling attempts.',
                );

                return ['skip_http' => true, 'operation' => $operation, 'attempt' => $attempt];
            }

            $metadata['poll_attempts'] = $attempt;
            $operation->update([
                'step' => 'host_polling',
                'metadata' => $metadata,
                'available_at' => now()->addMinute(),
            ]);

            return ['skip_http' => false, 'operation' => $operation, 'attempt' => $attempt];
        }, 3);
    }

    private function lockProvisionReferences(SupplierOperation $operation): void
    {
        foreach ([
            'account',
            'productMapping',
            'orderItemRoute',
            'order',
            'orderItem',
            'invoice',
            'service',
        ] as $relation) {
            $operation->setRelation(
                $relation,
                $operation->{$relation}()->lockForUpdate()->first(),
            );
        }
    }

    private function validateProvisionSnapshot(
        SupplierOperation $operation,
        array $allowedServiceStatuses = ['Pending'],
    ): array {
        $payload = $operation->request_payload;
        if (! is_array($payload)) {
            throw new InvalidArgumentException('The supplier operation snapshot is missing.');
        }
        try {
            $hash = hash('sha256', json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The supplier operation snapshot is invalid.', 0, $exception);
        }
        if (! hash_equals($operation->request_hash, $hash)) {
            throw new InvalidArgumentException('The supplier operation snapshot hash is invalid.');
        }
        if (($payload['version'] ?? null) !== 2
            || ($payload['action'] ?? null) !== SupplierOperation::ACTION_PROVISION
            || ! $this->hasExactKeys($payload, [
                'version',
                'action',
                'local',
                'route',
                'correlation',
            ])) {
            throw new InvalidArgumentException('The supplier operation snapshot type is invalid.');
        }
        if ($operation->action !== SupplierOperation::ACTION_PROVISION || ! $operation->account?->is_active) {
            throw new InvalidArgumentException('The supplier provisioning account is disabled or incomplete.');
        }
        if (! $operation->invoice || $operation->invoice->status !== 'Paid') {
            throw new InvalidArgumentException('The local invoice is not paid.');
        }
        if (! $operation->service
            || ! in_array($operation->service->status, $allowedServiceStatuses, true)) {
            throw new InvalidArgumentException('The local service is not pending provisioning.');
        }

        $local = $this->requiredArray($payload, 'local');
        $route = $this->requiredArray($payload, 'route');
        $routeAccount = $this->requiredArray($route, 'account');
        $routeMapping = $this->requiredArray($route, 'mapping');
        $routeLocal = $this->requiredArray($route, 'local');
        $upstream = $this->requiredArray($route, 'upstream');
        $correlation = $this->requiredArray($payload, 'correlation');
        if (! $this->hasExactKeys($local, [
            'order_id',
            'order_item_id',
            'invoice_id',
            'service_id',
            'unit_index',
        ]) || ! $this->hasExactKeys($route, [
            'version',
            'account',
            'mapping',
            'local',
            'upstream',
        ]) || ! $this->hasExactKeys($routeAccount, [
            'supplier_account_id',
            'driver',
            'base_url',
            'identity_hash',
        ]) || ! $this->hasExactKeys($routeMapping, [
            'supplier_product_mapping_id',
            'supplier_catalog_product_id',
            'options',
        ]) || ! $this->hasExactKeys($routeLocal, [
            'order_id',
            'order_item_id',
            'product_id',
            'billing_cycle',
            'quantity',
            'unit_price',
            'setup_fee',
            'unit_total',
            'currency',
        ]) || ! $this->hasExactKeys($upstream, [
            'product_id',
            'billing_cycle',
            'qty',
            'options',
            'configoption',
            'expected_amount',
            'currency',
        ]) || ! $this->hasExactKeys($correlation, [
            'downstream_id',
            'downstream_token',
            'downstream_url',
        ])) {
            throw new InvalidArgumentException('The supplier provisioning snapshot structure is invalid.');
        }
        if (($route['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('The supplier route snapshot type is invalid.');
        }
        foreach ([
            'order_id' => $operation->order_id,
            'order_item_id' => $operation->order_item_id,
            'invoice_id' => $operation->invoice_id,
            'service_id' => $operation->service_id,
        ] as $key => $reference) {
            if ((string) ($local[$key] ?? '') !== (string) $reference) {
                throw new InvalidArgumentException('The supplier operation local references do not match its snapshot.');
            }
        }

        $accountId = $this->requiredPositiveId($routeAccount, 'supplier_account_id');
        $mappingId = $this->requiredPositiveId($routeMapping, 'supplier_product_mapping_id');
        $catalogId = $this->requiredPositiveId($routeMapping, 'supplier_catalog_product_id');
        $orderId = $this->requiredPositiveId($routeLocal, 'order_id');
        $orderItemId = $this->requiredPositiveId($routeLocal, 'order_item_id');
        $localProductId = $this->requiredPositiveId($routeLocal, 'product_id');
        $localBillingCycle = $this->requiredIdentifier($routeLocal, 'billing_cycle', 32);
        $localCurrency = $this->requiredCurrency($routeLocal, 'currency');
        $unitPrice = $this->requiredAmount($routeLocal, 'unit_price');
        $setupFee = $this->requiredAmount($routeLocal, 'setup_fee');
        $unitTotal = $this->requiredAmount($routeLocal, 'unit_total');
        $quantity = $routeLocal['quantity'] ?? null;
        if ((! is_int($quantity) && ! (is_string($quantity) && ctype_digit($quantity)))
            || (int) $quantity < 1
            || (int) $quantity > 100
            || Money::toMinor($unitTotal) !== Money::toMinor($unitPrice) + Money::toMinor($setupFee)
            || Money::toMinor($unitTotal) > intdiv(self::MAX_DATABASE_AMOUNT_MINOR, (int) $quantity)) {
            throw new InvalidArgumentException('The supplier local monetary snapshot is invalid.');
        }
        $upstreamProductId = $this->requiredIdentifier($upstream, 'product_id', 128);
        $upstreamBillingCycle = $this->requiredIdentifier($upstream, 'billing_cycle', 32);
        $expectedAmount = $this->requiredAmount($upstream, 'expected_amount');
        $upstreamCurrency = $this->requiredCurrency($upstream, 'currency');
        if (! is_array($routeMapping['options'])
            || ! is_array($upstream['options'])
            || $upstream['options'] !== $routeMapping['options']
            || $upstream['qty'] !== 1
            || ! is_array($upstream['configoption'])
            || $upstream['configoption'] !== $this->mappingConfigOptions($routeMapping)) {
            throw new InvalidArgumentException('The supplier provisioning configuration snapshot is inconsistent.');
        }

        $account = $operation->account;
        $routeModel = $operation->orderItemRoute;
        $liveMapping = $operation->productMapping;
        $identityHash = $this->requiredIdentifier($routeAccount, 'identity_hash', 64);
        if (preg_match('/\A[0-9a-f]{64}\z/', $identityHash) !== 1
            || $account === null
            || $routeModel === null
            || $liveMapping === null
            || $routeModel->validatedSnapshot() !== $route
            || $accountId !== (int) $operation->supplier_account_id
            || $accountId !== (int) $routeModel->supplier_account_id
            || $mappingId !== (int) $operation->supplier_product_mapping_id
            || $mappingId !== (int) $routeModel->supplier_product_mapping_id
            || $catalogId !== (int) $routeModel->supplier_catalog_product_id
            || (int) $operation->supplier_order_item_route_id !== (int) $routeModel->id
            || $orderItemId !== (int) $routeModel->order_item_id
            || $localProductId !== (int) $routeModel->local_product_id
            || $localBillingCycle !== (string) $routeModel->local_billing_cycle
            || $upstreamProductId !== (string) $routeModel->upstream_product_id
            || $upstreamBillingCycle !== (string) $routeModel->upstream_billing_cycle
            || $localCurrency !== (string) $routeModel->local_currency
            || $unitPrice !== (string) $routeModel->local_unit_amount
            || $setupFee !== (string) $routeModel->local_setup_amount
            || $expectedAmount !== (string) $routeModel->expected_upstream_amount
            || $upstreamCurrency !== (string) $routeModel->expected_upstream_currency
            || ! hash_equals($identityHash, (string) $routeModel->account_identity_hash)
            || ! hash_equals($identityHash, SupplierOrderItemRoute::accountIdentityHash($account))
            || (string) $routeAccount['driver'] !== (string) $account->driver
            || rtrim(trim((string) $routeAccount['base_url']), '/')
                !== rtrim(trim((string) $account->base_url), '/')
            || (int) $liveMapping->id !== $mappingId
            || (int) $liveMapping->supplier_account_id !== $accountId
            || $orderId !== (int) $operation->order_id
            || $orderItemId !== (int) $operation->order_item_id
            || (int) $operation->service->product_id !== $localProductId
            || (string) $operation->service->billing_cycle !== $localBillingCycle
            || (string) $operation->orderItem?->order_id !== (string) $operation->order_id
            || (string) $operation->orderItem?->product_id !== (string) $localProductId
            || (string) $operation->orderItem?->billing_cycle !== $localBillingCycle
            || (int) $operation->orderItem?->quantity !== (int) $quantity
            || Money::format(Money::toMinor($operation->orderItem?->unit_price ?? '')) !== $unitPrice
            || Money::format(Money::toMinor($operation->orderItem?->setup_fee ?? '')) !== $setupFee
            || Money::toMinor($operation->orderItem?->amount ?? '')
                !== Money::toMinor($unitTotal) * (int) $quantity
            || ((is_array($operation->orderItem?->configuration)
                && $operation->orderItem->configuration !== [])
                || (! is_array($operation->orderItem?->configuration)
                    && $operation->orderItem?->configuration !== null))
            || (string) $operation->invoice->order_id !== (string) $operation->order_id
            || (string) $operation->service->order_id !== (string) $operation->order_id
            || (string) $operation->service->order_item_id !== (string) $operation->order_item_id
            || (string) $operation->service->unit_index !== (string) $local['unit_index']
            || (string) $operation->invoice->user_id !== (string) $operation->service->user_id
            || (string) $operation->order?->user_id !== (string) $operation->service->user_id) {
            throw new InvalidArgumentException('The supplier provisioning references do not match the immutable route.');
        }

        $url = $this->requiredIdentifier($correlation, 'downstream_url', 2048);
        $parts = parse_url($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new InvalidArgumentException('The supplier downstream URL snapshot is invalid.');
        }
        $token = $this->requiredIdentifier($correlation, 'downstream_token', 32);
        if (preg_match('/\A[0-9a-f]{32}\z/', $token) !== 1) {
            throw new InvalidArgumentException('The supplier downstream token snapshot is invalid.');
        }
        $downstreamId = $correlation['downstream_id'] ?? null;
        if ((! is_int($downstreamId) && ! (is_string($downstreamId) && ctype_digit($downstreamId)))
            || (int) $downstreamId < 1) {
            throw new InvalidArgumentException('The supplier downstream ID snapshot is invalid.');
        }

        return $payload;
    }

    private function mutate(
        int $operationId,
        string $step,
        callable $callback,
        array $payload,
        bool &$mutationAttempted,
        int $claimAttempt,
    ): FinanceResponse {
        $this->checkpoint(
            $operationId,
            $claimAttempt,
            $step.'_mutation_started',
            validateProvision: true,
        );
        $mutationAttempted = true;
        $response = $callback();
        $this->checkpoint($operationId, $claimAttempt, $step.'_mutation_completed', [
            'response_payload' => $this->safeResponse($step, $response, $payload),
        ]);

        return $response;
    }

    private function persistReferences(
        int $operationId,
        ?string $invoiceId,
        ?string $hostId,
        string $endpoint,
        FinanceResponse $response,
        ?string $invoiceStatus,
        ?int $claimAttempt = null,
        bool $paymentConfirmed = false,
    ): void {
        DB::transaction(function () use (
            $operationId,
            $invoiceId,
            $hostId,
            $endpoint,
            $response,
            $invoiceStatus,
            $claimAttempt,
            $paymentConfirmed,
        ): void {
            $operation = SupplierOperation::query()->lockForUpdate()->findOrFail($operationId);
            if ($operation->status !== SupplierOperation::STATUS_RUNNING
                || ($claimAttempt !== null && $operation->attempts !== $claimAttempt)) {
                throw new DomainException('The supplier operation claim is no longer current.');
            }
            if ($paymentConfirmed && ($response->status !== 1001 || $invoiceId === null)) {
                throw new DomainException('The supplier payment confirmation evidence is invalid.');
            }
            $account = $operation->account()->firstOrFail();
            $service = $operation->service()->lockForUpdate()->firstOrFail();
            $mapping = $operation->productMapping()->firstOrFail();
            $serviceLink = null;

            if ($hostId !== null) {
                $serviceLink = SupplierServiceLink::query()
                    ->where('supplier_account_id', $operation->supplier_account_id)
                    ->where('service_id', $service->id)
                    ->lockForUpdate()
                    ->first();
                $remoteLink = SupplierServiceLink::query()
                    ->where('supplier_account_id', $operation->supplier_account_id)
                    ->where('upstream_service_id', $hostId)
                    ->lockForUpdate()
                    ->first();
                if ($serviceLink !== null && (string) $serviceLink->upstream_service_id !== $hostId) {
                    throw new DomainException('The local service is already linked to another supplier host.');
                }
                if ($remoteLink !== null && (string) $remoteLink->service_id !== (string) $service->id) {
                    throw new DomainException('The supplier host is already linked to another local service.');
                }
                $serviceLink ??= $remoteLink;
                if ($serviceLink === null) {
                    $serviceLink = SupplierServiceLink::createFor($account, $service, null, [
                        'upstream_service_id' => $hostId,
                        'upstream_status' => 'Pending',
                        'metadata' => ['source' => 'provision'],
                    ]);
                    $serviceLink->supplier_product_mapping_id = $mapping->id;
                    $serviceLink->save();
                }
                if ((string) $serviceLink->supplier_product_mapping_id !== (string) $mapping->id) {
                    throw new DomainException('The supplier service link uses another product mapping.');
                }
                $operation->supplier_service_link_id = $serviceLink->id;
            }

            if ($invoiceId !== null) {
                $invoice = $operation->invoice()->firstOrFail();
                $invoiceLink = SupplierInvoiceLink::query()
                    ->where('supplier_account_id', $operation->supplier_account_id)
                    ->where('upstream_invoice_id', $invoiceId)
                    ->lockForUpdate()
                    ->first();
                if ($invoiceLink !== null && (string) $invoiceLink->invoice_id !== (string) $invoice->id) {
                    throw new DomainException('The supplier invoice is already linked to another local invoice.');
                }
                $invoiceLink ??= SupplierInvoiceLink::createFor($account, $invoice, $serviceLink, [
                    'upstream_invoice_id' => $invoiceId,
                    'upstream_status' => $invoiceStatus,
                    'metadata' => ['source' => 'provision'],
                ]);
                if ($serviceLink !== null) {
                    if ($invoiceLink->supplier_service_link_id === null) {
                        $invoiceLink->supplier_service_link_id = $serviceLink->id;
                    } elseif ((string) $invoiceLink->supplier_service_link_id !== (string) $serviceLink->id) {
                        throw new DomainException('The supplier invoice is linked to another supplier service.');
                    }
                }
                if ($invoiceStatus !== null) {
                    $invoiceLink->upstream_status = $invoiceStatus;
                }
                $invoiceLink->save();
                $operation->supplier_invoice_link_id = $invoiceLink->id;
            }

            $operation->upstream_reference = $invoiceId ?? $hostId ?? $operation->upstream_reference;
            $operation->response_payload = $this->safeResponse(
                $endpoint,
                $response,
                is_array($operation->request_payload) ? $operation->request_payload : [],
            );
            if ($paymentConfirmed) {
                $metadata = is_array($operation->metadata) ? $operation->metadata : [];
                $metadata['payment_confirmed'] = true;
                $metadata['payment_application_status'] = 1001;
                $metadata['payment_invoice_id'] = $invoiceId;
                $operation->metadata = $metadata;
            }
            $operation->save();
        }, 3);
    }

    private function awaitConfirmation(int $operationId, ?int $claimAttempt = null): void
    {
        DB::transaction(function () use ($operationId, $claimAttempt): void {
            $operation = SupplierOperation::query()->lockForUpdate()->findOrFail($operationId);
            $invoiceLink = $operation->invoiceLink()->lockForUpdate()->first();
            if ($operation->status !== SupplierOperation::STATUS_RUNNING
                || ($claimAttempt !== null && $operation->attempts !== $claimAttempt)
                || $operation->supplier_service_link_id === null
                || ! $this->paymentIsConfirmed($operation, $invoiceLink)) {
                throw new DomainException('The supplier host or payment evidence is unavailable for confirmation.');
            }

            $operation->update([
                'status' => SupplierOperation::STATUS_AWAITING_CONFIRMATION,
                'step' => 'awaiting_confirmation',
                'available_at' => now(),
                'last_error_code' => null,
                'last_error' => null,
            ]);
            $this->audit($operation, 'supplier.provisioning.awaiting_confirmation');
        }, 3);
    }

    private function applyHostStatus(
        int $operationId,
        int $attempt,
        string $status,
        array $host,
        FinanceResponse $response,
    ): void {
        DB::transaction(function () use ($operationId, $attempt, $status, $host, $response): void {
            $operation = SupplierOperation::query()->lockForUpdate()->findOrFail($operationId);
            if ($operation->status !== SupplierOperation::STATUS_AWAITING_CONFIRMATION) {
                return;
            }
            $service = $operation->service()->lockForUpdate()->firstOrFail();
            $serviceLink = $operation->serviceLink()->lockForUpdate()->firstOrFail();
            $invoiceLink = $operation->invoiceLink()->lockForUpdate()->first();
            try {
                $status = $this->validatedHostStatus(
                    $host,
                    (string) $serviceLink->upstream_service_id,
                    true,
                );
            } catch (UnexpectedValueException) {
                $this->setUnverifiedHostPollState($operation);

                return;
            }
            if (! $this->paymentIsConfirmed($operation, $invoiceLink)) {
                $this->setTerminalState(
                    $operation,
                    SupplierOperation::STATUS_AMBIGUOUS,
                    'payment_unconfirmed',
                    'payment_unconfirmed',
                    'Supplier payment is not confirmed, so the host cannot activate locally.',
                );

                return;
            }
            if ($service->status !== 'Pending') {
                $this->setTerminalState(
                    $operation,
                    SupplierOperation::STATUS_FAILED,
                    'local_service_not_pending',
                    'local_service_not_pending',
                    'The local service is no longer pending provisioning.',
                );

                return;
            }

            $normalized = strtolower($status);
            $safeHost = $this->safeHostData($host);
            $serviceLink->update([
                'upstream_status' => mb_substr($status, 0, 32),
                'metadata' => $safeHost,
                'synced_at' => now(),
            ]);
            $operation->response_payload = $this->safeHostResponse($response, $host);

            if ($normalized === 'active') {
                $service->update(['status' => 'Active'] + $this->activationAttributes($service, $safeHost));
                $this->setTerminalState(
                    $operation,
                    SupplierOperation::STATUS_SUCCEEDED,
                    'host_confirmed_active',
                );

                return;
            }

            $terminalStatus = match ($normalized) {
                'failed' => 'Failed',
                'cancelled', 'canceled' => 'Cancelled',
                'deleted' => 'Deleted',
                'suspended' => 'Suspended',
                default => null,
            };
            if ($terminalStatus !== null) {
                $service->update(['status' => $terminalStatus]);
                $this->setTerminalState(
                    $operation,
                    SupplierOperation::STATUS_FAILED,
                    'host_confirmed_'.$normalized,
                    'upstream_host_'.$normalized,
                    'The supplier host did not become active.',
                );

                return;
            }

            if ($attempt >= self::MAX_POLL_ATTEMPTS) {
                $this->setTerminalState(
                    $operation,
                    SupplierOperation::STATUS_FAILED,
                    'poll_exhausted',
                    'poll_exhausted',
                    'Supplier host confirmation exhausted its bounded polling attempts.',
                );

                return;
            }

            $operation->update([
                'step' => 'host_pending',
                'available_at' => now()->addMinute(),
                'last_error_code' => null,
                'last_error' => null,
            ]);
        }, 3);
    }

    private function deferOrExhaustPoll(
        int $operationId,
        int $attempt,
        string $code,
        string $message,
        ?array $response = null,
    ): void {
        DB::transaction(function () use ($operationId, $attempt, $code, $message, $response): void {
            $operation = SupplierOperation::query()->lockForUpdate()->find($operationId);
            if ($operation === null || $operation->status !== SupplierOperation::STATUS_AWAITING_CONFIRMATION) {
                return;
            }
            if ($response !== null) {
                $operation->response_payload = $response;
            }

            if ($attempt >= self::MAX_POLL_ATTEMPTS) {
                $this->setTerminalState(
                    $operation,
                    SupplierOperation::STATUS_FAILED,
                    'poll_exhausted',
                    'poll_exhausted',
                    $message,
                );

                return;
            }

            $operation->update([
                'step' => 'host_poll_deferred',
                'last_error_code' => $code,
                'last_error' => $message,
                'available_at' => now()->addMinute(),
            ]);
        }, 3);
    }

    private function finishUnverifiedHostPoll(int $operationId): void
    {
        DB::transaction(function () use ($operationId): void {
            $operation = SupplierOperation::query()->lockForUpdate()->find($operationId);
            if ($operation === null
                || $operation->status !== SupplierOperation::STATUS_AWAITING_CONFIRMATION) {
                return;
            }

            $this->setUnverifiedHostPollState($operation);
        }, 3);
    }

    private function setUnverifiedHostPollState(SupplierOperation $operation): void
    {
        $this->setTerminalState(
            $operation,
            SupplierOperation::STATUS_AMBIGUOUS,
            'host_identity_unverified',
            'host_identity_unverified',
            'The supplier host response identity could not be verified against the linked host.',
        );
    }

    private function finishBlockedCredit(
        int $operationId,
        string $message,
        ?int $claimAttempt = null,
    ): void {
        $this->finish(
            $operationId,
            SupplierOperation::STATUS_BLOCKED_CREDIT,
            'blocked_credit',
            'upstream_credit_insufficient',
            $message,
            $claimAttempt,
        );
    }

    private function deferOrExhaustPreflight(
        int $operationId,
        int $claimAttempt,
        string $message,
    ): void {
        DB::transaction(function () use ($operationId, $claimAttempt, $message): void {
            $operation = SupplierOperation::query()->lockForUpdate()->find($operationId);
            if ($operation === null
                || $operation->action !== SupplierOperation::ACTION_PROVISION
                || $operation->status !== SupplierOperation::STATUS_RUNNING
                || $operation->attempts !== $claimAttempt
                || $this->hasSupplierMutationEvidence($operation)) {
                return;
            }

            $metadata = is_array($operation->metadata) ? $operation->metadata : [];
            $failures = max(0, (int) ($metadata['preflight_failures'] ?? 0)) + 1;
            $metadata['preflight_failures'] = $failures;
            $operation->metadata = $metadata;

            if ($failures >= self::MAX_PREFLIGHT_ATTEMPTS) {
                $operation->save();
                $this->setTerminalState(
                    $operation,
                    SupplierOperation::STATUS_FAILED,
                    'preflight_retry_exhausted',
                    'preflight_retry_exhausted',
                    $message,
                );

                return;
            }

            $delay = self::PREFLIGHT_BACKOFF_SECONDS * (2 ** ($failures - 1));
            $operation->update([
                'status' => SupplierOperation::STATUS_QUEUED,
                'step' => 'preflight_retry_backoff',
                'metadata' => $metadata,
                'available_at' => now()->addSeconds($delay),
                'started_at' => null,
                'finished_at' => null,
                'last_error_code' => 'preflight_retry_scheduled',
                'last_error' => $message,
            ]);
            $this->audit($operation, 'supplier.provisioning.preflight_deferred', [
                'preflight_failures' => $failures,
                'retry_delay_seconds' => $delay,
            ]);
        }, 3);
    }

    private function finishManualPaymentReview(int $operationId, int $claimAttempt): void
    {
        $this->finish(
            $operationId,
            SupplierOperation::STATUS_BLOCKED_CREDIT,
            'awaiting_manual_supplier_payment',
            'legacy_payment_review_required',
            'The supplier invoice requires manual payment review because legacy credit payment is disabled.',
            $claimAttempt,
        );
    }

    private function finishAmbiguous(
        int $operationId,
        string $code,
        string $message,
        ?int $claimAttempt = null,
    ): void {
        $this->finish(
            $operationId,
            SupplierOperation::STATUS_AMBIGUOUS,
            'ambiguous',
            $code,
            $message,
            $claimAttempt,
        );
    }

    private function finishFailed(
        int $operationId,
        string $code,
        string $message,
        ?int $claimAttempt = null,
    ): void {
        $this->finish(
            $operationId,
            SupplierOperation::STATUS_FAILED,
            'failed',
            $code,
            $message,
            $claimAttempt,
        );
    }

    private function finish(
        int $operationId,
        string $status,
        string $step,
        string $code,
        string $message,
        ?int $claimAttempt = null,
    ): void {
        DB::transaction(function () use (
            $operationId,
            $status,
            $step,
            $code,
            $message,
            $claimAttempt,
        ): void {
            $operation = SupplierOperation::query()->lockForUpdate()->find($operationId);
            if ($operation === null
                || $operation->status !== SupplierOperation::STATUS_RUNNING
                || ($claimAttempt !== null && $operation->attempts !== $claimAttempt)) {
                return;
            }

            $this->setTerminalState($operation, $status, $step, $code, $message);
        }, 3);
    }

    private function setTerminalState(
        SupplierOperation $operation,
        string $status,
        string $step,
        ?string $code = null,
        ?string $message = null,
    ): void {
        $operation->update([
            'status' => $status,
            'step' => $step,
            'last_error_code' => $code,
            'last_error' => $message,
            'available_at' => null,
            'finished_at' => now(),
        ]);
        $this->audit($operation, 'supplier.provisioning.'.$status, [
            'step' => $step,
            'error_code' => $code,
        ]);
    }

    private function checkpoint(
        int $operationId,
        int $claimAttempt,
        string $step,
        array $attributes = [],
        bool $validateProvision = false,
    ): void {
        DB::transaction(function () use (
            $operationId,
            $claimAttempt,
            $step,
            $attributes,
            $validateProvision,
        ): void {
            $operation = SupplierOperation::query()->lockForUpdate()->findOrFail($operationId);
            if ($operation->status !== SupplierOperation::STATUS_RUNNING
                || $operation->attempts !== $claimAttempt) {
                throw new DomainException('The supplier operation is no longer running.');
            }
            if ($validateProvision) {
                $this->lockProvisionReferences($operation);
                $this->validateProvisionSnapshot($operation);
            }

            $operation->update(['step' => $step] + $attributes);
        }, 3);
    }

    private function claimIsCurrent(int $operationId, int $claimAttempt): bool
    {
        return SupplierOperation::query()
            ->whereKey($operationId)
            ->where('status', SupplierOperation::STATUS_RUNNING)
            ->where('attempts', $claimAttempt)
            ->exists();
    }

    private function references(FinanceResponse $response): array
    {
        $envelope = $response->envelope();
        $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];

        return [
            $this->responseReference($envelope, $data, 'invoiceid', 'invoice'),
            $this->responseReference($envelope, $data, 'hostid', 'host'),
        ];
    }

    private function responseReference(
        array $envelope,
        array $data,
        string $key,
        string $label,
    ): ?string {
        $identifiers = [];
        foreach ([$envelope, $data] as $source) {
            if (! array_key_exists($key, $source)) {
                continue;
            }

            $value = $source[$key];
            if (is_array($value)) {
                if (! array_is_list($value) || count($value) !== 1) {
                    throw new DomainException("The supplier returned multiple or malformed {$label} identifiers.");
                }
                $value = $value[0];
            }
            if (! is_string($value) && ! is_int($value)) {
                throw new DomainException("The supplier returned an invalid {$label} identifier.");
            }
            $identifiers[] = $this->opaqueIdentifier($value, $label);
        }

        $identifiers = array_values(array_unique($identifiers));
        if (count($identifiers) > 1) {
            throw new DomainException("The supplier returned conflicting {$label} identifiers.");
        }

        return $identifiers[0] ?? null;
    }

    private function firstIdentifier(mixed $value): ?string
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (($identifier = $this->firstIdentifier($item)) !== null) {
                    return $identifier;
                }
            }

            return null;
        }
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== ''
            && $value !== '0'
            && strlen($value) <= 128
            && ! preg_match('/[\x00-\x1f\x7f]/', $value)
                ? $value
                : null;
    }

    private function creditIsInsufficient(?int $status, string $message): bool
    {
        if (! in_array($status, [200, 400], true)) {
            return false;
        }

        return preg_match(
            '/(?:credit|balance|余额).{0,40}(?:insufficient|not\s+enough|不足|不够)|'.
            '(?:insufficient|not\s+enough|不足|不够).{0,40}(?:credit|balance|余额)/iu',
            $message,
        ) === 1;
    }

    private function retryablePreflightFinanceFailure(
        FinanceException $exception,
        string $phase,
    ): bool {
        if ($phase === 'client_construction') {
            return preg_match(
                '/unsupported driver|account is disabled|base URL|require HTTPS|not publicly routable|'.
                'base URL host|base URL port|base URL path/i',
                $exception->getMessage(),
            ) !== 1;
        }
        if (! in_array($phase, ['set_config', 'quote'], true)) {
            return false;
        }

        $endpoint = $exception->safeContext()['endpoint'] ?? null;
        if ($endpoint === '/zjmf_api_login') {
            return true;
        }

        $httpStatus = $exception->httpStatus();
        $applicationStatus = $exception->applicationStatus();
        if (in_array($httpStatus, [408, 425, 429], true)
            || ($httpStatus !== null && $httpStatus >= 500)
            || in_array($applicationStatus, [401, 403, 405, 408, 425, 429], true)
            || ($applicationStatus !== null && $applicationStatus >= 500)) {
            return true;
        }
        if ($applicationStatus !== null) {
            return false;
        }
        if ($httpStatus !== null && $httpStatus !== 200) {
            return false;
        }

        return preg_match(
            '/connection failed|could not be resolved safely|unexpected HTTP status|non-JSON response|'.
            'malformed JSON|invalid application envelope/i',
            $exception->getMessage(),
        ) === 1;
    }

    private function safePreflightError(
        SupplierOperation $operation,
        Throwable $exception,
    ): string {
        try {
            $credentials = is_array($operation->account?->credentials)
                ? $operation->account->credentials
                : [];
        } catch (Throwable) {
            $credentials = [];
        }
        $message = SupplierErrorSanitizer::sanitize(
            $exception->getMessage(),
            [$operation->request_payload ?? [], $credentials],
        );

        return mb_substr($message ?: 'Supplier preflight failed before any mutation started.', 0, 2000);
    }

    private function paymentIsConfirmed(
        SupplierOperation $operation,
        ?SupplierInvoiceLink $invoiceLink,
    ): bool {
        $metadata = is_array($operation->metadata) ? $operation->metadata : [];
        $paymentInvoiceId = $metadata['payment_invoice_id'] ?? null;
        if ((! is_string($paymentInvoiceId) && ! is_int($paymentInvoiceId))
            || $invoiceLink === null) {
            return false;
        }

        $paymentInvoiceId = $this->firstIdentifier($paymentInvoiceId);
        $linkedInvoiceId = $this->firstIdentifier($invoiceLink->upstream_invoice_id);

        $ownedInvoice = $paymentInvoiceId !== null
            && $linkedInvoiceId !== null
            && hash_equals($linkedInvoiceId, $paymentInvoiceId)
            && strtolower((string) $invoiceLink->upstream_status) === 'paid'
            && (string) $operation->supplier_invoice_link_id === (string) $invoiceLink->id
            && (string) $operation->supplier_account_id === (string) $invoiceLink->supplier_account_id
            && (string) $operation->invoice_id === (string) $invoiceLink->invoice_id;
        if (! $ownedInvoice) {
            return false;
        }
        if (array_key_exists('payment_confirmation', $metadata) === false) {
            $automaticMarkersAreExact = ($metadata['payment_confirmed'] ?? null) === true
                && ($metadata['payment_application_status'] ?? null) === 1001
                && array_key_exists('payment_confirmed_by', $metadata) === false
                && array_key_exists('payment_confirmed_at', $metadata) === false
                && array_key_exists('payment_host_id', $metadata) === false;

            if ($automaticMarkersAreExact) {
                return true;
            }
        }

        if (($metadata['payment_confirmed'] ?? null) !== true
            || ($metadata['payment_confirmation'] ?? null) !== 'admin_attested'
            || array_key_exists('payment_application_status', $metadata)) {
            return false;
        }

        $confirmedBy = $metadata['payment_confirmed_by'] ?? null;
        $confirmedAt = $metadata['payment_confirmed_at'] ?? null;
        $paymentHostId = $this->firstIdentifier($metadata['payment_host_id'] ?? null);
        $serviceLink = $operation->serviceLink;

        return is_int($confirmedBy)
            && $confirmedBy > 0
            && is_string($confirmedAt)
            && trim($confirmedAt) !== ''
            && $paymentHostId !== null
            && $serviceLink !== null
            && (string) $operation->supplier_service_link_id === (string) $serviceLink->id
            && (string) $operation->supplier_account_id === (string) $serviceLink->supplier_account_id
            && (string) $operation->service_id === (string) $serviceLink->service_id
            && hash_equals(
                $paymentHostId,
                (string) $serviceLink->upstream_service_id,
            );
    }

    private function validatedHostStatus(
        array $host,
        string $expectedHostId,
        bool $identityRequired = false,
    ): string {
        $hasIdentity = false;
        foreach (['id', 'hostid', 'host_id'] as $key) {
            if (array_key_exists($key, $host) === false) {
                continue;
            }

            $hasIdentity = true;
            if (is_string($host[$key]) === false && is_int($host[$key]) === false) {
                if ($identityRequired) {
                    throw new UnexpectedValueException('The supplier returned an invalid host identity.');
                }

                throw new DomainException('The supplier returned a different host record.');
            }
            try {
                $hostId = $this->opaqueIdentifier($host[$key], 'host');
            } catch (InvalidArgumentException $exception) {
                if (! $identityRequired) {
                    throw $exception;
                }

                throw new UnexpectedValueException(
                    'The supplier returned an invalid host identity.',
                    0,
                    $exception,
                );
            }
            if (hash_equals($expectedHostId, $hostId) === false) {
                if ($identityRequired) {
                    throw new UnexpectedValueException('The supplier returned a different host record.');
                }

                throw new DomainException('The supplier returned a different host record.');
            }
        }
        if ($identityRequired && ! $hasIdentity) {
            throw new UnexpectedValueException('The supplier host response did not contain an identity.');
        }

        $status = is_string($host['domainstatus'] ?? null)
            ? trim($host['domainstatus'])
            : '';
        if ($status === ''
            || mb_strlen($status) > 32
            || preg_match('/[\x00-\x1f\x7f]/', $status)) {
            throw new DomainException('The supplier host response did not contain a valid status.');
        }

        return $status;
    }

    private function hasPaymentConfirmationEvidence(
        SupplierOperation $operation,
        ?SupplierInvoiceLink $invoiceLink,
    ): bool {
        $metadata = is_array($operation->metadata) ? $operation->metadata : [];
        $response = is_array($operation->response_payload) ? $operation->response_payload : [];

        return array_key_exists('payment_confirmed', $metadata)
            || array_key_exists('payment_application_status', $metadata)
            || array_key_exists('payment_invoice_id', $metadata)
            || array_key_exists('payment_confirmation', $metadata)
            || array_key_exists('payment_confirmed_by', $metadata)
            || array_key_exists('payment_host_id', $metadata)
            || array_key_exists('payment_confirmed_at', $metadata)
            || strtolower((string) $invoiceLink?->upstream_status) === 'paid'
            || (in_array($response['endpoint'] ?? null, ['apply_credit', 'apply_credit_recovery'], true)
                && ($response['status'] ?? null) === 1001);
    }

    private function safeResponse(string $endpoint, FinanceResponse $response, array $payload): array
    {
        [$invoiceId, $hostId] = $this->references($response);

        return array_filter([
            'endpoint' => $endpoint,
            'status' => $response->status,
            'message' => SupplierErrorSanitizer::sanitize($response->message, [$payload]),
            'invoice_id' => $invoiceId,
            'host_id' => $hostId,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function safeHostResponse(FinanceResponse $response, array $host): array
    {
        return [
            'endpoint' => 'host_header',
            'status' => $response->status,
            'host' => $this->safeHostData($host),
        ];
    }

    private function safeHostData(array $host): array
    {
        $status = is_string($host['domainstatus'] ?? null) ? trim($host['domainstatus']) : '';
        $domain = is_string($host['domain'] ?? null) ? trim($host['domain']) : '';
        $dedicatedIp = is_string($host['dedicatedip'] ?? null) ? trim($host['dedicatedip']) : '';
        $registeredAt = array_key_exists('regdate', $host)
            ? $this->upstreamDate($host['regdate'], 'registration')
            : null;
        $nextDueAt = array_key_exists('nextduedate', $host)
            ? $this->upstreamDate($host['nextduedate'], 'next due')
            : null;
        if ($registeredAt !== null && $registeredAt->isAfter(now()->addMinutes(5))) {
            throw new InvalidArgumentException('The supplier registration date is unexpectedly in the future.');
        }
        if ($nextDueAt !== null && ! $nextDueAt->isAfter(now())) {
            throw new InvalidArgumentException('The supplier next due date is not in the future.');
        }
        if ($registeredAt !== null && $nextDueAt !== null && ! $nextDueAt->isAfter($registeredAt)) {
            throw new InvalidArgumentException('The supplier host term dates are inconsistent.');
        }

        return array_filter([
            'status' => $status === '' ? null : mb_substr($status, 0, 32),
            'domain' => $domain !== ''
                && mb_strlen($domain) <= 253
                && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
                    ? $domain
                    : null,
            'dedicated_ip' => filter_var($dedicatedIp, FILTER_VALIDATE_IP) ? $dedicatedIp : null,
            'assigned_ips' => $this->safeAssignedIps($host['assignedips'] ?? $host['assigned_ips'] ?? []),
            'registered_at_timestamp' => $registeredAt?->getTimestamp(),
            'next_due_at_timestamp' => $nextDueAt?->getTimestamp(),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function activationAttributes(Service $service, array $host): array
    {
        $hasActivation = $service->activated_at !== null;
        $upstreamRegisteredAt = isset($host['registered_at_timestamp'])
            ? Carbon::createFromTimestamp((int) $host['registered_at_timestamp'], config('app.timezone'))
            : null;
        $upstreamNextDueAt = isset($host['next_due_at_timestamp'])
            ? Carbon::createFromTimestamp((int) $host['next_due_at_timestamp'], config('app.timezone'))
            : null;
        $activatedAt = $hasActivation
            ? $service->activated_at->copy()
            : ($upstreamRegisteredAt ?? now());
        if ($upstreamNextDueAt !== null && ! $upstreamNextDueAt->isAfter($activatedAt)) {
            throw new InvalidArgumentException('The supplier next due date does not follow activation.');
        }
        $anchorDay = $hasActivation
            ? ($service->billing_anchor_day ?? $activatedAt->day)
            : $activatedAt->day;
        $nextDueAt = $hasActivation && $service->next_due_at !== null
            ? $service->next_due_at
            : ($upstreamNextDueAt
                ?? $this->nextDueAt($activatedAt, (string) $service->billing_cycle, $anchorDay));
        if (! $hasActivation && $nextDueAt !== null && ! $nextDueAt->isAfter(now())) {
            throw new InvalidArgumentException('The supplier service term is already due.');
        }

        return [
            'registered_at' => $hasActivation
                ? $service->registered_at
                : ($upstreamRegisteredAt ?? $service->registered_at ?? $activatedAt),
            'activated_at' => $activatedAt,
            'billing_anchor_day' => $anchorDay,
            'next_due_at' => $nextDueAt,
        ] + array_filter([
            'domain' => $host['domain'] ?? null,
            'dedicated_ip' => $host['dedicated_ip'] ?? null,
            'assigned_ips' => $host['assigned_ips'] ?? null,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function upstreamDate(mixed $value, string $label): Carbon
    {
        $timezone = new DateTimeZone((string) config('app.timezone', 'UTC'));
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $timestamp = filter_var($value, FILTER_VALIDATE_INT);
            if ($timestamp === false
                || $timestamp < self::MIN_UPSTREAM_TIMESTAMP
                || $timestamp > self::MAX_UPSTREAM_TIMESTAMP) {
                throw new InvalidArgumentException("The supplier {$label} timestamp is invalid.");
            }

            return Carbon::createFromTimestamp($timestamp, $timezone);
        }
        if (! is_string($value)
            || $value === ''
            || strlen($value) > 35
            || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException("The supplier {$label} date is invalid.");
        }

        foreach ([
            ['!Y-m-d', 'Y-m-d', $timezone],
            ['!Y-m-d H:i:s', 'Y-m-d H:i:s', $timezone],
            ['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:sP', $timezone],
            ['!Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i:s\Z', new DateTimeZone('UTC')],
        ] as [$format, $comparisonFormat, $parseTimezone]) {
            $date = DateTimeImmutable::createFromFormat($format, $value, $parseTimezone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date === false
                || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
                || $date->format($comparisonFormat) !== $value) {
                continue;
            }
            $timestamp = $date->getTimestamp();
            if ($timestamp < self::MIN_UPSTREAM_TIMESTAMP || $timestamp > self::MAX_UPSTREAM_TIMESTAMP) {
                throw new InvalidArgumentException("The supplier {$label} date is outside the accepted range.");
            }

            return Carbon::instance($date)->setTimezone($timezone);
        }

        throw new InvalidArgumentException("The supplier {$label} date format is invalid.");
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

    private function safeAssignedIps(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/', substr($value, 0, 4096), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $ip): ?string => is_string($ip) && filter_var(trim($ip), FILTER_VALIDATE_IP)
                ? trim($ip)
                : null,
            array_slice($value, 0, 64),
        ))));
    }

    private function requiredArray(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        if (! is_array($value)) {
            throw new InvalidArgumentException("The supplier {$key} snapshot is invalid.");
        }

        return $value;
    }

    private function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }

    private function requiredPositiveId(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if ((! is_int($value) && ! (is_string($value) && ctype_digit($value)))
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new InvalidArgumentException("The supplier {$key} snapshot is invalid.");
        }

        return (int) $value;
    }

    private function requiredAmount(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException("The supplier {$key} monetary snapshot is invalid.");
        }
        $value = is_float($value) ? number_format($value, 2, '.', '') : trim((string) $value);
        if (preg_match('/\A\d{1,16}(?:\.\d{1,2})?\z/', $value) !== 1) {
            throw new InvalidArgumentException("The supplier {$key} monetary snapshot is invalid.");
        }

        try {
            return Money::format(Money::toMinor($value));
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(
                "The supplier {$key} monetary snapshot is invalid.",
                0,
                $exception,
            );
        }
    }

    private function requiredCurrency(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value)
            || preg_match('/\A[A-Za-z0-9]{3,8}\z/', trim($value)) !== 1) {
            throw new InvalidArgumentException("The supplier {$key} currency snapshot is invalid.");
        }

        return strtoupper(trim($value));
    }

    private function validateQuote(
        array $route,
        FinanceResponse $response,
        array $parameters,
    ): array {
        $upstream = $this->requiredArray($route, 'upstream');
        $quote = $response->data['quote'] ?? null;
        if (! is_array($quote)
            || ! $this->hasExactKeys($quote, ['amount', 'currency', 'source'])) {
            throw new DomainException('The supplier quote does not contain an authoritative payable total.');
        }

        $amount = $this->requiredAmount($quote, 'amount');
        $currency = $this->requiredCurrency($quote, 'currency');
        $expectedAmount = $this->requiredAmount($upstream, 'expected_amount');
        $expectedCurrency = $this->requiredCurrency($upstream, 'currency');
        $source = $this->requiredIdentifier($quote, 'source', 64);
        if (! in_array($source, [
            'data.sale_total',
            'sale_total',
            'products.total',
            'data.products.total',
            'data.total',
            'total',
        ], true)) {
            throw new DomainException('The supplier quote total source is unsupported.');
        }
        if (! hash_equals($expectedCurrency, $currency)) {
            throw new DomainException('The supplier quote currency does not match the frozen route.');
        }
        if (Money::toMinor($amount) > Money::toMinor($expectedAmount)) {
            throw new DomainException('The supplier quote exceeds the frozen upstream amount.');
        }

        return [
            'endpoint' => '/cart/get_total',
            'status' => $response->status,
            'amount' => $amount,
            'currency' => $currency,
            'source' => $source,
            'expected_amount' => $expectedAmount,
            'request_hash' => hash('sha256', json_encode(
                $parameters,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )),
        ];
    }

    private function mappingConfigOptions(array $mapping): array
    {
        $options = $mapping['options'] ?? null;
        if (! is_array($options)) {
            throw new InvalidArgumentException('The supplier mapping options snapshot is invalid.');
        }

        $configOptions = $options['configoption'] ?? $options['config_options'] ?? $options;
        if (! is_array($configOptions)) {
            throw new InvalidArgumentException('The supplier mapping configuration snapshot is invalid.');
        }

        return $configOptions;
    }

    private function requiredIdentifier(array $payload, string $key, int $maximumLength): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException("The supplier {$key} snapshot is invalid.");
        }
        $value = trim((string) $value);
        if ($value === ''
            || strlen($value) > $maximumLength
            || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException("The supplier {$key} snapshot is invalid.");
        }

        return $value;
    }

    private function legacyCreditPaymentIsAllowed(int $operationId, int $claimAttempt): bool
    {
        return DB::transaction(function () use ($operationId, $claimAttempt): bool {
            $operation = SupplierOperation::query()->lockForUpdate()->findOrFail($operationId);
            if ($operation->status !== SupplierOperation::STATUS_RUNNING
                || $operation->attempts !== $claimAttempt) {
                throw new DomainException('The supplier operation claim is no longer current.');
            }

            return $operation->account()->lockForUpdate()->firstOrFail()
                ->allowsLegacyUnboundedCreditPayment();
        }, 3);
    }

    private function withAccountLock(int $accountId, callable $callback): bool
    {
        return Cache::lock('supplier-account:'.$accountId, self::ACCOUNT_LOCK_SECONDS)->get($callback) === true;
    }

    private function limit(int $limit): int
    {
        return max(1, min(1000, $limit));
    }

    private function audit(SupplierOperation $operation, string $action, array $after = []): void
    {
        AuditLog::create([
            'actor_id' => null,
            'action' => $action,
            'subject_type' => SupplierOperation::class,
            'subject_id' => $operation->id,
            'after' => ['status' => $operation->status] + $after,
            'ip_address' => null,
            'user_agent' => null,
        ]);
    }
}
