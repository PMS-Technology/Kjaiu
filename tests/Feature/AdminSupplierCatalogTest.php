<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\SupplierAccount;
use App\Models\SupplierCatalogProduct;
use App\Models\SupplierOperation;
use App\Models\SupplierOrderItemRoute;
use App\Models\SupplierProductMapping;
use App\Models\User;
use App\Services\SupplierProvisioningOutbox;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminSupplierCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_successful_sync_normalizes_and_upserts_without_mutating_local_pricing(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier();
        $localProduct = $this->localProduct('sync', [
            'price' => '77.77',
            'setup_fee' => '8.88',
        ]);
        $localProduct->prices()->create([
            'billing_cycle' => 'annually',
            'price' => '700.00',
            'setup_fee' => '5.00',
            'is_active' => true,
        ]);
        $existing = SupplierCatalogProduct::createForAccount($supplier, [
            'upstream_product_id' => '101',
            'name' => 'Old compute name',
            'minimum_price' => '1.00',
            'billing_cycles' => ['monthly'],
            'metadata' => ['old' => true],
        ]);
        $missing = SupplierCatalogProduct::createForAccount($supplier, [
            'upstream_product_id' => '999',
            'name' => 'No longer listed',
            'billing_cycles' => ['monthly'],
        ]);
        $mapping = SupplierProductMapping::createFor($supplier, $existing, $localProduct, [
            'local_billing_cycle' => 'monthly',
            'upstream_billing_cycle' => 'monthly',
        ]);

        Http::fake(function (ClientRequest $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match ($path) {
                '/zjmf_api_login' => $this->financeResponse([
                    'status' => 200,
                    'jwt' => 'private-login-jwt',
                ]),
                '/api/product/list' => $this->financeResponse([
                    'status' => 200,
                    'data' => [
                        'list' => [[
                            'id' => 'group-not-a-product',
                            'products' => [
                                'compute' => ['id' => 101],
                                'storage' => ['id' => 202],
                            ],
                        ]],
                        'total' => 2,
                    ],
                ]),
                '/api/product/101' => $this->financeResponse([
                    'status' => 200,
                    'data' => [
                        'currency' => ['code' => 'cny'],
                        'product' => [
                            'id' => 101,
                            'gid' => 'compute/group',
                            'type' => 'cloud',
                            'name' => 'Upstream compute',
                            'description' => 'Bearer private-login-jwt',
                            'billingcycle' => 'monthly',
                            'product_price' => '12.345',
                            'setup_fee' => '1.50',
                            'prices' => [[
                                'billingcycle' => 'annually',
                                'price' => '120.00',
                                'setup_fee' => '0.00',
                            ]],
                            'raw_private_payload' => 'raw-response-marker',
                        ],
                    ],
                ]),
                '/api/product/202' => $this->financeResponse([
                    'status' => 200,
                    'data' => [
                        'currency_code' => 'usd',
                        'product' => [
                            'id' => '202',
                            'name' => 'Backup storage',
                            'product_type' => 'storage',
                            'billing_cycles' => [[
                                'cycle' => 'quarterly',
                                'price' => '30.00',
                                'setup' => '2.00',
                            ]],
                        ],
                    ],
                ]),
                default => $this->financeResponse(['status' => 404, 'msg' => 'unexpected']),
            };
        });

        $response = $this->actingAs($administrator)
            ->post('/admin/suppliers/'.$supplier->id.'/catalog-sync', [
                'current_password' => 'admin-password',
            ]);

        $response->assertRedirect()->assertSessionHas('success');
        $synced = $existing->fresh();
        $this->assertSame('Upstream compute', $synced->name);
        $this->assertSame('compute/group', $synced->upstream_group_id);
        $this->assertSame('cloud', $synced->type);
        $this->assertSame('CNY', $synced->currency);
        $this->assertSame('12.35', $synced->minimum_price);
        $this->assertSame(['monthly', 'annually'], $synced->billing_cycles);
        $this->assertSame('monthly', $synced->metadata['primary_billing_cycle']);
        $this->assertSame('12.35', $synced->metadata['prices']['monthly']['price']);
        $this->assertSame('120.00', $synced->metadata['prices']['annually']['price']);
        $this->assertStringNotContainsString('private-login-jwt', $synced->description);
        $this->assertNotNull($synced->last_seen_at);
        $this->assertNotNull($synced->synced_at);

        $created = $supplier->catalogProducts()->where('upstream_product_id', '202')->sole();
        $this->assertSame(['quarterly'], $created->billing_cycles);
        $this->assertSame('quarterly', $created->metadata['default_billing_cycle']);
        $this->assertSame('30.00', $created->minimum_price);
        $this->assertSame('USD', $created->currency);
        $this->assertFalse($missing->fresh()->is_active);
        $this->assertSame($mapping->id, $mapping->fresh()->id);
        $this->assertTrue($mapping->fresh()->is_active);

        $this->assertSame('77.77', $localProduct->fresh()->price);
        $this->assertSame('8.88', $localProduct->fresh()->setup_fee);
        $this->assertSame('700.00', $localProduct->fresh()->prices()->sole()->price);
        $this->assertNotNull($supplier->fresh()->last_catalog_synced_at);
        $this->assertNotNull($supplier->fresh()->last_connected_at);
        $this->assertNull($supplier->fresh()->last_error);

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'supplier.catalog_sync_requested')->count());
        $successAudit = AuditLog::query()
            ->where('action', 'supplier.catalog_sync_succeeded')->sole();
        $this->assertSame(2, $successAudit->after['product_count']);
        $serializedCatalog = DB::table('supplier_catalog_products')->get()->toJson();
        $serializedAudits = AuditLog::query()->get()->toJson();
        foreach (['private-login-jwt', 'raw-response-marker', 'supplier-password'] as $secret) {
            $this->assertStringNotContainsString($secret, $serializedCatalog);
            $this->assertStringNotContainsString($secret, $serializedAudits);
            $this->get('/admin/suppliers')->assertDontSee($secret);
        }
        Http::assertSentCount(4);
    }

    public function test_catalog_page_lists_only_the_selected_suppliers_products(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier();
        $otherSupplier = $this->supplier();
        SupplierCatalogProduct::createForAccount($supplier, [
            'upstream_product_id' => 'visible-101',
            'name' => 'Visible upstream product',
            'currency' => 'CNY',
            'billing_cycles' => ['monthly'],
            'metadata' => ['prices' => ['monthly' => ['price' => '10.00', 'setup_fee' => '0.00']]],
        ]);
        SupplierCatalogProduct::createForAccount($otherSupplier, [
            'upstream_product_id' => 'hidden-202',
            'name' => 'Other supplier product',
            'currency' => 'CNY',
            'billing_cycles' => ['monthly'],
            'metadata' => ['prices' => ['monthly' => ['price' => '20.00', 'setup_fee' => '0.00']]],
        ]);

        $this->actingAs($administrator)
            ->get('/admin/suppliers/'.$supplier->id.'/catalog')
            ->assertOk()
            ->assertSee('导入上游商品')
            ->assertSee('Visible upstream product')
            ->assertDontSee('Other supplier product');
    }

    public function test_administrator_can_select_and_import_an_upstream_product_with_all_prices_and_mappings(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier();
        $parent = ProductGroup::create(['name' => 'Infrastructure']);
        $group = ProductGroup::create([
            'parent_id' => $parent->id,
            'name' => 'Cloud servers',
            'is_active' => true,
        ]);
        $catalog = SupplierCatalogProduct::createForAccount($supplier, [
            'upstream_product_id' => 'compute-101',
            'type' => 'cloud',
            'name' => 'Upstream compute',
            'description' => 'Compute imported from supplier',
            'currency' => 'CNY',
            'minimum_price' => '12.35',
            'billing_cycles' => ['monthly', 'annually'],
            'metadata' => [
                'primary_billing_cycle' => 'monthly',
                'prices' => [
                    'monthly' => ['price' => '12.35', 'setup_fee' => '1.50'],
                    'annually' => ['price' => '120.00', 'setup_fee' => '0.00'],
                ],
            ],
        ]);

        $this->actingAs($administrator)->post(
            '/admin/suppliers/'.$supplier->id.'/catalog-import',
            [
                'product_group_id' => $group->id,
                'catalog_products' => [$catalog->id],
                'current_password' => 'admin-password',
            ],
        )->assertRedirect('/admin/suppliers/'.$supplier->id.'/catalog')
            ->assertSessionHas('success');

        $product = Product::query()->where('name', 'Upstream compute')->sole();
        $this->assertSame($group->id, $product->product_group_id);
        $this->assertSame('monthly', $product->billing_cycle);
        $this->assertSame('12.35', $product->price);
        $this->assertSame('1.50', $product->setup_fee);
        $this->assertFalse($product->is_active);
        $this->assertFalse($product->auto_setup);
        $this->assertSame('120.00', $product->prices()->where('billing_cycle', 'annually')->sole()->price);
        $this->assertSame(
            ['annually', 'monthly'],
            $product->supplierMappings()->orderBy('local_billing_cycle')->pluck('local_billing_cycle')->all(),
        );
        $this->assertDatabaseHas('supplier_catalog_imports', [
            'supplier_account_id' => $supplier->id,
            'supplier_catalog_product_id' => $catalog->id,
            'product_id' => $product->id,
            'imported_by' => $administrator->id,
        ]);
        $audit = AuditLog::query()->where('action', 'supplier.catalog_products_imported')->sole();
        $this->assertSame(1, $audit->after['imported_count']);
        $this->assertStringNotContainsString('admin-password', $audit->toJson());
    }

    public function test_catalog_import_rejects_duplicates_cross_account_products_and_currency_mismatches_atomically(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier();
        $otherSupplier = $this->supplier();
        $parent = ProductGroup::create(['name' => 'Infrastructure']);
        $group = ProductGroup::create(['parent_id' => $parent->id, 'name' => 'Cloud servers']);
        $valid = SupplierCatalogProduct::createForAccount($supplier, [
            'upstream_product_id' => 'valid-101',
            'name' => 'Valid product',
            'currency' => 'CNY',
            'billing_cycles' => ['monthly'],
            'metadata' => ['prices' => ['monthly' => ['price' => '10.00', 'setup_fee' => '0.00']]],
        ]);
        $foreign = SupplierCatalogProduct::createForAccount($otherSupplier, [
            'upstream_product_id' => 'foreign-202',
            'name' => 'Foreign product',
            'currency' => 'CNY',
            'billing_cycles' => ['monthly'],
            'metadata' => ['prices' => ['monthly' => ['price' => '20.00', 'setup_fee' => '0.00']]],
        ]);
        $usd = SupplierCatalogProduct::createForAccount($supplier, [
            'upstream_product_id' => 'usd-303',
            'name' => 'USD product',
            'currency' => 'USD',
            'billing_cycles' => ['monthly'],
            'metadata' => ['prices' => ['monthly' => ['price' => '30.00', 'setup_fee' => '0.00']]],
        ]);

        $payload = fn (array $products): array => [
            'product_group_id' => $group->id,
            'catalog_products' => $products,
            'current_password' => 'admin-password',
        ];
        $this->actingAs($administrator)->post(
            '/admin/suppliers/'.$supplier->id.'/catalog-import',
            $payload([$valid->id, $foreign->id]),
        )->assertSessionHasErrors('catalog_products');
        $this->assertDatabaseCount('products', 0);

        $this->post(
            '/admin/suppliers/'.$supplier->id.'/catalog-import',
            $payload([$valid->id, $usd->id]),
        )->assertSessionHasErrors('catalog_products');
        $this->assertDatabaseCount('products', 0);

        $this->post(
            '/admin/suppliers/'.$supplier->id.'/catalog-import',
            $payload([$valid->id]),
        )->assertSessionHas('success');
        $this->post(
            '/admin/suppliers/'.$supplier->id.'/catalog-import',
            $payload([$valid->id]),
        )->assertSessionHasErrors('catalog_products');
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('supplier_catalog_imports', 1);
    }

    public function test_detail_failure_preserves_the_complete_previous_catalog_and_mappings(): void
    {
        $administrator = $this->administrator();
        $syncedAt = now()->subDay()->startOfSecond();
        $supplier = $this->supplier([
            'last_catalog_synced_at' => $syncedAt,
            'last_connected_at' => $syncedAt,
        ]);
        $localProduct = $this->localProduct('rollback');
        $existing = SupplierCatalogProduct::createForAccount($supplier, [
            'upstream_product_id' => '101',
            'name' => 'Stable compute',
            'minimum_price' => '10.00',
            'billing_cycles' => ['monthly'],
            'is_active' => true,
            'metadata' => ['stable' => true],
        ]);
        $unseen = SupplierCatalogProduct::createForAccount($supplier, [
            'upstream_product_id' => '999',
            'name' => 'Stable unseen product',
            'billing_cycles' => ['monthly'],
            'is_active' => true,
        ]);
        $mapping = SupplierProductMapping::createFor($supplier, $existing, $localProduct, [
            'local_billing_cycle' => 'monthly',
            'upstream_billing_cycle' => 'monthly',
            'options' => ['preserve' => true],
        ]);

        Http::fake(function (ClientRequest $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match ($path) {
                '/zjmf_api_login' => $this->financeResponse([
                    'status' => 200,
                    'jwt' => 'failure-private-jwt',
                ]),
                '/api/product/list' => $this->financeResponse([
                    'status' => 200,
                    'data' => ['list' => [['id' => 101], ['id' => 202]], 'total' => 2],
                ]),
                '/api/product/101' => $this->financeResponse([
                    'status' => 200,
                    'data' => ['product' => [
                        'id' => 101,
                        'name' => 'Must not be committed',
                        'billingcycle' => 'annually',
                        'product_price' => '999.00',
                    ]],
                ]),
                '/api/product/202' => $this->financeResponse([
                    'status' => 400,
                    'msg' => 'username=supplier-user password=supplier-password Bearer failure-private-jwt raw-upstream-error',
                    'data' => ['raw' => 'raw-upstream-payload'],
                ]),
                default => $this->financeResponse(['status' => 404, 'msg' => 'unexpected']),
            };
        });

        $response = $this->actingAs($administrator)
            ->post('/admin/suppliers/'.$supplier->id.'/catalog-sync', [
                'current_password' => 'admin-password',
            ]);

        $response->assertRedirect()->assertSessionHasErrors('supplier');
        $this->assertSame('Stable compute', $existing->fresh()->name);
        $this->assertSame('10.00', $existing->fresh()->minimum_price);
        $this->assertSame(['monthly'], $existing->fresh()->billing_cycles);
        $this->assertSame(['stable' => true], $existing->fresh()->metadata);
        $this->assertTrue($unseen->fresh()->is_active);
        $this->assertDatabaseMissing('supplier_catalog_products', [
            'supplier_account_id' => $supplier->id,
            'upstream_product_id' => '202',
        ]);
        $this->assertSame(['preserve' => true], $mapping->fresh()->options);
        $this->assertTrue($mapping->fresh()->is_active);

        $failed = $supplier->fresh();
        $this->assertSame($syncedAt->toDateTimeString(), $failed->last_catalog_synced_at->toDateTimeString());
        $this->assertSame($syncedAt->toDateTimeString(), $failed->last_connected_at->toDateTimeString());
        $this->assertNotNull($failed->last_error);
        foreach (['supplier-user', 'supplier-password', 'failure-private-jwt'] as $secret) {
            $this->assertStringNotContainsString($secret, $failed->last_error);
        }

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'supplier.catalog_sync_succeeded',
        ]);
        $failedAudit = AuditLog::query()->where('action', 'supplier.catalog_sync_failed')->sole();
        $serializedAudit = json_encode($failedAudit->toArray(), JSON_THROW_ON_ERROR);
        $sessionErrors = json_encode($response->getSession()->get('errors')->all(), JSON_THROW_ON_ERROR);
        $rendered = $this->get('/admin/suppliers')->getContent();
        foreach ([
            'supplier-user',
            'supplier-password',
            'failure-private-jwt',
            'raw-upstream-error',
            'raw-upstream-payload',
        ] as $value) {
            $this->assertStringNotContainsString($value, $serializedAudit);
            $this->assertStringNotContainsString($value, $sessionErrors);
            $this->assertStringNotContainsString($value, $rendered);
        }
        Http::assertSentCount(4);
    }

    public function test_sync_discards_a_result_if_account_configuration_changes_in_flight(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier(['last_error' => 'Keep this newer state']);
        $stable = SupplierCatalogProduct::createForAccount($supplier, [
            'upstream_product_id' => 'stable-race-product',
            'name' => 'Stable race product',
            'billing_cycles' => ['monthly'],
        ]);

        Http::fake(function (ClientRequest $request) use ($supplier) {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => $this->financeResponse([
                    'status' => 200,
                    'jwt' => 'catalog-race-jwt',
                ]),
                '/api/product/list' => $this->financeResponse([
                    'status' => 200,
                    'data' => ['list' => [['id' => 'catalog-race-product']], 'total' => 1],
                ]),
                '/api/product/catalog-race-product' => tap(
                    $this->financeResponse([
                        'status' => 400,
                        'msg' => 'detail failed after the account changed',
                    ]),
                    fn () => DB::table('supplier_accounts')->where('id', $supplier->id)->update([
                        'code' => 'catalog-race-new-code',
                        'last_error' => 'Newer catalog configuration state',
                        'updated_at' => now(),
                    ]),
                ),
                default => $this->financeResponse(['status' => 404, 'msg' => 'unexpected']),
            };
        });

        $response = $this->actingAs($administrator)
            ->post('/admin/suppliers/'.$supplier->id.'/catalog-sync', [
                'current_password' => 'admin-password',
            ]);

        $response->assertRedirect('/admin/suppliers/'.$supplier->id.'/catalog')
            ->assertSessionHasErrors('supplier');
        $current = $supplier->fresh();
        $this->assertSame('catalog-race-new-code', $current->code);
        $this->assertSame('Newer catalog configuration state', $current->last_error);
        $this->assertNull($current->last_catalog_synced_at);
        $this->assertTrue($stable->fresh()->is_active);
        $this->assertSame('Stable race product', $stable->fresh()->name);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'supplier.catalog_sync_failed']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'supplier.catalog_sync_succeeded']);
        $audit = AuditLog::query()->where('action', 'supplier.catalog_sync_discarded')->sole();
        $this->assertSame(['configuration_conflict' => true], $audit->after);
        Http::assertSentCount(3);
    }

    public function test_data_info_pagination_and_array_valued_cycles_prove_a_complete_catalog(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier();

        Http::fake(function (ClientRequest $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $page = (int) ($request->data()['page'] ?? 1);

            return match (true) {
                $path === '/zjmf_api_login' => $this->financeResponse([
                    'status' => 200,
                    'jwt' => 'pagination-private-jwt',
                ]),
                $path === '/api/product/list' && $page === 1 => $this->financeResponse([
                    'status' => 200,
                    'data' => [
                        'list' => [['id' => 'Case-ID']],
                        'info' => [
                            'current_page' => 1,
                            'last_page' => 2,
                            'has_next' => true,
                            'total' => 2,
                        ],
                    ],
                ]),
                $path === '/api/product/list' && $page === 2 => $this->financeResponse([
                    'status' => 200,
                    'data' => [
                        'list' => [['id' => 'case-id']],
                        'info' => [
                            'current_page' => 2,
                            'last_page' => 2,
                            'has_next' => false,
                            'total' => 2,
                            'next_page' => null,
                        ],
                    ],
                ]),
                $path === '/api/product/Case-ID' => $this->financeResponse([
                    'status' => 200,
                    'data' => ['product' => [
                        'id' => 'Case-ID',
                        'name' => 'Upper case route',
                        'billingcycle' => ['monthly', ['cycle' => 'annually']],
                        'product_price' => '10.00',
                    ]],
                ]),
                $path === '/api/product/case-id' => $this->financeResponse([
                    'status' => 200,
                    'data' => ['product' => [
                        'id' => 'case-id',
                        'name' => 'Lower case route',
                        'billing_cycles' => ['quarterly'],
                    ]],
                ]),
                default => $this->financeResponse(['status' => 404, 'msg' => 'unexpected']),
            };
        });

        $this->actingAs($administrator)
            ->post('/admin/suppliers/'.$supplier->id.'/catalog-sync', [
                'current_password' => 'admin-password',
            ])->assertRedirect()->assertSessionHas('success');

        $upper = $supplier->catalogProducts()->where('upstream_product_id', 'Case-ID')->sole();
        $lower = $supplier->catalogProducts()->where('upstream_product_id', 'case-id')->sole();
        $this->assertNotSame($upper->id, $lower->id);
        $this->assertSame(['monthly', 'annually'], $upper->billing_cycles);
        $this->assertSame(['quarterly'], $lower->billing_cycles);
        Http::assertSentCount(5);
    }

    public function test_incomplete_flag_can_only_confirm_an_explicit_next_page(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier();

        Http::fake(function (ClientRequest $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $page = (int) ($request->data()['page'] ?? 1);

            return match (true) {
                $path === '/zjmf_api_login' => $this->financeResponse([
                    'status' => 200,
                    'jwt' => 'incomplete-page-jwt',
                ]),
                $path === '/api/product/list' && $page === 1 => $this->financeResponse([
                    'status' => 200,
                    'data' => [
                        'list' => [['id' => 'incomplete-page-product']],
                        'has_next' => true,
                        'complete' => false,
                        'count' => 1,
                    ],
                ]),
                $path === '/api/product/list' && $page === 2 => $this->financeResponse([
                    'status' => 200,
                    'data' => [
                        'list' => [],
                        'has_next' => false,
                        'next_page' => null,
                        'count' => 0,
                    ],
                ]),
                $path === '/api/product/incomplete-page-product' => $this->financeResponse([
                    'status' => 200,
                    'data' => ['product' => [
                        'id' => 'incomplete-page-product',
                        'billingcycle' => 'monthly',
                    ]],
                ]),
                default => $this->financeResponse(['status' => 404, 'msg' => 'unexpected']),
            };
        });

        $this->actingAs($administrator)
            ->post('/admin/suppliers/'.$supplier->id.'/catalog-sync', [
                'current_password' => 'admin-password',
            ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('supplier_catalog_products', [
            'supplier_account_id' => $supplier->id,
            'upstream_product_id' => 'incomplete-page-product',
        ]);
        Http::assertSentCount(4);
    }

    public function test_unknown_or_unproven_completeness_metadata_fails_before_catalog_writes(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier();
        $stable = SupplierCatalogProduct::createForAccount($supplier, [
            'upstream_product_id' => 'stable-product',
            'name' => 'Stable product',
            'billing_cycles' => ['monthly'],
        ]);

        Http::fake(function (ClientRequest $request) {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => $this->financeResponse([
                    'status' => 200,
                    'jwt' => 'unknown-metadata-jwt',
                ]),
                '/api/product/list' => $this->financeResponse([
                    'status' => 200,
                    'data' => [
                        'list' => [],
                        'info' => ['mystery_cursor' => 'opaque'],
                    ],
                ]),
                default => $this->financeResponse(['status' => 404, 'msg' => 'unexpected']),
            };
        });

        $this->actingAs($administrator)
            ->post('/admin/suppliers/'.$supplier->id.'/catalog-sync', [
                'current_password' => 'admin-password',
            ])->assertSessionHasErrors('supplier');

        $this->assertTrue($stable->fresh()->is_active);
        $this->assertSame('Stable product', $stable->fresh()->name);
        $this->assertNull($supplier->fresh()->last_catalog_synced_at);
        Http::assertSentCount(2);
    }

    public function test_explicit_unpaginated_completeness_requires_both_flags(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier();
        $responses = collect([
            ['data' => [
                'list' => [],
                'complete' => false,
                'paginated' => false,
            ]],
            ['data' => [
                'list' => [],
                'complete' => true,
                'paginated' => false,
            ]],
        ]);

        Http::fake(function (ClientRequest $request) use ($responses) {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => $this->financeResponse([
                    'status' => 200,
                    'jwt' => 'unpaginated-proof-jwt',
                ]),
                '/api/product/list' => $this->financeResponse([
                    'status' => 200,
                ] + $responses->shift()),
                default => $this->financeResponse(['status' => 404, 'msg' => 'unexpected']),
            };
        });

        $this->actingAs($administrator)
            ->post('/admin/suppliers/'.$supplier->id.'/catalog-sync', [
                'current_password' => 'admin-password',
            ])->assertSessionHasErrors('supplier');
        $this->assertNull($supplier->fresh()->last_catalog_synced_at);

        $this->post('/admin/suppliers/'.$supplier->id.'/catalog-sync', [
            'current_password' => 'admin-password',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertNotNull($supplier->fresh()->last_catalog_synced_at);
    }

    public function test_unproven_empty_and_contradictory_pagination_preserve_the_previous_catalog(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier();
        $stable = SupplierCatalogProduct::createForAccount($supplier, [
            'upstream_product_id' => 'stable-empty',
            'name' => 'Stable empty guard',
            'billing_cycles' => ['monthly'],
        ]);
        $responses = collect([
            ['data' => ['list' => []]],
            ['data' => [
                'list' => [['id' => 'unexpected-product']],
                'pagination' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'has_next' => true,
                    'total' => 1,
                ],
            ]],
        ]);

        Http::fake(function (ClientRequest $request) use ($responses) {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => $this->financeResponse([
                    'status' => 200,
                    'jwt' => 'empty-proof-jwt',
                ]),
                '/api/product/list' => $this->financeResponse([
                    'status' => 200,
                ] + $responses->shift()),
                default => $this->financeResponse(['status' => 404, 'msg' => 'unexpected']),
            };
        });

        foreach ([1, 2] as $attempt) {
            $this->actingAs($administrator)
                ->post('/admin/suppliers/'.$supplier->id.'/catalog-sync', [
                    'current_password' => 'admin-password',
                ])->assertSessionHasErrors('supplier');
            $this->assertTrue($stable->fresh()->is_active, 'Attempt '.$attempt.' changed the catalog.');
            $this->assertSame('Stable empty guard', $stable->fresh()->name);
        }
        $this->assertDatabaseMissing('supplier_catalog_products', [
            'upstream_product_id' => 'unexpected-product',
        ]);
    }

    public function test_complete_sync_retires_stale_cycle_mapping_without_mutating_frozen_route(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier();
        $product = $this->localProduct('stale-cycle');
        $catalog = SupplierCatalogProduct::createForAccount($supplier, [
            'upstream_product_id' => 'stale-cycle-product',
            'name' => 'Before cycle sync',
            'currency' => 'CNY',
            'billing_cycles' => ['annually'],
            'metadata' => ['prices' => ['annually' => ['price' => '10.00']]],
        ]);
        $mapping = SupplierProductMapping::createFor($supplier, $catalog, $product, [
            'local_billing_cycle' => 'monthly',
            'upstream_billing_cycle' => 'annually',
        ]);
        $route = $this->freezeRoute($mapping, 'stale-cycle');

        Http::fake(function (ClientRequest $request) {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => $this->financeResponse([
                    'status' => 200,
                    'jwt' => 'stale-cycle-jwt',
                ]),
                '/api/product/list' => $this->financeResponse([
                    'status' => 200,
                    'data' => ['list' => [['id' => 'stale-cycle-product']], 'total' => 1],
                ]),
                '/api/product/stale-cycle-product' => $this->financeResponse([
                    'status' => 200,
                    'data' => ['product' => [
                        'id' => 'stale-cycle-product',
                        'name' => 'After cycle sync',
                        'billingcycle' => 'monthly',
                        'product_price' => '11.00',
                    ]],
                ]),
                default => $this->financeResponse(['status' => 404, 'msg' => 'unexpected']),
            };
        });

        $this->actingAs($administrator)
            ->post('/admin/suppliers/'.$supplier->id.'/catalog-sync', [
                'current_password' => 'admin-password',
            ])->assertRedirect()->assertSessionHas('success');

        $this->assertFalse($mapping->fresh()->is_active);
        $this->assertSame(['monthly'], $catalog->fresh()->billing_cycles);
        $this->assertSame($mapping->id, $route->fresh()->supplier_product_mapping_id);
        $this->assertSame(
            'annually',
            $route->fresh()->validatedSnapshot()['upstream']['billing_cycle'],
        );
        $audit = AuditLog::query()->where('action', 'supplier.catalog_sync_succeeded')->sole();
        $this->assertSame(1, $audit->after['deactivated_mapping_count']);
    }

    public function test_stale_cycle_sync_rolls_back_atomically_for_a_nonterminal_mapping(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier();
        $product = $this->localProduct('stale-cycle-guard');
        $catalog = SupplierCatalogProduct::createForAccount($supplier, [
            'upstream_product_id' => 'guarded-cycle-product',
            'name' => 'Guarded before sync',
            'billing_cycles' => ['annually'],
        ]);
        $mapping = SupplierProductMapping::createFor($supplier, $catalog, $product, [
            'local_billing_cycle' => 'monthly',
            'upstream_billing_cycle' => 'annually',
        ]);
        SupplierOperation::createFor(
            account: $supplier,
            attributes: [
                'action' => SupplierOperation::ACTION_PROVISION,
                'status' => SupplierOperation::STATUS_RUNNING,
                'idempotency_key' => 'stale-cycle-guard',
            ],
            productMapping: $mapping,
        );

        Http::fake(function (ClientRequest $request) {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => $this->financeResponse([
                    'status' => 200,
                    'jwt' => 'guarded-cycle-jwt',
                ]),
                '/api/product/list' => $this->financeResponse([
                    'status' => 200,
                    'data' => ['list' => [['id' => 'guarded-cycle-product']], 'total' => 1],
                ]),
                '/api/product/guarded-cycle-product' => $this->financeResponse([
                    'status' => 200,
                    'data' => ['product' => [
                        'id' => 'guarded-cycle-product',
                        'name' => 'Must roll back',
                        'billingcycle' => 'monthly',
                    ]],
                ]),
                default => $this->financeResponse(['status' => 404, 'msg' => 'unexpected']),
            };
        });

        $this->actingAs($administrator)
            ->post('/admin/suppliers/'.$supplier->id.'/catalog-sync', [
                'current_password' => 'admin-password',
            ])->assertSessionHasErrors('supplier');

        $this->assertTrue($mapping->fresh()->is_active);
        $this->assertSame('Guarded before sync', $catalog->fresh()->name);
        $this->assertSame(['annually'], $catalog->fresh()->billing_cycles);
        $this->assertNull($supplier->fresh()->last_catalog_synced_at);
    }

    public function test_mapping_validation_enforces_account_product_and_cycle_boundaries_atomically(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier();
        $otherSupplier = $this->supplier([
            'code' => 'other-supplier',
            'name' => 'Other supplier',
        ]);
        $product = $this->localProduct('cycles');
        $product->prices()->create([
            'billing_cycle' => 'annually',
            'price' => '100.00',
            'setup_fee' => '0.00',
            'is_active' => true,
        ]);
        $product->prices()->create([
            'billing_cycle' => 'quarterly',
            'price' => '30.00',
            'setup_fee' => '0.00',
            'is_active' => false,
        ]);
        $inactiveProduct = $this->localProduct('inactive', ['is_active' => false]);
        $catalog = SupplierCatalogProduct::createForAccount($supplier, [
            'upstream_product_id' => 'catalog-a',
            'name' => 'Catalog A',
            'billing_cycles' => ['monthly', 'annually'],
            'is_active' => true,
        ]);
        $inactiveCatalog = SupplierCatalogProduct::createForAccount($supplier, [
            'upstream_product_id' => 'catalog-inactive',
            'billing_cycles' => ['monthly'],
            'is_active' => false,
        ]);
        $otherCatalog = SupplierCatalogProduct::createForAccount($otherSupplier, [
            'upstream_product_id' => 'catalog-b',
            'billing_cycles' => ['monthly'],
            'is_active' => true,
        ]);

        $this->putMappings($administrator, $supplier, [
            $this->mappingPayload($product, 'monthly', $otherCatalog, 'monthly'),
        ])->assertSessionHasErrors('mappings');
        $this->assertDatabaseCount('supplier_product_mappings', 0);

        $this->putMappings($administrator, $supplier, [
            $this->mappingPayload($product, 'monthly', $catalog, 'quarterly'),
        ])->assertSessionHasErrors('mappings');
        $this->assertDatabaseCount('supplier_product_mappings', 0);

        $this->putMappings($administrator, $supplier, [
            $this->mappingPayload($product, 'quarterly', $catalog, 'monthly'),
        ])->assertSessionHasErrors('mappings');
        $this->assertDatabaseCount('supplier_product_mappings', 0);

        $this->putMappings($administrator, $supplier, [
            $this->mappingPayload($inactiveProduct, 'monthly', $catalog, 'monthly'),
        ])->assertSessionHasErrors('mappings');
        $this->assertDatabaseCount('supplier_product_mappings', 0);

        $this->putMappings($administrator, $supplier, [
            $this->mappingPayload($product, 'monthly', $inactiveCatalog, 'monthly'),
        ])->assertSessionHasErrors('mappings');
        $this->assertDatabaseCount('supplier_product_mappings', 0);

        $this->putMappings($administrator, $supplier, [
            $this->mappingPayload($product, 'monthly', $catalog, 'monthly'),
            $this->mappingPayload($product, 'annually', $catalog, 'quarterly'),
        ])->assertSessionHasErrors('mappings');
        $this->assertDatabaseCount('supplier_product_mappings', 0);

        $this->putMappings($administrator, $supplier, [
            $this->mappingPayload($product, 'monthly', $catalog, 'monthly'),
            $this->mappingPayload($product, 'annually', $catalog, 'annually'),
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(
            ['annually', 'monthly'],
            $supplier->productMappings()->orderBy('local_billing_cycle')
                ->pluck('local_billing_cycle')->all(),
        );
        $this->assertDatabaseCount('supplier_product_mappings', 2);
    }

    public function test_mapping_pages_are_capped_signed_and_preserve_every_unposted_row(): void
    {
        $administrator = $this->administrator();
        $otherAdministrator = $this->administrator();
        $supplier = $this->supplier();
        $otherSupplier = $this->supplier([
            'code' => 'mapping-page-other',
            'name' => 'Other mapping page supplier',
        ]);
        $catalog = SupplierCatalogProduct::createForAccount($supplier, [
            'upstream_product_id' => 'mapping-page-catalog',
            'name' => 'Mapping page catalog',
            'billing_cycles' => ['monthly'],
        ]);
        $otherCatalog = SupplierCatalogProduct::createForAccount($otherSupplier, [
            'upstream_product_id' => 'mapping-page-other-catalog',
            'billing_cycles' => ['monthly'],
        ]);
        $group = ProductGroup::create(['name' => 'Mapping page group']);
        $products = collect();
        foreach (range(1, 51) as $number) {
            $products->push(Product::create([
                'product_group_id' => $group->id,
                'name' => sprintf('Mapping page %03d', $number),
                'type' => 'cloud',
                'billing_cycle' => 'monthly',
                'price' => '10.00',
                'setup_fee' => '0.00',
                'is_active' => true,
            ]));
        }
        $firstMapping = SupplierProductMapping::createFor(
            $supplier,
            $catalog,
            $products->first(),
            [
                'local_billing_cycle' => 'monthly',
                'upstream_billing_cycle' => 'monthly',
            ],
        );
        $lastMapping = SupplierProductMapping::createFor(
            $supplier,
            $catalog,
            $products->last(),
            [
                'local_billing_cycle' => 'monthly',
                'upstream_billing_cycle' => 'monthly',
            ],
        );

        $index = $this->actingAs($administrator)->get('/admin/suppliers?'.http_build_query([
            'mapping_account' => $supplier->id,
            'mapping_page' => 1,
        ]));
        $index->assertOk();
        $page = $index->viewData('mappingPages')->get($supplier->id);
        $token = $index->viewData('mappingPageTokens')->get($supplier->id);
        $this->assertCount(50, $page);
        $this->assertSame(51, $page->total());
        $this->assertTrue($page->hasMorePages());
        $index->assertSee('mapping_page=2', false)
            ->assertSee('mapping_account='.$supplier->id, false);

        $this->put('/admin/suppliers/'.$supplier->id.'/mappings', [
            'current_password' => 'admin-password',
            'mapping_page' => 1,
            'mapping_page_token' => $token,
            'mappings' => [[
                'product_id' => $products->first()->id,
                'local_billing_cycle' => 'monthly',
                'target' => '',
            ]],
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertNull($firstMapping->fresh());
        $this->assertTrue($lastMapping->fresh()->is_active);

        foreach ([
            [$otherSupplier, $administrator, $otherCatalog],
            [$supplier, $otherAdministrator, $catalog],
        ] as [$submittedSupplier, $submittedAdministrator, $submittedCatalog]) {
            $this->actingAs($submittedAdministrator)
                ->put('/admin/suppliers/'.$submittedSupplier->id.'/mappings', [
                    'current_password' => 'admin-password',
                    'mapping_page' => 1,
                    'mapping_page_token' => $token,
                    'mappings' => [[
                        'product_id' => $products->first()->id,
                        'local_billing_cycle' => 'monthly',
                        'target' => $submittedCatalog->id.'|monthly',
                    ]],
                ])->assertSessionHasErrors('mappings');
        }
        $this->assertDatabaseMissing('supplier_product_mappings', [
            'product_id' => $products->first()->id,
            'is_active' => true,
        ]);

        $secretResponse = $this->actingAs($administrator)
            ->put('/admin/suppliers/'.$supplier->id.'/mappings', [
                'current_password' => 'admin-password',
                'mapping_page' => 1,
                'mapping_page_token' => $token,
                'mappings' => [[
                    'product_id' => $products->first()->id,
                    'local_billing_cycle' => 'monthly',
                    'target' => '1|supplier-password',
                ]],
            ]);
        $secretResponse->assertSessionHasErrors('mappings')
            ->assertSessionMissing('_old_input.mappings')
            ->assertSessionMissing('_old_input.current_password')
            ->assertSessionMissing('_old_input.mapping_page_token');
    }

    public function test_two_administrators_cannot_overwrite_a_newer_mapping_page(): void
    {
        $firstAdministrator = $this->administrator();
        $secondAdministrator = $this->administrator();
        $supplier = $this->supplier();
        $product = $this->localProduct('stale-admins');
        $untouchedProduct = $this->localProduct('stale-admins-untouched');
        $firstCatalog = SupplierCatalogProduct::createForAccount($supplier, [
            'upstream_product_id' => 'stale-first',
            'billing_cycles' => ['monthly'],
        ]);
        $secondCatalog = SupplierCatalogProduct::createForAccount($supplier, [
            'upstream_product_id' => 'stale-second',
            'billing_cycles' => ['monthly'],
        ]);
        $mapping = SupplierProductMapping::createFor($supplier, $firstCatalog, $product, [
            'local_billing_cycle' => 'monthly',
            'upstream_billing_cycle' => 'monthly',
            'options' => ['region' => 'initial'],
        ]);

        $firstPage = $this->actingAs($firstAdministrator)->get('/admin/suppliers');
        $secondPage = $this->actingAs($secondAdministrator)->get('/admin/suppliers');
        $firstToken = $firstPage->viewData('mappingPageTokens')->get($supplier->id);
        $secondToken = $secondPage->viewData('mappingPageTokens')->get($supplier->id);

        $this->actingAs($firstAdministrator)
            ->put('/admin/suppliers/'.$supplier->id.'/mappings', [
                'current_password' => 'admin-password',
                'mapping_page' => 1,
                'mapping_page_token' => $firstToken,
                'mappings' => [
                    $this->mappingPayload($product, 'monthly', $secondCatalog, 'monthly'),
                ],
            ])->assertRedirect()->assertSessionHas('success');

        $stale = $this->actingAs($secondAdministrator)
            ->put('/admin/suppliers/'.$supplier->id.'/mappings', [
                'current_password' => 'admin-password',
                'mapping_page' => 1,
                'mapping_page_token' => $secondToken,
                'mappings' => [[
                    'product_id' => $product->id,
                    'local_billing_cycle' => 'monthly',
                    'target' => '',
                ], $this->mappingPayload(
                    $untouchedProduct,
                    'monthly',
                    $secondCatalog,
                    'monthly',
                )],
            ]);

        $stale->assertRedirect()->assertSessionHasErrors('mappings');
        $current = $mapping->fresh();
        $this->assertSame($secondCatalog->id, $current->supplier_catalog_product_id);
        $this->assertSame('monthly', $current->upstream_billing_cycle);
        $this->assertTrue($current->is_active);
        $this->assertDatabaseMissing('supplier_product_mappings', [
            'product_id' => $untouchedProduct->id,
            'is_active' => true,
        ]);
        $this->assertSame(1, AuditLog::query()->where('action', 'supplier.mappings_updated')->count());

        $this->actingAs($secondAdministrator)
            ->putJson('/admin/suppliers/'.$supplier->id.'/mappings', [
                'current_password' => 'admin-password',
                'mapping_page' => 1,
                'mapping_page_token' => $secondToken,
                'mappings' => [
                    $this->mappingPayload($product, 'monthly', $firstCatalog, 'monthly'),
                ],
            ])->assertStatus(409)->assertJsonValidationErrors('mappings');
    }

    public function test_mapping_page_revision_binds_target_timestamp_and_options_hash(): void
    {
        $firstAdministrator = $this->administrator();
        $secondAdministrator = $this->administrator();
        $supplier = $this->supplier();
        $product = $this->localProduct('stale-options');
        $catalog = SupplierCatalogProduct::createForAccount($supplier, [
            'upstream_product_id' => 'stale-options-catalog',
            'billing_cycles' => ['monthly'],
        ]);
        $mapping = SupplierProductMapping::createFor($supplier, $catalog, $product, [
            'local_billing_cycle' => 'monthly',
            'upstream_billing_cycle' => 'monthly',
            'options' => ['region' => 'initial'],
        ]);

        $firstPage = $this->actingAs($firstAdministrator)->get('/admin/suppliers');
        $secondPage = $this->actingAs($secondAdministrator)->get('/admin/suppliers');
        $firstToken = $firstPage->viewData('mappingPageTokens')->get($supplier->id);
        $secondToken = $secondPage->viewData('mappingPageTokens')->get($supplier->id);
        $tokenPayload = json_decode(
            Crypt::decryptString($firstToken),
            true,
            16,
            JSON_THROW_ON_ERROR,
        );
        $revision = $tokenPayload['rows'][$product->id.'|monthly'];
        $this->assertSame(2, $tokenPayload['version']);
        $this->assertSame($mapping->id, $revision['mapping_id']);
        $this->assertSame($supplier->id, $revision['supplier_account_id']);
        $this->assertSame($catalog->id, $revision['target_catalog_product_id']);
        $this->assertSame('monthly', $revision['target_billing_cycle']);
        $this->assertNotEmpty($revision['revision']);
        $this->assertSame(
            hash('sha256', json_encode(['region' => 'initial'], JSON_THROW_ON_ERROR)),
            $revision['options_hash'],
        );

        $mapping->options = ['region' => 'changed'];
        $mapping->save();
        $stale = $this->actingAs($secondAdministrator)
            ->put('/admin/suppliers/'.$supplier->id.'/mappings', [
                'current_password' => 'admin-password',
                'mapping_page' => 1,
                'mapping_page_token' => $secondToken,
                'mappings' => [
                    $this->mappingPayload($product, 'monthly', $catalog, 'monthly'),
                ],
            ]);

        $stale->assertRedirect()->assertSessionHasErrors('mappings');
        $this->assertSame(['region' => 'changed'], $mapping->fresh()->options);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_mapping_targets_are_emitted_once_and_dirty_navigation_is_guarded(): void
    {
        $supplier = $this->supplier();
        $product = $this->localProduct('shared-targets');
        $this->localProduct('shared-targets-second');
        $catalog = SupplierCatalogProduct::createForAccount($supplier, [
            'upstream_product_id' => 'shared-target-catalog',
            'name' => 'Shared target',
            'billing_cycles' => ['monthly', 'annually'],
        ]);
        SupplierProductMapping::createFor($supplier, $catalog, $product, [
            'local_billing_cycle' => 'monthly',
            'upstream_billing_cycle' => 'monthly',
        ]);

        $response = $this->actingAs($this->administrator())->get('/admin/suppliers');
        $response->assertOk()
            ->assertSee('data-dirty-guard', false)
            ->assertSee('data-dirty-message=', false)
            ->assertSee('datalist id="supplier-targets-'.$supplier->id.'"', false)
            ->assertSee('list="supplier-targets-'.$supplier->id.'"', false)
            ->assertDontSee('<select name="mappings[', false);
        $this->assertSame(
            1,
            substr_count(
                $response->getContent(),
                '<option value="'.$catalog->id.'|monthly">',
            ),
        );

        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertIsString($script);
        $this->assertStringContainsString('form[data-dirty-guard]', $script);
        $this->assertStringContainsString('requestConfirmation', $script);
        $this->assertStringContainsString('dialog.addEventListener("cancel"', $script);
        $this->assertStringContainsString('event.defaultPrevented', $script);
        $this->assertStringContainsString('form.dataset.submitting = "true"', $script);
        $this->assertStringNotContainsString('window.confirm', $script);
        $this->assertStringNotContainsString('beforeunload', $script);
    }

    public function test_blank_mapping_only_deactivates_the_submitted_pair_and_omissions_are_preserved(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier();
        $firstProduct = $this->localProduct('first');
        $secondProduct = $this->localProduct('second');
        $catalog = SupplierCatalogProduct::createForAccount($supplier, [
            'upstream_product_id' => 'catalog',
            'name' => 'Catalog',
            'billing_cycles' => ['monthly'],
        ]);
        $first = SupplierProductMapping::createFor($supplier, $catalog, $firstProduct, [
            'local_billing_cycle' => 'monthly',
            'upstream_billing_cycle' => 'monthly',
            'options' => ['keep' => 'history'],
        ]);
        $second = SupplierProductMapping::createFor($supplier, $catalog, $secondProduct, [
            'local_billing_cycle' => 'monthly',
            'upstream_billing_cycle' => 'monthly',
        ]);

        $this->putMappings($administrator, $supplier, [[
            'product_id' => $firstProduct->id,
            'local_billing_cycle' => 'monthly',
            'target' => '',
        ]])->assertRedirect()->assertSessionHas('success');

        $this->assertNull($first->fresh());
        $this->assertTrue($second->fresh()->is_active);
        $this->assertDatabaseCount('supplier_product_mappings', 1);

        $this->putMappings($administrator, $supplier, [
            $this->mappingPayload($firstProduct, 'monthly', $catalog, 'monthly'),
        ])->assertRedirect()->assertSessionHas('success');

        $replacement = $supplier->productMappings()
            ->where('product_id', $firstProduct->id)
            ->where('local_billing_cycle', 'monthly')
            ->sole();
        $this->assertNotSame($first->id, $replacement->id);
        $this->assertTrue($replacement->is_active);
        $this->assertTrue($second->fresh()->is_active);
        $this->assertDatabaseCount('supplier_product_mappings', 2);
        $this->assertSame(2, AuditLog::query()->where('action', 'supplier.mappings_updated')->count());
    }

    public function test_mapping_migration_preserves_frozen_routes_and_corruption_never_routes_silently(): void
    {
        $administrator = $this->administrator();
        $first = $this->supplier(['code' => 'global-first']);
        $second = $this->supplier(['code' => 'global-second', 'name' => 'Second supplier']);
        $product = $this->localProduct('global');
        $firstCatalog = SupplierCatalogProduct::createForAccount($first, [
            'upstream_product_id' => 'global-first-product',
            'currency' => 'CNY',
            'billing_cycles' => ['monthly'],
            'metadata' => ['prices' => ['monthly' => ['price' => '10.00']]],
        ]);
        $secondCatalog = SupplierCatalogProduct::createForAccount($second, [
            'upstream_product_id' => 'global-second-product',
            'billing_cycles' => ['monthly'],
        ]);
        $mapping = SupplierProductMapping::createFor($first, $firstCatalog, $product, [
            'local_billing_cycle' => 'monthly',
            'upstream_billing_cycle' => 'monthly',
        ]);
        $route = $this->freezeRoute($mapping, 'global-migration');

        $this->putMappings($administrator, $second, [
            $this->mappingPayload($product, 'monthly', $secondCatalog, 'monthly'),
        ])->assertRedirect()->assertSessionHas('success');
        $replacement = $second->productMappings()->where('is_active', true)->sole();
        $this->assertFalse($mapping->fresh()->is_active);
        $this->assertSame($first->id, $mapping->fresh()->supplier_account_id);
        $this->assertSame($mapping->id, $route->fresh()->supplier_product_mapping_id);
        $this->assertSame(
            'global-first-product',
            $route->fresh()->validatedSnapshot()['upstream']['product_id'],
        );
        $this->assertSame($second->id, $replacement->supplier_account_id);
        $this->assertDatabaseCount('supplier_product_mappings', 2);

        Schema::table('supplier_product_mappings', function (Blueprint $table): void {
            $table->dropUnique('sup_mapping_active_route_uq');
        });
        $duplicateId = DB::table('supplier_product_mappings')->insertGetId([
            'supplier_account_id' => $second->id,
            'supplier_catalog_product_id' => $secondCatalog->id,
            'product_id' => $product->id,
            'local_billing_cycle' => 'monthly',
            'upstream_billing_cycle' => 'monthly',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(SupplierProvisioningOutbox::class)->activeMapping($product->id, 'monthly');
            $this->fail('Expected duplicate active mappings to fail closed.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'Multiple active supplier mappings exist for a local product and billing cycle.',
                $exception->getMessage(),
            );
        } finally {
            DB::table('supplier_product_mappings')->where('id', $duplicateId)->delete();
            Schema::table('supplier_product_mappings', function (Blueprint $table): void {
                $table->unique(
                    'active_route_key',
                    'sup_mapping_active_route_uq',
                );
            });
        }
    }

    public function test_nonterminal_operations_freeze_mapping_routing_and_options(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier();
        $product = $this->localProduct('frozen');
        $product->prices()->create([
            'billing_cycle' => 'annually',
            'price' => '100.00',
            'setup_fee' => '0.00',
            'is_active' => true,
        ]);
        $firstCatalog = SupplierCatalogProduct::createForAccount($supplier, [
            'upstream_product_id' => 'frozen-first',
            'billing_cycles' => ['monthly', 'annually'],
        ]);
        $secondCatalog = SupplierCatalogProduct::createForAccount($supplier, [
            'upstream_product_id' => 'frozen-second',
            'billing_cycles' => ['monthly', 'annually'],
        ]);
        $mapping = SupplierProductMapping::createFor($supplier, $firstCatalog, $product, [
            'local_billing_cycle' => 'monthly',
            'upstream_billing_cycle' => 'monthly',
            'options' => ['region' => 'original'],
        ]);
        $operation = SupplierOperation::createFor(
            account: $supplier,
            attributes: [
                'action' => SupplierOperation::ACTION_PROVISION,
                'status' => SupplierOperation::STATUS_AMBIGUOUS,
                'idempotency_key' => 'mapping-config-guard',
            ],
            productMapping: $mapping,
        );

        foreach ([
            ['upstream_product_id' => 'frozen-retargeted'],
            ['is_active' => false],
        ] as $attributes) {
            try {
                $firstCatalog->fresh()->fill($attributes)->save();
                $this->fail('Expected a nonterminal catalog routing mutation to be rejected.');
            } catch (DomainException $exception) {
                $this->assertSame(
                    'Supplier catalog routing cannot change while operations are nonterminal.',
                    $exception->getMessage(),
                );
            }
        }

        $sameSecond = now()->startOfSecond();
        DB::table('supplier_accounts')->where('id', $supplier->id)->update([
            'last_catalog_synced_at' => $sameSecond,
        ]);
        $this->travelTo($sameSecond);

        Http::fake(function (ClientRequest $request) {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => $this->financeResponse([
                    'status' => 200,
                    'jwt' => 'private-login-jwt',
                ]),
                '/api/product/list' => $this->financeResponse([
                    'status' => 200,
                    'data' => ['list' => [], 'total' => 0],
                ]),
                default => $this->financeResponse(['status' => 404, 'msg' => 'unexpected']),
            };
        });

        $this->actingAs($administrator)
            ->post('/admin/suppliers/'.$supplier->id.'/catalog-sync', [
                'current_password' => 'admin-password',
            ])
            ->assertSessionHasErrors('supplier');
        $this->assertTrue($firstCatalog->fresh()->is_active);
        $this->assertTrue($secondCatalog->fresh()->is_active);
        $this->assertSame(
            $sameSecond->toDateTimeString(),
            $supplier->fresh()->last_catalog_synced_at->toDateTimeString(),
        );
        Http::assertSentCount(2);

        foreach ([
            $this->mappingPayload($product, 'monthly', $secondCatalog, 'monthly'),
            $this->mappingPayload($product, 'monthly', $firstCatalog, 'annually'),
            [
                'product_id' => $product->id,
                'local_billing_cycle' => 'monthly',
                'target' => '',
            ],
        ] as $payload) {
            $this->putMappings($administrator, $supplier, [$payload])
                ->assertSessionHasErrors('mappings');
            $unchanged = $mapping->fresh();
            $this->assertTrue($unchanged->is_active);
            $this->assertSame($firstCatalog->id, $unchanged->supplier_catalog_product_id);
            $this->assertSame('monthly', $unchanged->local_billing_cycle);
            $this->assertSame('monthly', $unchanged->upstream_billing_cycle);
            $this->assertSame(['region' => 'original'], $unchanged->options);
        }

        foreach ([
            ['options' => ['region' => 'changed']],
            ['local_billing_cycle' => 'annually'],
        ] as $attributes) {
            try {
                $mapping->fresh()->fill($attributes)->save();
                $this->fail('Expected a nonterminal mapping mutation to be rejected.');
            } catch (DomainException $exception) {
                $this->assertSame(
                    'Supplier mappings cannot change while referenced operations are nonterminal.',
                    $exception->getMessage(),
                );
            }
        }
        try {
            $mapping->fresh()->delete();
            $this->fail('Expected a nonterminal mapping deletion to be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'Supplier mappings cannot be deleted while referenced operations are nonterminal.',
                $exception->getMessage(),
            );
        }

        $operation->update([
            'status' => SupplierOperation::STATUS_FAILED,
            'finished_at' => now(),
        ]);
        try {
            $terminalCatalog = $firstCatalog->fresh();
            $terminalCatalog->upstream_product_id = 'frozen-terminal';
            $terminalCatalog->save();
            $this->fail('Expected a historically referenced catalog ID mutation to be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'Upstream product IDs referenced by supplier history are immutable.',
                $exception->getMessage(),
            );
        }

        try {
            $terminal = $mapping->fresh();
            $terminal->supplier_catalog_product_id = $secondCatalog->id;
            $terminal->local_billing_cycle = 'annually';
            $terminal->upstream_billing_cycle = 'annually';
            $terminal->options = ['region' => 'terminal'];
            $terminal->save();
            $this->fail('Expected a historically referenced mapping mutation to be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'Supplier mappings with historical references cannot change routing identity.',
                $exception->getMessage(),
            );
        }
        $terminal = $mapping->fresh();
        $terminal->is_active = false;
        $terminal->save();
        $this->assertFalse($terminal->fresh()->is_active);
        $this->assertSame($terminal->id, $operation->fresh()->supplier_product_mapping_id);
    }

    private function administrator(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'Active',
            'password' => 'admin-password',
        ]);
    }

    private function supplier(array $overrides = []): SupplierAccount
    {
        return SupplierAccount::create(array_merge([
            'code' => 'supplier-'.bin2hex(random_bytes(4)),
            'name' => 'Primary supplier',
            'driver' => SupplierAccount::DRIVER_IDCSMART_FINANCE,
            'base_url' => 'https://8.8.8.8',
            'credentials' => [
                'username' => 'supplier-user',
                'password' => 'supplier-password',
            ],
            'options' => ['verify_tls' => true],
            'is_active' => true,
        ], $overrides));
    }

    private function localProduct(string $suffix, array $overrides = []): Product
    {
        $group = ProductGroup::create(['name' => 'Group '.$suffix]);

        return Product::create(array_merge([
            'product_group_id' => $group->id,
            'name' => 'Product '.$suffix,
            'type' => 'cloud',
            'billing_cycle' => 'monthly',
            'price' => '10.00',
            'setup_fee' => '0.00',
            'is_active' => true,
        ], $overrides));
    }

    private function mappingPayload(
        Product $product,
        string $localCycle,
        SupplierCatalogProduct $catalog,
        string $upstreamCycle,
    ): array {
        return [
            'product_id' => $product->id,
            'local_billing_cycle' => $localCycle,
            'target' => $catalog->id.'|'.$upstreamCycle,
        ];
    }

    private function putMappings(
        User $administrator,
        SupplierAccount $supplier,
        array $mappings,
        int $page = 1,
    ) {
        $index = $this->actingAs($administrator)->get('/admin/suppliers?'.http_build_query([
            'mapping_account' => $supplier->id,
            'mapping_page' => $page,
        ]));
        $index->assertOk();

        return $this->put('/admin/suppliers/'.$supplier->id.'/mappings', [
            'mapping_page' => $page,
            'mapping_page_token' => $index->viewData('mappingPageTokens')->get($supplier->id),
            'mappings' => $mappings,
        ]);
    }

    private function freezeRoute(
        SupplierProductMapping $mapping,
        string $suffix,
    ): SupplierOrderItemRoute {
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'Pending',
        ]);
        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $mapping->product_id,
            'product_name' => 'Frozen '.$suffix,
            'billing_cycle' => $mapping->local_billing_cycle,
            'quantity' => 1,
            'unit_price' => '10.00',
            'setup_fee' => '0.00',
            'amount' => '10.00',
            'configuration' => [],
        ]);

        return DB::transaction(fn (): SupplierOrderItemRoute => app(
            SupplierProvisioningOutbox::class,
        )->freezeRoute(
            $orderItem,
            $mapping->load(['account', 'catalogProduct']),
            'CNY',
        ));
    }

    private function financeResponse(array $payload)
    {
        return Http::response($payload, 200, ['Content-Type' => 'application/json']);
    }
}
