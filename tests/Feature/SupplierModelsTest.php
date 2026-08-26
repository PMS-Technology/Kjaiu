<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Models\SupplierAccount;
use App\Models\SupplierCatalogProduct;
use App\Models\SupplierInvoiceLink;
use App\Models\SupplierOperation;
use App\Models\SupplierOrderItemRoute;
use App\Models\SupplierProductMapping;
use App\Models\SupplierServiceLink;
use App\Models\User;
use App\Services\SupplierProvisioningOutbox;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounts_and_catalog_products_preserve_admin_and_sync_state_safely(): void
    {
        $testedAt = now()->subMinutes(3)->startOfSecond();
        $connectedAt = now()->subMinutes(2)->startOfSecond();
        $catalogSyncedAt = now()->subMinute()->startOfSecond();
        $account = SupplierAccount::create([
            'code' => ' primary ',
            'name' => 'Primary supplier',
            'driver' => SupplierAccount::DRIVER_IDCSMART_FINANCE,
            'base_url' => 'https://supplier.test',
            'credentials' => [
                'username' => 'api-user',
                'password' => 'supplier-secret',
                'api_key' => 'supplier-api-key',
            ],
            'options' => ['region' => 'global'],
            'last_tested_at' => $testedAt,
            'last_connected_at' => $connectedAt,
            'last_catalog_synced_at' => $catalogSyncedAt,
            'last_error' => 'Login supplier-secret failed; token=supplier-api-key; Bearer response-token',
        ]);
        $lastSeenAt = now()->subSeconds(30)->startOfSecond();
        $syncedAt = now()->startOfSecond();
        $catalog = SupplierCatalogProduct::createForAccount($account, [
            'upstream_product_id' => ' 00042/A ',
            'upstream_group_id' => 'group:compute',
            'type' => 'server',
            'name' => 'Compute',
            'description' => 'Upstream compute product',
            'currency' => 'CNY',
            'minimum_price' => '9.50',
            'billing_cycles' => ['monthly', 'annually'],
            'metadata' => ['source' => 'sync'],
            'last_seen_at' => $lastSeenAt,
            'synced_at' => $syncedAt,
        ]);
        $generated = SupplierAccount::create([
            'name' => 'Generated code',
            'base_url' => 'https://generated.test',
        ]);
        $anotherGenerated = SupplierAccount::create([
            'name' => 'Another generated code',
            'base_url' => 'https://generated-2.test',
        ]);

        $rawCredentials = DB::table('supplier_accounts')->where('id', $account->id)->value('credentials');
        $this->assertSame('primary', $account->code);
        $this->assertMatchesRegularExpression('/\Asupplier-[a-z0-9]{24}\z/', $generated->code);
        $this->assertNotSame($generated->code, $anotherGenerated->code);
        $this->assertSame($testedAt->toDateTimeString(), $account->last_tested_at->toDateTimeString());
        $this->assertSame($connectedAt->toDateTimeString(), $account->last_connected_at->toDateTimeString());
        $this->assertSame($catalogSyncedAt->toDateTimeString(), $account->last_catalog_synced_at->toDateTimeString());
        $this->assertStringNotContainsString('supplier-secret', $account->last_error);
        $this->assertStringNotContainsString('supplier-api-key', $account->last_error);
        $this->assertStringNotContainsString('response-token', $account->last_error);
        $this->assertStringContainsString('[REDACTED]', $account->last_error);
        $this->assertStringNotContainsString('supplier-secret', $rawCredentials);
        $this->assertSame('supplier-secret', $account->fresh()->credentials['password']);
        $this->assertArrayNotHasKey('credentials', $account->toArray());

        $this->assertSame('00042/A', $catalog->upstream_product_id);
        $this->assertSame('group:compute', $catalog->upstream_group_id);
        $this->assertSame('server', $catalog->type);
        $this->assertSame('9.50', $catalog->minimum_price);
        $this->assertSame(['monthly', 'annually'], $catalog->billing_cycles);
        $this->assertSame($lastSeenAt->toDateTimeString(), $catalog->last_seen_at->toDateTimeString());
        $this->assertSame($syncedAt->toDateTimeString(), $catalog->synced_at->toDateTimeString());
        $this->assertSame($account->id, $catalog->account->id);
        $this->assertArrayNotHasKey('billing_cycle', $catalog->toArray());

        $this->assertDomainException(
            fn () => SupplierAccount::create([
                'code' => '  ',
                'name' => 'Blank code',
                'base_url' => 'https://blank.test',
            ]),
            'A non-empty supplier account code is required.',
        );
        $this->assertQueryException(fn () => SupplierAccount::create([
            'code' => 'primary',
            'name' => 'Duplicate code',
            'base_url' => 'https://duplicate.test',
        ]));
    }

    public function test_product_mappings_keep_history_but_allow_only_one_active_local_route(): void
    {
        $local = $this->localRecords('mapping');
        $account = $this->account('Mapping');
        $catalog = SupplierCatalogProduct::createForAccount($account, [
            'upstream_product_id' => 'catalog-mapping',
            'billing_cycles' => ['monthly', 'annually'],
        ]);
        $monthly = SupplierProductMapping::createFor($account, $catalog, $local['product'], [
            'local_billing_cycle' => ' monthly ',
            'upstream_billing_cycle' => 'month',
            'options' => ['promotion' => 'standard'],
        ]);
        $annually = SupplierProductMapping::createFor($account, $catalog, $local['product'], [
            'local_billing_cycle' => 'annually',
            'upstream_billing_cycle' => 'year',
        ]);

        $this->assertSame('monthly', $monthly->local_billing_cycle);
        $this->assertSame('month', $monthly->upstream_billing_cycle);
        $this->assertSame(['promotion' => 'standard'], $monthly->options);
        $this->assertSame($local['product']->id, $monthly->product->id);
        $this->assertSame($catalog->id, $monthly->catalogProduct->id);
        $this->assertSame(['annually', 'monthly'], $account->productMappings()
            ->orderBy('local_billing_cycle')
            ->pluck('local_billing_cycle')
            ->all());

        $guarded = new SupplierProductMapping([
            'supplier_account_id' => $account->id,
            'supplier_catalog_product_id' => $catalog->id,
            'product_id' => $local['product']->id,
            'local_billing_cycle' => 'quarterly',
            'upstream_billing_cycle' => 'quarter',
        ]);
        $this->assertNull($guarded->supplier_account_id);
        $this->assertNull($guarded->supplier_catalog_product_id);
        $this->assertNull($guarded->product_id);

        $this->assertDomainException(
            fn () => SupplierProductMapping::createFor($account, $catalog, $local['product']),
            'Both local and upstream billing cycles are required.',
        );
        $this->assertDomainException(
            fn () => SupplierProductMapping::createFor(
                $account,
                $catalog,
                $local['product'],
                [
                    'local_billing_cycle' => 'monthly',
                    'upstream_billing_cycle' => 'monthly-special',
                ],
            ),
            'The local product and billing cycle already have an active supplier mapping.',
        );
        $historical = SupplierProductMapping::createFor($account, $catalog, $local['product'], [
            'local_billing_cycle' => 'monthly',
            'upstream_billing_cycle' => 'monthly-historical',
            'is_active' => false,
        ]);
        $this->assertFalse($historical->is_active);
        $otherAccount = $this->account('Mapping duplicate');
        $otherCatalog = SupplierCatalogProduct::createForAccount($otherAccount, [
            'upstream_product_id' => 'catalog-mapping-duplicate',
        ]);
        $this->assertQueryException(fn () => DB::table('supplier_product_mappings')->insert([
            'supplier_account_id' => $otherAccount->id,
            'supplier_catalog_product_id' => $otherCatalog->id,
            'product_id' => $local['product']->id,
            'local_billing_cycle' => 'monthly',
            'upstream_billing_cycle' => 'monthly',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        $this->assertSame(3, SupplierProductMapping::query()->count());
        $this->assertSame($annually->id, $account->productMappings()->where('local_billing_cycle', 'annually')->sole()->id);
    }

    public function test_remote_links_normalize_opaque_ids_and_require_the_available_identifier(): void
    {
        $local = $this->localRecords('links');
        $account = $this->account('Links');
        $serviceLink = SupplierServiceLink::createFor($account, $local['service'], null, [
            'upstream_service_id' => 7001,
            'metadata' => ['source' => 'settlement'],
        ]);
        $orderInvoice = $this->invoice($local['user'], 'order-only');
        $invoiceInvoice = $this->invoice($local['user'], 'invoice-only');
        $orderLink = SupplierInvoiceLink::createFor($account, $orderInvoice, null, [
            'upstream_order_id' => 8001,
            'upstream_invoice_id' => ' ',
        ]);
        $invoiceLink = SupplierInvoiceLink::createFor($account, $invoiceInvoice, $serviceLink, [
            'upstream_order_id' => '',
            'upstream_invoice_id' => ' invoice/8002 ',
            'upstream_status' => 'Unpaid',
        ]);
        $secondInvoiceLink = SupplierInvoiceLink::createFor($account, $invoiceInvoice, $serviceLink, [
            'upstream_invoice_id' => 'invoice/8003',
            'upstream_status' => 'Unpaid',
        ]);

        $this->assertSame('7001', $serviceLink->upstream_service_id);
        $this->assertNull($serviceLink->supplier_product_mapping_id);
        $this->assertNull($serviceLink->upstream_status);
        $this->assertSame($local['service']->id, $serviceLink->service->id);
        $this->assertSame('8001', $orderLink->upstream_order_id);
        $this->assertNull($orderLink->upstream_invoice_id);
        $this->assertNull($invoiceLink->upstream_order_id);
        $this->assertSame('invoice/8002', $invoiceLink->upstream_invoice_id);
        $this->assertSame($serviceLink->id, $invoiceLink->serviceLink->id);
        $this->assertSame($invoiceInvoice->id, $invoiceLink->invoice->id);
        $this->assertSame($invoiceInvoice->id, $secondInvoiceLink->invoice->id);
        $this->assertCount(2, $invoiceInvoice->supplierInvoiceLinks);

        $this->assertDomainException(
            fn () => SupplierServiceLink::createFor($account, $local['service'], null, [
                'upstream_service_id' => ' ',
            ]),
            'A non-empty upstream service ID is required.',
        );
        $this->assertDomainException(
            fn () => SupplierInvoiceLink::createFor(
                $account,
                $this->invoice($local['user'], 'missing-identifiers'),
                null,
                ['upstream_order_id' => ' ', 'upstream_invoice_id' => null],
            ),
            'An upstream order or invoice ID is required.',
        );
        $this->assertQueryException(fn () => SupplierInvoiceLink::createFor(
            $account,
            $this->invoice($local['user'], 'duplicate-order'),
            null,
            ['upstream_order_id' => '8001'],
        ));
        $this->assertQueryException(fn () => SupplierInvoiceLink::createFor(
            $account,
            $this->invoice($local['user'], 'duplicate-invoice'),
            null,
            ['upstream_invoice_id' => 'invoice/8002'],
        ));

        $this->assertQueryException(fn () => $local['service']->delete());
        $this->assertQueryException(fn () => $orderInvoice->delete());
        $this->assertDatabaseHas('services', ['id' => $local['service']->id]);
        $this->assertDatabaseHas('invoices', ['id' => $orderInvoice->id]);
    }

    public function test_operations_can_be_recorded_before_remote_links_exist(): void
    {
        $local = $this->localRecords('operation');
        $account = $this->account('Operation');
        $catalog = SupplierCatalogProduct::createForAccount($account, [
            'upstream_product_id' => 'catalog-operation',
        ]);
        $mapping = SupplierProductMapping::createFor($account, $catalog, $local['product'], [
            'local_billing_cycle' => 'monthly',
            'upstream_billing_cycle' => 'monthly',
        ]);
        $request = ['hostname' => 'node-1', 'password' => 'instance-secret'];
        $response = ['authorization' => 'Bearer upstream-token'];
        $operation = SupplierOperation::createFor(
            account: $account,
            attributes: [
                'action' => SupplierOperation::ACTION_PROVISION,
                'status' => SupplierOperation::STATUS_QUEUED,
                'step' => 'settlement',
                'idempotency_key' => 'provision-operation-1',
                'request_payload' => $request,
                'response_payload' => $response,
                'last_error_code' => 'UPSTREAM_500',
                'last_error' => 'Provisioning instance-secret failed with token=upstream-token',
                'attempts' => 2,
                'metadata' => ['source' => 'invoice-paid'],
                'available_at' => now()->subMinute(),
                'started_at' => now(),
            ],
            productMapping: $mapping,
            order: $local['order'],
            orderItem: $local['orderItem'],
            invoice: $local['invoice'],
            service: $local['service'],
        );
        $expectedHash = hash('sha256', json_encode(
            $request,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        $raw = DB::table('supplier_operations')->where('id', $operation->id)->first();
        $this->assertSame($expectedHash, $operation->request_hash);
        $this->assertStringNotContainsString('instance-secret', $raw->request_payload);
        $this->assertStringNotContainsString('upstream-token', $raw->response_payload);
        $this->assertStringNotContainsString('instance-secret', $raw->last_error);
        $this->assertStringNotContainsString('upstream-token', $raw->last_error);
        $this->assertStringContainsString('[REDACTED]', $raw->last_error);
        $this->assertSame($request, $operation->fresh()->request_payload);
        $this->assertSame($response, $operation->fresh()->response_payload);
        $this->assertArrayNotHasKey('request_payload', $operation->toArray());
        $this->assertArrayNotHasKey('response_payload', $operation->toArray());
        $this->assertNull($operation->supplier_service_link_id);
        $this->assertNull($operation->supplier_invoice_link_id);
        $this->assertSame($mapping->id, $operation->productMapping->id);
        $this->assertSame($local['order']->id, $operation->order->id);
        $this->assertSame($local['orderItem']->id, $operation->orderItem->id);
        $this->assertSame($local['invoice']->id, $operation->invoice->id);
        $this->assertSame($local['service']->id, $operation->service->id);
        $this->assertSame($operation->id, $mapping->operations()->sole()->id);
        $this->assertSame('UPSTREAM_500', $operation->last_error_code);
        $this->assertSame([
            'provision',
            'renew',
            'suspend',
            'unsuspend',
            'cancel',
            'sync',
        ], SupplierOperation::ACTIONS);
        $this->assertSame([
            'queued',
            'running',
            'blocked_credit',
            'awaiting_confirmation',
            'ambiguous',
            'succeeded',
            'failed',
        ], SupplierOperation::STATUSES);
        $this->assertSame([
            'queued',
            'running',
            'awaiting_confirmation',
            'blocked_credit',
            'ambiguous',
        ], SupplierOperation::NONTERMINAL_STATUSES);

        $accountOnly = SupplierOperation::createFor($account, [
            'action' => SupplierOperation::ACTION_SYNC,
            'idempotency_key' => 'sync-operation-1',
        ]);
        $this->assertNull($accountOnly->supplier_product_mapping_id);
        $this->assertNull($accountOnly->supplier_service_link_id);
        $this->assertNull($accountOnly->supplier_invoice_link_id);
        $this->assertSame(hash('sha256', '[]'), $accountOnly->request_hash);

        $this->assertDomainException(
            fn () => SupplierOperation::createFor($account, [
                'action' => SupplierOperation::ACTION_SYNC,
                'idempotency_key' => 'bad-request-hash',
                'request_hash' => str_repeat('0', 64),
                'request_payload' => ['scope' => 'catalog'],
            ]),
            'The supplier operation request hash does not match its payload.',
        );
        $this->assertQueryException(fn () => SupplierOperation::createFor($account, [
            'action' => SupplierOperation::ACTION_PROVISION,
            'idempotency_key' => 'provision-operation-1',
            'request_payload' => $request,
        ]));
        $this->assertQueryException(fn () => $local['order']->delete());
        $this->assertDatabaseHas('orders', ['id' => $local['order']->id]);
    }

    public function test_provisioning_outbox_keeps_an_immutable_mapping_snapshot(): void
    {
        $local = $this->localRecords('snapshot');
        $account = $this->account('Snapshot');
        $catalog = SupplierCatalogProduct::createForAccount($account, [
            'upstream_product_id' => 'upstream-snapshot',
            'currency' => 'CNY',
            'billing_cycles' => ['month'],
            'metadata' => [
                'prices' => [
                    'month' => ['price' => '10.00'],
                ],
            ],
        ]);
        $mapping = SupplierProductMapping::createFor($account, $catalog, $local['product'], [
            'local_billing_cycle' => 'monthly',
            'upstream_billing_cycle' => 'month',
            'options' => ['configoption' => ['image' => 'ubuntu']],
        ]);
        [$route, $operation] = DB::transaction(function () use ($local, $mapping): array {
            $outbox = app(SupplierProvisioningOutbox::class);
            $mapping->load(['account', 'catalogProduct']);
            $route = $outbox->freezeRoute($local['orderItem'], $mapping, 'CNY');

            return [$route, $outbox->queueProvision(
                $local['invoice'],
                $local['orderItem'],
                $local['service'],
                $route,
            )];
        });

        $snapshot = $operation->request_payload['route'];
        $this->assertSame([
            'options' => ['configoption' => ['image' => 'ubuntu']],
            'supplier_catalog_product_id' => $catalog->id,
            'supplier_product_mapping_id' => $mapping->id,
        ], $snapshot['mapping']);
        $this->assertSame($route->id, $operation->supplier_order_item_route_id);
        $this->assertSame('monthly', $snapshot['local']['billing_cycle']);
        $this->assertSame($local['product']->id, $snapshot['local']['product_id']);
        $this->assertSame('month', $snapshot['upstream']['billing_cycle']);
        $this->assertSame('upstream-snapshot', $snapshot['upstream']['product_id']);
        $this->assertSame('10.00', $snapshot['upstream']['expected_amount']);
        $otherAccount = $this->account('SnapshotOther');
        $this->assertQueryException(fn () => DB::table('supplier_operations')->insert([
            'supplier_account_id' => $otherAccount->id,
            'supplier_order_item_route_id' => $route->id,
            'action' => SupplierOperation::ACTION_SYNC,
            'status' => SupplierOperation::STATUS_QUEUED,
            'idempotency_key' => 'cross-account-route',
            'request_hash' => hash('sha256', '[]'),
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        $rawRoute = DB::table('supplier_order_item_routes')->where('id', $route->id)->first();
        $this->assertStringNotContainsString('upstream-snapshot', $rawRoute->snapshot);
        $this->assertStringNotContainsString('ubuntu', $rawRoute->snapshot);
        $this->assertArrayNotHasKey('snapshot', $route->toArray());
        $this->assertDomainException(function () use ($route): void {
            $route->local_currency = 'USD';
            $route->save();
        }, 'Supplier order item routes are immutable.');
        $route->refresh();
        $this->assertDomainException(
            fn () => $route->delete(),
            'Supplier order item routes are immutable.',
        );

        $operation->update([
            'status' => SupplierOperation::STATUS_SUCCEEDED,
            'finished_at' => now(),
        ]);
        $this->assertDomainException(
            fn () => $mapping->update([
                'upstream_billing_cycle' => 'year',
                'options' => ['configoption' => ['image' => 'debian']],
            ]),
            'Supplier mappings with historical references cannot change routing identity.',
        );
        $mapping->refresh();
        $mapping->update(['is_active' => false]);
        $replacementCatalog = SupplierCatalogProduct::createForAccount($account, [
            'upstream_product_id' => 'upstream-snapshot-replacement',
            'currency' => 'CNY',
            'billing_cycles' => ['year'],
        ]);
        $replacement = SupplierProductMapping::createFor(
            $account,
            $replacementCatalog,
            $local['product'],
            [
                'local_billing_cycle' => 'monthly',
                'upstream_billing_cycle' => 'year',
            ],
        );

        $this->assertSame($snapshot, $operation->fresh()->request_payload['route']);
        $this->assertSame('month', $operation->request_payload['route']['upstream']['billing_cycle']);
        $this->assertSame(
            ['image' => 'ubuntu'],
            $operation->request_payload['route']['upstream']['configoption'],
        );
        $this->assertSame($snapshot, SupplierOrderItemRoute::query()->findOrFail($route->id)->validatedSnapshot());
        $this->assertFalse($mapping->fresh()->is_active);
        $this->assertTrue($replacement->is_active);
        $this->assertSame($mapping->id, $route->fresh()->supplier_product_mapping_id);
    }

    public function test_operation_helpers_and_composite_keys_reject_inconsistent_references(): void
    {
        $local = $this->localRecords('ownership');
        $otherLocal = $this->localRecords('other');
        $first = $this->account('First');
        $second = $this->account('Second');
        $firstCatalog = SupplierCatalogProduct::createForAccount($first, [
            'upstream_product_id' => 'first-product',
        ]);
        $secondCatalog = SupplierCatalogProduct::createForAccount($second, [
            'upstream_product_id' => 'second-product',
        ]);
        $firstMapping = SupplierProductMapping::createFor($first, $firstCatalog, $local['product'], [
            'local_billing_cycle' => 'monthly',
            'upstream_billing_cycle' => 'monthly',
        ]);
        $secondMapping = SupplierProductMapping::createFor($second, $secondCatalog, $otherLocal['product'], [
            'local_billing_cycle' => 'monthly',
            'upstream_billing_cycle' => 'monthly',
        ]);
        $firstService = SupplierServiceLink::createFor($first, $local['service'], $firstMapping, [
            'upstream_service_id' => 'first-service',
        ]);
        $secondService = SupplierServiceLink::createFor($second, $otherLocal['service'], $secondMapping, [
            'upstream_service_id' => 'second-service',
        ]);
        $secondInvoice = SupplierInvoiceLink::createFor($second, $otherLocal['invoice'], $secondService, [
            'upstream_invoice_id' => 'second-invoice',
        ]);

        $invalid = [
            [
                fn () => SupplierProductMapping::createFor($first, $secondCatalog, $local['product'], [
                    'local_billing_cycle' => 'annually',
                    'upstream_billing_cycle' => 'annually',
                ]),
                'Cross-account supplier references are not allowed.',
            ],
            [
                fn () => SupplierServiceLink::createFor($first, $otherLocal['service'], $secondMapping, [
                    'upstream_service_id' => 'invalid-service',
                ]),
                'Cross-account supplier references are not allowed.',
            ],
            [
                fn () => SupplierInvoiceLink::createFor(
                    $first,
                    $otherLocal['invoice'],
                    $secondService,
                    ['upstream_invoice_id' => 'invalid-invoice'],
                ),
                'Cross-account supplier references are not allowed.',
            ],
            [
                fn () => SupplierOperation::createFor(
                    account: $first,
                    attributes: $this->operationAttributes('cross-account-mapping'),
                    productMapping: $secondMapping,
                ),
                'Cross-account supplier references are not allowed.',
            ],
            [
                fn () => SupplierOperation::createFor(
                    account: $first,
                    attributes: $this->operationAttributes('wrong-order-item'),
                    order: $local['order'],
                    orderItem: $otherLocal['orderItem'],
                ),
                'The order item does not belong to the supplied order.',
            ],
            [
                fn () => SupplierOperation::createFor(
                    account: $first,
                    attributes: $this->operationAttributes('wrong-service-link'),
                    service: $otherLocal['service'],
                    serviceLink: $firstService,
                ),
                'The supplier service link does not reference the supplied service.',
            ],
            [
                fn () => SupplierOperation::createFor(
                    account: $second,
                    attributes: $this->operationAttributes('wrong-invoice-link'),
                    invoice: $local['invoice'],
                    invoiceLink: $secondInvoice,
                ),
                'The supplier invoice link does not reference the supplied invoice.',
            ],
            [
                fn () => SupplierOperation::createFor(
                    account: $first,
                    attributes: $this->operationAttributes('unsaved-order'),
                    order: new Order(['user_id' => $local['user']->id]),
                ),
                'A persisted order is required.',
            ],
        ];

        foreach ($invalid as [$callback, $message]) {
            $this->assertDomainException($callback, $message);
        }

        $this->assertQueryException(fn () => DB::table('supplier_product_mappings')->insert([
            'supplier_account_id' => $first->id,
            'supplier_catalog_product_id' => $secondCatalog->id,
            'product_id' => $local['product']->id,
            'local_billing_cycle' => 'quarterly',
            'upstream_billing_cycle' => 'quarterly',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        $this->assertQueryException(fn () => DB::table('supplier_operations')->insert([
            'supplier_account_id' => $first->id,
            'supplier_product_mapping_id' => $secondMapping->id,
            'action' => SupplierOperation::ACTION_SYNC,
            'status' => SupplierOperation::STATUS_QUEUED,
            'idempotency_key' => 'database-cross-account',
            'request_hash' => hash('sha256', '[]'),
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->assertSame(2, SupplierProductMapping::query()->count());
        $this->assertSame(2, SupplierServiceLink::query()->count());
        $this->assertSame(1, SupplierInvoiceLink::query()->count());
        $this->assertSame(0, SupplierOperation::query()->count());
        $this->assertQueryException(fn () => $first->delete());
        $this->assertDatabaseHas('supplier_accounts', ['id' => $first->id]);
        $this->assertSame($firstService->id, $first->serviceLinks()->sole()->id);
    }

    public function test_operation_eloquent_boundary_rejects_malformed_same_account_route_references(): void
    {
        $firstLocal = $this->localRecords('route-boundary-first');
        $secondLocal = $this->localRecords('route-boundary-second');
        $account = $this->account('RouteBoundary');
        $firstCatalog = SupplierCatalogProduct::createForAccount($account, [
            'upstream_product_id' => 'route-boundary-first',
            'currency' => 'CNY',
            'billing_cycles' => ['monthly'],
            'metadata' => ['prices' => ['monthly' => ['price' => '10.00']]],
        ]);
        $secondCatalog = SupplierCatalogProduct::createForAccount($account, [
            'upstream_product_id' => 'route-boundary-second',
            'billing_cycles' => ['monthly'],
        ]);
        $firstMapping = SupplierProductMapping::createFor(
            $account,
            $firstCatalog,
            $firstLocal['product'],
            [
                'local_billing_cycle' => 'monthly',
                'upstream_billing_cycle' => 'monthly',
            ],
        );
        $secondMapping = SupplierProductMapping::createFor(
            $account,
            $secondCatalog,
            $secondLocal['product'],
            [
                'local_billing_cycle' => 'monthly',
                'upstream_billing_cycle' => 'monthly',
            ],
        );
        $route = DB::transaction(fn (): SupplierOrderItemRoute => app(
            SupplierProvisioningOutbox::class,
        )->freezeRoute(
            $firstLocal['orderItem'],
            $firstMapping->load(['account', 'catalogProduct']),
            'CNY',
        ));

        // Nullable composite FKs cannot enforce partial tuples; createFor is the persistence boundary.
        // Direct query-builder writes remain a documented bypass risk and must not persist operations.
        $this->assertDomainException(
            fn () => SupplierOperation::createFor(
                account: $account,
                attributes: $this->operationAttributes('same-account-route-mismatch'),
                productMapping: $secondMapping,
                orderItem: $firstLocal['orderItem'],
                orderItemRoute: $route,
            ),
            'The supplier route does not match the operation references.',
        );
        $this->assertDatabaseCount('supplier_operations', 0);
    }

    private function account(string $name): SupplierAccount
    {
        return SupplierAccount::create([
            'code' => strtolower($name).'-'.bin2hex(random_bytes(4)),
            'name' => $name,
            'driver' => SupplierAccount::DRIVER_IDCSMART_FINANCE,
            'base_url' => 'https://supplier.test',
            'credentials' => ['username' => 'user', 'password' => 'secret'],
        ]);
    }

    private function localRecords(string $suffix): array
    {
        $group = ProductGroup::create(['name' => 'Group '.$suffix]);
        $product = Product::create([
            'product_group_id' => $group->id,
            'name' => 'Product '.$suffix,
            'billing_cycle' => 'monthly',
        ]);
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'Pending',
        ]);
        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => '10.00',
            'setup_fee' => '0.00',
            'amount' => '10.00',
            'configuration' => [],
        ]);
        $invoice = Invoice::create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'number' => 'INV-'.strtoupper($suffix).'-'.bin2hex(random_bytes(4)),
        ]);
        $service = Service::create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'product_id' => $product->id,
            'unit_index' => 1,
            'name' => 'Service '.$suffix,
            'billing_cycle' => 'monthly',
        ]);

        return compact('user', 'product', 'order', 'orderItem', 'invoice', 'service');
    }

    private function invoice(User $user, string $suffix): Invoice
    {
        return Invoice::create([
            'user_id' => $user->id,
            'number' => 'INV-'.strtoupper($suffix).'-'.bin2hex(random_bytes(4)),
        ]);
    }

    private function operationAttributes(string $idempotencyKey): array
    {
        return [
            'action' => SupplierOperation::ACTION_SYNC,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    private function assertDomainException(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail('Expected a domain exception.');
        } catch (DomainException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    private function assertQueryException(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a database query exception.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
