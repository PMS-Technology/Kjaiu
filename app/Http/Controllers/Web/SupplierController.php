<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Integrations\Idcsmart\FinanceClient;
use App\Integrations\Idcsmart\FinanceException;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductPrice;
use App\Models\SupplierAccount;
use App\Models\SupplierCatalogProduct;
use App\Models\SupplierErrorSanitizer;
use App\Models\SupplierProductMapping;
use App\Models\User;
use App\Services\SupplierCatalogImportService;
use App\Services\SupplierCatalogSyncService;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class SupplierController extends Controller
{
    private const MAPPINGS_PER_PAGE = 50;

    private const MAPPING_TOKEN_TTL_SECONDS = 7200;

    private const CONNECTION_TEST_COOLDOWN_SECONDS = 300;

    private const CYCLES = [
        'free' => '免费',
        'hourly' => '小时付',
        'daily' => '天付',
        'monthly' => '月付',
        'quarterly' => '季付',
        'semiannually' => '半年付',
        'annually' => '年付',
        'biennially' => '两年付',
        'triennially' => '三年付',
        'onetime' => '一次性',
    ];

    public function index(Request $request, SupplierCatalogSyncService $catalogSync): View
    {
        $accounts = SupplierAccount::query()
            ->where('driver', SupplierAccount::DRIVER_IDCSMART_FINANCE)
            ->withCount([
                'catalogProducts as catalog_product_count',
                'catalogProducts as active_catalog_product_count' => fn ($query) => $query
                    ->where('is_active', true),
                'productMappings as active_mapping_count' => fn ($query) => $query
                    ->where('is_active', true),
            ])
            ->with([
                'catalogProducts' => fn ($query) => $query
                    ->orderBy('name')
                    ->orderBy('upstream_product_id'),
                'productMappings' => fn ($query) => $query
                    ->with('catalogProduct')
                    ->orderBy('product_id')
                    ->orderBy('local_billing_cycle'),
            ])
            ->orderBy('name')
            ->get();

        $products = Product::query()
            ->where('is_active', true)
            ->with(['prices' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('billing_cycle')])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
        $allLocalProductCycles = $products->flatMap(function (Product $product): array {
            $rows = [[
                'product' => $product,
                'cycle' => $product->billing_cycle,
                'price' => $product->price,
                'setup_fee' => $product->setup_fee,
                'is_default' => true,
            ]];

            foreach ($product->prices as $price) {
                if ($price->billing_cycle === $product->billing_cycle) {
                    continue;
                }
                $rows[] = [
                    'product' => $product,
                    'cycle' => $price->billing_cycle,
                    'price' => $price->price,
                    'setup_fee' => $price->setup_fee,
                    'is_default' => false,
                ];
            }

            return $rows;
        })->values();
        $mappingPage = $this->mappingPageNumber($request, $allLocalProductCycles->count());
        $mappingRows = $allLocalProductCycles
            ->slice(($mappingPage - 1) * self::MAPPINGS_PER_PAGE, self::MAPPINGS_PER_PAGE)
            ->values();
        $mappingPageGroups = $mappingRows->isEmpty()
            ? collect()
            : SupplierProductMapping::query()
                ->whereIn('product_id', $mappingRows->pluck('product.id')->unique())
                ->where('is_active', true)
                ->orderBy('product_id')
                ->orderBy('local_billing_cycle')
                ->orderBy('id')
                ->get()
                ->groupBy(fn (SupplierProductMapping $mapping): string => (
                    $mapping->product_id.'|'.$mapping->local_billing_cycle
                ));
        $mappingPageState = $mappingRows->mapWithKeys(function (array $row) use (
            $mappingPageGroups,
        ): array {
            $pair = $row['product']->id.'|'.$row['cycle'];

            return [$pair => $this->mappingRevisionState(
                $mappingPageGroups->get($pair, collect()),
            )];
        })->all();

        $accountStates = $accounts->mapWithKeys(function (SupplierAccount $account): array {
            $credentials = $this->savedCredentials($account);

            return [$account->id => [
                'base_url' => $this->safeBaseUrl($account->base_url, $credentials),
                'code' => $this->safeDisplayValue($account->code, $credentials, '代码已安全隐藏'),
                'identifier' => $this->maskIdentifier($credentials['username'] ?? null),
                'name' => $this->safeDisplayValue($account->name, $credentials, '名称已安全隐藏'),
                'password_configured' => filled($credentials['password'] ?? null),
                'allow_legacy_unbounded_credit_payment' => $account
                    ->allowsLegacyUnboundedCreditPayment(),
            ]];
        });
        $mappingPages = $accounts->mapWithKeys(function (SupplierAccount $account) use (
            $allLocalProductCycles,
            $mappingPage,
            $mappingRows,
        ): array {
            $paginator = new LengthAwarePaginator(
                $mappingRows,
                $allLocalProductCycles->count(),
                self::MAPPINGS_PER_PAGE,
                $mappingPage,
                [
                    'path' => route('admin.suppliers.index'),
                    'pageName' => 'mapping_page',
                ],
            );
            $paginator->appends(['mapping_account' => $account->id]);

            return [$account->id => $paginator];
        });
        $mappingPageTokens = $accounts->mapWithKeys(fn (SupplierAccount $account): array => [
            $account->id => $this->mappingPageToken(
                $account,
                $request->user(),
                $mappingPageState,
                $mappingPage,
            ),
        ]);
        $catalogOptions = $accounts->mapWithKeys(function (SupplierAccount $account) use ($catalogSync): array {
            $credentials = $this->credentialValues($this->savedCredentials($account));
            $options = $account->catalogProducts->where('is_active', true)->flatMap(function (
                SupplierCatalogProduct $product,
            ) use ($catalogSync, $credentials): array {
                return collect($catalogSync->billingCycles($product))
                    ->reject(fn (string $cycle): bool => $this->containsSensitiveValue(
                        $cycle,
                        $credentials,
                    ))
                    ->map(fn (string $cycle): array => [
                        'value' => $product->id.'|'.$cycle,
                        'catalog_product_id' => $product->id,
                        'name' => $this->safeDisplayValue(
                            $product->name,
                            $credentials,
                            '上游商品名称已隐藏',
                        ),
                        'upstream_product_id' => $this->safeDisplayValue(
                            $product->upstream_product_id,
                            $credentials,
                            'ID 已隐藏',
                        ),
                        'cycle' => $cycle,
                    ])
                    ->all();
            })->values();

            return [$account->id => $options];
        });
        $mappingTargets = $accounts->mapWithKeys(function (
            SupplierAccount $account,
        ): array {
            $targets = $account->productMappings->where('is_active', true)
                ->mapWithKeys(function (SupplierProductMapping $mapping): array {
                    $target = $mapping->supplier_catalog_product_id.'|'.$mapping->upstream_billing_cycle;

                    return [
                        $mapping->product_id.'|'.$mapping->local_billing_cycle => $target,
                    ];
                });

            return [$account->id => $targets];
        });
        $mappingStates = $accounts->mapWithKeys(function (
            SupplierAccount $account,
        ) use ($catalogSync): array {
            $states = $account->productMappings
                ->groupBy(fn (SupplierProductMapping $mapping): string => (
                    $mapping->product_id.'|'.$mapping->local_billing_cycle
                ))
                ->map(function ($mappings) use ($catalogSync): array {
                    $mapping = $mappings->firstWhere('is_active', true)
                        ?? $mappings->sortByDesc('id')->first();
                    $catalog = $mapping->catalogProduct;
                    $validCycle = $catalog !== null
                        && in_array(
                            $mapping->upstream_billing_cycle,
                            $catalogSync->billingCycles($catalog),
                            true,
                        );
                    $status = match (true) {
                        ! $mapping->is_active && $catalog?->is_active === false => '上游商品已停用，映射已停用',
                        ! $mapping->is_active && ! $validCycle => '上游周期已消失，映射已停用',
                        ! $mapping->is_active => '映射已停用',
                        $catalog?->is_active === false => '上游商品已停用',
                        ! $validCycle => '上游周期已失效',
                        default => '当前有效路由',
                    };

                    return [
                        'status' => $status,
                        'target' => $mapping->supplier_catalog_product_id.'|'.$mapping->upstream_billing_cycle,
                        'valid' => $mapping->is_active && $catalog?->is_active && $validCycle,
                        'historical_count' => $mappings->where('is_active', false)->count(),
                    ];
                });

            return [$account->id => $states];
        });
        $globalMappingStates = $accounts
            ->flatMap(fn (SupplierAccount $account) => $account->productMappings)
            ->groupBy(fn (SupplierProductMapping $mapping): string => (
                $mapping->product_id.'|'.$mapping->local_billing_cycle
            ))
            ->map(function ($mappings) use ($accountStates): array {
                $mapping = $mappings->firstWhere('is_active', true)
                    ?? $mappings->sortByDesc('id')->first();

                return [
                    'supplier_account_id' => $mapping->supplier_account_id,
                    'supplier_name' => $accountStates->get($mapping->supplier_account_id)['name'],
                    'is_active' => $mapping->is_active,
                    'historical_count' => $mappings->where('is_active', false)->count(),
                ];
            });
        $mappingAccount = filter_var(
            $request->query('mapping_account'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if ($mappingAccount === false || ! $accounts->contains('id', $mappingAccount)) {
            $mappingAccount = null;
        }

        return view('admin.suppliers.index', [
            'accounts' => $accounts,
            'accountStates' => $accountStates,
            'catalogOptions' => $catalogOptions,
            'globalMappingStates' => $globalMappingStates,
            'mappingAccount' => $mappingAccount,
            'mappingPages' => $mappingPages,
            'mappingPageTokens' => $mappingPageTokens,
            'mappingStates' => $mappingStates,
            'mappingTargets' => $mappingTargets,
            'cycles' => self::CYCLES,
            'autoTestConnections' => ! $request->session()->pull('supplier_auto_tested', false)
                && $accounts->contains(fn (SupplierAccount $account): bool => (
                    $account->is_active
                    && ($account->last_tested_at === null
                        || $account->last_tested_at->lt(now()->subSeconds(self::CONNECTION_TEST_COOLDOWN_SECONDS)))
                )),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $sensitive = $this->takeSensitiveInput($request);
        $data = $this->validateAccount($request, $sensitive);
        $this->validateCredentials($sensitive, true);

        $account = SupplierAccount::create([
            'code' => $data['code'],
            'name' => $data['name'],
            'driver' => SupplierAccount::DRIVER_IDCSMART_FINANCE,
            'base_url' => $data['base_url'],
            'credentials' => [
                'username' => $sensitive['username'],
                'password' => $sensitive['password'],
            ],
            'options' => [
                'verify_tls' => true,
                'allow_legacy_unbounded_credit_payment' => $data[
                    'allow_legacy_unbounded_credit_payment'
                ],
            ],
            'is_active' => $data['is_active'],
        ]);

        $this->recordAudit(
            $request,
            'supplier.created',
            $account,
            null,
            $this->safeAuditState($account, $account->credentials, $sensitive),
            $this->secretValues($sensitive),
        );

        return redirect()->route('admin.suppliers.index')
            ->with('success', '上游供应商账户已创建');
    }

    public function catalog(Request $request, SupplierAccount $supplier): View
    {
        $this->requireSupportedAccount($supplier);
        $keyword = trim((string) $request->query('q'));
        $catalogProducts = $supplier->catalogProducts()
            ->with('catalogImport.product')
            ->when($keyword !== '', fn ($query) => $query->where(function ($search) use ($keyword): void {
                $search->where('name', 'like', "%$keyword%")
                    ->orWhere('upstream_product_id', 'like', "%$keyword%")
                    ->orWhere('upstream_group_id', 'like', "%$keyword%");
            }))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(30)
            ->withQueryString();
        $groups = ProductGroup::query()
            ->whereNotNull('parent_id')
            ->where('is_active', true)
            ->with('parent:id,name')
            ->orderBy('name')
            ->get();

        return view('admin.suppliers.catalog', [
            'supplier' => $supplier,
            'catalogProducts' => $catalogProducts,
            'groups' => $groups,
            'keyword' => $keyword,
            'cycles' => self::CYCLES,
            'localCurrency' => strtoupper((string) config('kjaiu.currency.code', 'CNY')),
            'autoSyncCatalog' => ! $request->hasAny(['q', 'page'])
                && ! $request->session()->pull('supplier_catalog_synced_'.$supplier->getKey(), false),
        ]);
    }

    public function importCatalog(
        Request $request,
        SupplierAccount $supplier,
        SupplierCatalogImportService $catalogImport,
    ): RedirectResponse {
        $this->requireSupportedAccount($supplier);
        $data = $request->validate([
            'product_group_id' => [
                'required',
                'integer',
                Rule::exists('product_groups', 'id')->whereNotNull('parent_id')->where('is_active', true),
            ],
            'catalog_products' => ['required', 'array', 'min:1', 'max:50'],
            'catalog_products.*' => ['required', 'integer', 'distinct'],
        ]);
        $imported = $catalogImport->import(
            $request,
            $supplier,
            ProductGroup::query()->findOrFail($data['product_group_id']),
            $request->user(),
            array_map('intval', $data['catalog_products']),
        );

        return redirect()->route('admin.suppliers.catalog', $supplier)->with(
            'success',
            '已导入 '.count($imported).' 个上游商品；商品默认下架，请检查售价后再上架。',
        );
    }

    public function update(Request $request, SupplierAccount $supplier): RedirectResponse
    {
        $this->requireSupportedAccount($supplier);
        $sensitive = $this->takeSensitiveInput($request);
        $data = $this->validateAccount($request, $sensitive, $supplier);
        $this->validateCredentials($sensitive, false);

        try {
            $result = DB::transaction(function () use ($request, $supplier, $sensitive, $data): array {
                $account = SupplierAccount::query()->lockForUpdate()->findOrFail($supplier->getKey());
                $this->requireSupportedAccount($account);
                $credentials = $this->savedCredentials($account);
                $previousCredentials = $credentials;
                if ($this->payloadContainsSensitiveValue([
                    'name' => $data['name'],
                    'code' => $data['code'],
                    'driver' => $data['driver'],
                    'base_url' => $data['base_url'],
                    'options' => $account->options,
                ], $this->credentialValues([
                    $credentials,
                    $this->secretValues($sensitive),
                ]))) {
                    $request->replace(array_filter([
                        '_token' => $request->input('_token'),
                        '_method' => $request->input('_method'),
                        '_form' => $request->input('_form'),
                    ], fn (mixed $value): bool => is_string($value) && $value !== ''));
                    throw ValidationException::withMessages([
                        'supplier' => '供应商明文配置不能包含上游凭据',
                    ]);
                }
                $usernameChanged = $sensitive['username_provided']
                    && ! hash_equals((string) ($credentials['username'] ?? ''), $sensitive['username']);
                $passwordChanged = $sensitive['password_provided']
                    && ! hash_equals((string) ($credentials['password'] ?? ''), $sensitive['password']);
                $baseUrlChanged = ! hash_equals(
                    rtrim((string) $account->base_url, '/'),
                    $data['base_url'],
                );
                $driverChanged = ! hash_equals((string) $account->driver, $data['driver']);
                $codeChanged = ! hash_equals((string) $account->code, $data['code']);
                $activeStateChanged = $account->is_active !== $data['is_active'];
                $legacyPaymentChanged = $account->allowsLegacyUnboundedCreditPayment()
                    !== $data['allow_legacy_unbounded_credit_payment'];
                $credentialsChanged = $usernameChanged || $passwordChanged;
                $connectionIdentityChanged = $baseUrlChanged
                    || $driverChanged
                    || $codeChanged
                    || $activeStateChanged
                    || $legacyPaymentChanged;
                if ($connectionIdentityChanged
                    && ($account->hasNonterminalOperations()
                        || $account->hasPendingOrderItemRoutes())) {
                    throw ValidationException::withMessages([
                        'supplier' => '仍有未终结的上游操作或待结算路由，当前不能修改连接配置、状态或高风险兼容选项',
                    ]);
                }

                if ($usernameChanged) {
                    $credentials['username'] = $sensitive['username'];
                }
                if ($passwordChanged) {
                    $credentials['password'] = $sensitive['password'];
                }
                if (! filled($credentials['username'] ?? null) || ! filled($credentials['password'] ?? null)) {
                    throw ValidationException::withMessages([
                        'password' => '上游登录标识和密码必须同时配置',
                    ]);
                }

                $before = $this->safeAuditState(
                    $account,
                    $previousCredentials,
                    $credentials,
                    $sensitive,
                );
                $attributes = [
                    'code' => $data['code'],
                    'name' => $data['name'],
                    'driver' => $data['driver'],
                    'base_url' => $baseUrlChanged ? $data['base_url'] : $account->base_url,
                    'is_active' => $data['is_active'],
                ];
                if ($usernameChanged || $passwordChanged) {
                    $attributes['credentials'] = $credentials;
                }
                if ($legacyPaymentChanged) {
                    $options = is_array($account->options) ? $account->options : [];
                    $options['allow_legacy_unbounded_credit_payment'] = $data[
                        'allow_legacy_unbounded_credit_payment'
                    ];
                    $attributes['options'] = $options;
                }
                $previousConnection = clone $account;
                $account->update($attributes);
                if ($credentialsChanged) {
                    FinanceClient::forgetCachedJwt($previousConnection);
                    FinanceClient::forgetCachedJwt($account);
                }

                return compact(
                    'account',
                    'before',
                    'credentials',
                    'previousCredentials',
                    'usernameChanged',
                    'passwordChanged',
                    'credentialsChanged',
                    'legacyPaymentChanged',
                );
            }, 3);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'supplier' => $exception->getMessage(),
            ]);
        }

        $supplier = $result['account'];
        $credentials = $result['credentials'];
        $previousCredentials = $result['previousCredentials'];
        $usernameChanged = $result['usernameChanged'];
        $passwordChanged = $result['passwordChanged'];
        $credentialsChanged = $result['credentialsChanged'];

        $this->recordAudit(
            $request,
            'supplier.updated',
            $supplier,
            $result['before'],
            $this->safeAuditState(
                $supplier,
                $credentials,
                $previousCredentials,
                $sensitive,
            ) + [
                'credentials_changed' => $credentialsChanged,
                'credential_identifier_changed' => $usernameChanged,
                'credential_password_changed' => $passwordChanged,
                'legacy_unbounded_credit_payment_changed' => $result['legacyPaymentChanged'],
            ],
            array_merge(
                array_values($previousCredentials),
                array_values($credentials),
                $this->secretValues($sensitive),
            ),
        );

        return redirect()->route('admin.suppliers.index')
            ->with('success', '上游供应商配置已更新');
    }

    public function testActive(Request $request): RedirectResponse
    {
        $cutoff = now()->subSeconds(self::CONNECTION_TEST_COOLDOWN_SECONDS);
        $accounts = SupplierAccount::query()
            ->where('driver', SupplierAccount::DRIVER_IDCSMART_FINANCE)
            ->where('is_active', true)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_tested_at')->orWhere('last_tested_at', '<', $cutoff);
            })
            ->orderBy('id')
            ->get();
        $failed = 0;
        foreach ($accounts as $account) {
            $lock = Cache::lock('supplier:connection-test:'.$account->getKey(), 90);
            if (! $lock->get()) {
                continue;
            }
            try {
                $freshAccount = SupplierAccount::query()->findOrFail($account->getKey());
                if ($freshAccount->last_tested_at !== null
                    && $freshAccount->last_tested_at->gte($cutoff)) {
                    continue;
                }
                if (! $this->testConnection($request, $freshAccount)) {
                    $failed++;
                }
            } finally {
                $lock->release();
            }
        }

        $redirect = redirect()->route('admin.suppliers.index')
            ->with('supplier_auto_tested', true);
        if ($failed > 0) {
            return $redirect->withErrors([
                'supplier' => "已自动检测上游连接，{$failed} 个账户连接失败",
            ]);
        }

        return $redirect->with('success', $accounts->isEmpty()
            ? '上游连接状态仍在有效期内'
            : '已自动检测上游连接');
    }

    private function testConnection(Request $request, SupplierAccount $supplier): bool
    {
        $testedCredentials = $this->savedCredentials($supplier);
        $testedAt = now();
        $fingerprint = $this->accountFingerprint($supplier);
        $success = true;
        $error = null;

        try {
            (new FinanceClient($supplier))->products();
        } catch (Throwable $exception) {
            $success = false;
            $error = $exception;
        }

        $result = DB::transaction(function () use (
            $supplier,
            $fingerprint,
            $testedAt,
            $success,
            $error,
        ): array {
            $account = SupplierAccount::query()->lockForUpdate()->findOrFail($supplier->getKey());
            $credentials = $this->savedCredentials($account);
            if (! hash_equals($fingerprint, $this->accountFingerprint($account))) {
                return compact('account', 'credentials') + ['conflict' => true];
            }
            $this->requireSupportedAccount($account);

            $attributes = ['last_tested_at' => $testedAt];
            if ($success) {
                $attributes += [
                    'last_connected_at' => $testedAt,
                    'last_error' => null,
                ];
            } else {
                $attributes['last_error'] = $this->safeError($account, $error);
            }
            $account->forceFill($attributes)->save();

            return compact('account', 'credentials') + ['conflict' => false];
        }, 3);

        $supplier = $result['account'];
        $sensitive = array_merge($testedCredentials, $result['credentials']);
        if ($result['conflict']) {
            $this->recordAudit(
                $request,
                'supplier.connection_test_discarded',
                $supplier,
                null,
                ['configuration_conflict' => true],
                $sensitive,
            );

            return false;
        }

        $this->recordAudit(
            $request,
            'supplier.connection_tested',
            $supplier,
            null,
            ['success' => $success],
            $sensitive,
        );

        return $success;
    }

    public function sync(
        Request $request,
        SupplierAccount $supplier,
        SupplierCatalogSyncService $catalogSync,
    ): RedirectResponse {
        $this->requireSupportedAccount($supplier);
        $credentials = $this->savedCredentials($supplier);
        $sensitive = $credentials;
        $fingerprint = $this->accountFingerprint($supplier);
        $lock = Cache::lock('supplier:catalog-sync:'.$supplier->getKey(), 1800);
        if (! $lock->get()) {
            return redirect()->route('admin.suppliers.catalog', $supplier)
                ->with('supplier_catalog_synced_'.$supplier->getKey(), true)
                ->withErrors(['supplier' => '上游目录正在同步，当前显示最近一次成功同步的目录']);
        }
        $this->recordAudit(
            $request,
            'supplier.catalog_sync_requested',
            $supplier,
            null,
            [
                'catalog_product_count' => $supplier->catalogProducts()->count(),
            ],
            $sensitive,
        );

        try {
            $result = $catalogSync->sync($supplier);
        } catch (Throwable $exception) {
            $currentSupplier = SupplierAccount::query()->find($supplier->getKey()) ?? $supplier;
            $currentCredentials = $this->savedCredentials($currentSupplier);
            if (! hash_equals($fingerprint, $this->accountFingerprint($currentSupplier))) {
                $this->recordAudit(
                    $request,
                    'supplier.catalog_sync_discarded',
                    $currentSupplier,
                    null,
                    ['configuration_conflict' => true],
                    array_merge($credentials, $currentCredentials),
                );

                return redirect()->route('admin.suppliers.catalog', $supplier)
                    ->with('supplier_catalog_synced_'.$supplier->getKey(), true)
                    ->withErrors([
                        'supplier' => '连接配置在目录同步期间已变更，正在显示最近一次同步的目录',
                    ]);
            }
            $currentSupplier->forceFill([
                'last_error' => $this->safeError($currentSupplier, $exception, $credentials),
            ])->save();
            $this->recordAudit(
                $request,
                'supplier.catalog_sync_failed',
                $currentSupplier,
                null,
                ['success' => false],
                array_merge($credentials, $currentCredentials),
            );

            return redirect()->route('admin.suppliers.catalog', $supplier)
                ->with('supplier_catalog_synced_'.$supplier->getKey(), true)
                ->withErrors([
                    'supplier' => '目录同步失败，正在显示最近一次成功同步的目录',
                ]);
        } finally {
            $lock->release();
        }

        $supplier->refresh();
        $this->recordAudit(
            $request,
            'supplier.catalog_sync_succeeded',
            $supplier,
            null,
            [
                'success' => true,
                'product_count' => $result['product_count'],
                'active_product_count' => $result['active_count'],
                'deactivated_product_count' => $result['deactivated_product_count'],
                'deactivated_mapping_count' => $result['deactivated_mapping_count'],
                'catalog_complete' => $result['catalog_complete'],
            ],
            $sensitive,
        );

        $message = '上游目录同步完成，共读取 '.$result['product_count'].' 个商品';
        if (! $result['catalog_complete']) {
            $message .= '；上游未提供分页信息，未停用本次未返回的原有商品和映射';
        }

        return redirect()->route('admin.suppliers.catalog', $supplier)
            ->with('supplier_catalog_synced_'.$supplier->getKey(), true)
            ->with('success', $message);
    }

    public function mappings(
        Request $request,
        SupplierAccount $supplier,
        SupplierCatalogSyncService $catalogSync,
    ): RedirectResponse {
        $this->requireSupportedAccount($supplier);
        $mappingInput = $this->takeMappingInput($request);
        $mappingSecrets = $this->credentialValues([
            $this->savedCredentials($supplier),
        ]);
        if ($this->payloadContainsSensitiveValue($request->input('mappings'), $mappingSecrets)) {
            $request->replace(array_filter([
                '_token' => $request->input('_token'),
                '_method' => $request->input('_method'),
                '_form' => $request->input('_form'),
            ], fn (mixed $value): bool => is_string($value) && $value !== ''));
            throw ValidationException::withMessages([
                'mappings' => '映射提交不能包含上游凭据',
            ]);
        }
        $data = $request->validate([
            'mappings' => ['required', 'array', 'min:1', 'max:'.self::MAPPINGS_PER_PAGE],
            'mappings.*' => ['required', 'array:product_id,local_billing_cycle,target'],
            'mappings.*.product_id' => ['required', 'integer', 'min:1'],
            'mappings.*.local_billing_cycle' => [
                'required',
                'string',
                'max:32',
                'regex:/^[a-z0-9_.-]+$/',
            ],
            'mappings.*.target' => ['present', 'nullable', 'string', 'max:180'],
        ]);

        $submitted = [];
        foreach ($data['mappings'] as $index => $mapping) {
            $productId = (int) $mapping['product_id'];
            $cycle = trim($mapping['local_billing_cycle']);
            $pair = $productId.'|'.$cycle;
            if ($cycle === '' || array_key_exists($pair, $submitted)) {
                throw ValidationException::withMessages([
                    "mappings.{$index}.local_billing_cycle" => '本地商品周期重复或无效',
                ]);
            }

            $target = $this->mappingTarget($mapping['target'] ?? null, $index);
            $submitted[$pair] = [
                'product_id' => $productId,
                'local_billing_cycle' => $cycle,
                'catalog_product_id' => $target['catalog_product_id'] ?? null,
                'upstream_billing_cycle' => $target['upstream_billing_cycle'] ?? null,
            ];
        }
        $expectedPageState = $this->validateMappingPageToken(
            $mappingInput['page_token'],
            $supplier,
            $request->user(),
            $mappingInput['page'],
            array_keys($submitted),
        );
        $pageProductIds = collect(array_keys($expectedPageState))
            ->map(fn (string $pair): int => (int) explode('|', $pair, 2)[0])
            ->unique()
            ->values();

        $result = DB::transaction(function () use (
            $supplier,
            $submitted,
            $catalogSync,
            $expectedPageState,
            $pageProductIds,
        ): array {
            $products = Product::query()
                ->whereIn('id', $pageProductIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $alternateCycles = ProductPrice::query()
                ->whereIn('product_id', $pageProductIds)
                ->where('is_active', true)
                ->orderBy('product_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->groupBy('product_id');

            $account = SupplierAccount::query()->lockForUpdate()->findOrFail($supplier->getKey());
            $this->requireSupportedAccount($account);
            $catalogIds = collect($submitted)
                ->pluck('catalog_product_id')
                ->filter()
                ->unique()
                ->values();
            $catalogProducts = $account->catalogProducts()
                ->whereIn('id', $catalogIds)
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $mappingGroups = SupplierProductMapping::query()
                ->whereIn('product_id', $pageProductIds)
                ->orderBy('product_id')
                ->orderBy('local_billing_cycle')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->groupBy(fn (SupplierProductMapping $mapping): string => (
                    $mapping->product_id.'|'.$mapping->local_billing_cycle
                ));
            foreach ($expectedPageState as $pair => $expectedState) {
                if ($this->mappingRevisionState(
                    $mappingGroups->get($pair, collect()),
                ) !== $expectedState) {
                    $this->throwMappingPageConflict();
                }
            }
            if ($mappingGroups->contains(
                fn ($group): bool => $group->where('is_active', true)->count() > 1,
            )) {
                throw ValidationException::withMessages([
                    'mappings' => '检测到重复的有效本地商品周期映射，已拒绝不确定路由',
                ]);
            }

            foreach ($submitted as $pair => $mapping) {
                $product = $products->get($mapping['product_id']);
                if ($product === null || ! $product->is_active) {
                    throw ValidationException::withMessages([
                        'mappings' => '只能配置当前处于销售状态的本地商品',
                    ]);
                }
                $hasLocalCycle = hash_equals(
                    (string) $product->billing_cycle,
                    $mapping['local_billing_cycle'],
                ) || $alternateCycles->get($product->id, collect())->contains(
                    'billing_cycle',
                    $mapping['local_billing_cycle'],
                );
                if (! $hasLocalCycle) {
                    throw ValidationException::withMessages([
                        'mappings' => '提交的本地商品付款周期不存在或未启用',
                    ]);
                }

                if ($mapping['catalog_product_id'] === null) {
                    continue;
                }
                $catalogProduct = $catalogProducts->get($mapping['catalog_product_id']);
                if ($catalogProduct === null) {
                    throw ValidationException::withMessages([
                        'mappings' => '上游商品不属于该账户或当前不可用',
                    ]);
                }
                if (! in_array(
                    $mapping['upstream_billing_cycle'],
                    $catalogSync->billingCycles($catalogProduct),
                    true,
                )) {
                    throw ValidationException::withMessages([
                        'mappings' => '所选上游商品不支持该付款周期',
                    ]);
                }
            }

            $beforeCount = $account->productMappings()->where('is_active', true)->count();
            $updatedCount = 0;
            $removedCount = 0;

            foreach ($submitted as $pair => $mapping) {
                $group = $mappingGroups->get($pair, collect());
                $existing = $group->firstWhere('is_active', true);
                if ($mapping['catalog_product_id'] === null) {
                    if ($existing !== null
                        && (string) $existing->supplier_account_id === (string) $account->id) {
                        $this->retireMapping($existing);
                        $removedCount++;
                    }

                    continue;
                }

                $catalogProduct = $catalogProducts->get($mapping['catalog_product_id']);
                $sameTarget = $existing !== null
                    && (string) $existing->supplier_account_id === (string) $account->id
                    && (string) $existing->supplier_catalog_product_id === (string) $catalogProduct->id
                    && hash_equals(
                        (string) $existing->upstream_billing_cycle,
                        $mapping['upstream_billing_cycle'],
                    );
                if ($sameTarget) {
                    continue;
                }

                if ($existing !== null && ! $this->mappingHasReferencesForUpdate($existing)
                    && (string) $existing->supplier_account_id === (string) $account->id) {
                    $existing->supplier_catalog_product_id = $catalogProduct->id;
                    $existing->upstream_billing_cycle = $mapping['upstream_billing_cycle'];
                    $this->saveMapping($existing);
                    $updatedCount++;

                    continue;
                }

                if ($existing !== null) {
                    $this->retireMapping($existing);
                }

                $replacement = $group
                    ->filter(fn (SupplierProductMapping $candidate): bool => $candidate->exists
                        && ! $candidate->is_active
                        && (string) $candidate->supplier_account_id === (string) $account->id
                        && (string) $candidate->supplier_catalog_product_id === (string) $catalogProduct->id
                        && hash_equals(
                            (string) $candidate->upstream_billing_cycle,
                            $mapping['upstream_billing_cycle'],
                        ))
                    ->sortByDesc('id')
                    ->first();
                if ($replacement === null) {
                    $replacement = $group
                        ->filter(fn (SupplierProductMapping $candidate): bool => $candidate->exists
                            && ! $candidate->is_active
                            && (string) $candidate->supplier_account_id === (string) $account->id
                            && ! $this->mappingHasReferencesForUpdate($candidate))
                        ->sortByDesc('id')
                        ->first();
                }

                if ($replacement === null) {
                    $this->createMapping(
                        $account,
                        $catalogProduct,
                        $products->get($mapping['product_id']),
                        [
                            'local_billing_cycle' => $mapping['local_billing_cycle'],
                            'upstream_billing_cycle' => $mapping['upstream_billing_cycle'],
                            'is_active' => true,
                        ],
                    );
                } else {
                    $replacement->supplier_catalog_product_id = $catalogProduct->id;
                    $replacement->upstream_billing_cycle = $mapping['upstream_billing_cycle'];
                    $replacement->is_active = true;
                    $this->saveMapping($replacement);
                }
                $updatedCount++;
            }

            return [
                'before_count' => $beforeCount,
                'after_count' => $account->productMappings()->where('is_active', true)->count(),
                'submitted_count' => count($submitted),
                'updated_count' => $updatedCount,
                'removed_count' => $removedCount,
            ];
        }, 3);

        $this->recordAudit(
            $request,
            'supplier.mappings_updated',
            $supplier,
            ['active_mapping_count' => $result['before_count']],
            [
                'active_mapping_count' => $result['after_count'],
                'submitted_pair_count' => $result['submitted_count'],
                'updated_pair_count' => $result['updated_count'],
                'removed_pair_count' => $result['removed_count'],
            ],
            $this->savedCredentials($supplier),
        );

        return back()->with('success', '上游商品周期映射已更新');
    }

    private function saveMapping(SupplierProductMapping $mapping): void
    {
        try {
            $mapping->save();
        } catch (DomainException|QueryException $exception) {
            throw ValidationException::withMessages([
                'mappings' => $exception instanceof DomainException
                    ? $exception->getMessage()
                    : '该本地商品付款周期已存在供应商映射',
            ]);
        }
    }

    private function retireMapping(SupplierProductMapping $mapping): void
    {
        if ($this->mappingHasReferencesForUpdate($mapping)) {
            $mapping->is_active = false;
            $this->saveMapping($mapping);

            return;
        }

        try {
            $mapping->delete();
        } catch (DomainException|QueryException $exception) {
            throw ValidationException::withMessages([
                'mappings' => $exception instanceof DomainException
                    ? $exception->getMessage()
                    : '映射已被引用，当前不能解除或迁移',
            ]);
        }
    }

    private function mappingHasReferencesForUpdate(SupplierProductMapping $mapping): bool
    {
        return $mapping->serviceLinks()->lockForUpdate()->exists()
            || $mapping->operations()->lockForUpdate()->exists()
            || $mapping->orderItemRoutes()->lockForUpdate()->exists();
    }

    private function createMapping(
        SupplierAccount $account,
        SupplierCatalogProduct $catalogProduct,
        Product $product,
        array $attributes,
    ): SupplierProductMapping {
        try {
            return SupplierProductMapping::createFor(
                $account,
                $catalogProduct,
                $product,
                $attributes,
            );
        } catch (DomainException|QueryException $exception) {
            throw ValidationException::withMessages([
                'mappings' => $exception instanceof DomainException
                    ? $exception->getMessage()
                    : '该本地商品付款周期已存在供应商映射',
            ]);
        }
    }

    private function mappingTarget(mixed $target, int|string $index): ?array
    {
        if ($target === null || (is_string($target) && trim($target) === '')) {
            return null;
        }
        if (! is_string($target)
            || ! preg_match('/^([1-9][0-9]*)\|([a-z0-9_.-]{1,32})$/D', trim($target), $matches)) {
            throw ValidationException::withMessages([
                "mappings.{$index}.target" => '请选择有效的上游商品和付款周期',
            ]);
        }

        $catalogProductId = filter_var($matches[1], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($catalogProductId === false) {
            throw ValidationException::withMessages([
                "mappings.{$index}.target" => '请选择有效的上游商品和付款周期',
            ]);
        }

        return [
            'catalog_product_id' => $catalogProductId,
            'upstream_billing_cycle' => $matches[2],
        ];
    }

    private function validateAccount(
        Request $request,
        array $sensitive,
        ?SupplierAccount $account = null,
    ): array {
        $legacyCreditPayment = $request->input(
            'allow_legacy_unbounded_credit_payment',
            $account?->allowsLegacyUnboundedCreditPayment() ?? false,
        );
        $name = is_string($request->input('name'))
            ? trim($request->input('name'))
            : $request->input('name');
        $code = is_string($request->input('code'))
            ? trim($request->input('code'))
            : $request->input('code');
        $plaintext = [
            'name' => $name,
            'code' => $code,
            'driver' => $request->input('driver'),
            'base_url' => $sensitive['base_url'],
            'options' => [
                'saved' => $account?->options,
                'verify_tls' => $request->input('verify_tls'),
                'tls_verify' => $request->input('tls_verify'),
                'allow_legacy_unbounded_credit_payment' => $legacyCreditPayment,
            ],
        ];
        $secretValues = $this->credentialValues([
            $this->savedCredentials($account ?? new SupplierAccount),
            $this->secretValues($sensitive),
        ]);
        if ($this->payloadContainsSensitiveValue($plaintext, $secretValues)) {
            $request->replace(array_filter([
                '_token' => $request->input('_token'),
                '_method' => $request->input('_method'),
                '_form' => $request->input('_form'),
            ], fn (mixed $value): bool => is_string($value) && $value !== ''));
            throw ValidationException::withMessages([
                'supplier' => '供应商明文配置不能包含上游凭据',
            ]);
        }
        $normalizedBaseUrl = $this->normalizeBaseUrl($sensitive['base_url']);

        $request->merge([
            'name' => $name,
            'code' => $code,
            'base_url' => $normalizedBaseUrl,
            'allow_legacy_unbounded_credit_payment' => $legacyCreditPayment,
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'code' => [
                'required',
                'string',
                'max:64',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
                Rule::unique('supplier_accounts', 'code')->ignore($account),
            ],
            'driver' => ['required', Rule::in([SupplierAccount::DRIVER_IDCSMART_FINANCE])],
            'base_url' => ['required', 'string', 'max:2048'],
            'is_active' => ['required', 'boolean'],
            'verify_tls' => ['sometimes', 'accepted'],
            'tls_verify' => ['sometimes', 'accepted'],
            'allow_legacy_unbounded_credit_payment' => ['required', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $data['allow_legacy_unbounded_credit_payment'] = $request->boolean(
            'allow_legacy_unbounded_credit_payment',
        );

        return $data;
    }

    private function takeSensitiveInput(Request $request): array
    {
        $username = $request->input('username');
        $password = $request->input('password');
        $baseUrl = $request->input('base_url');

        $safeInput = $request->only([
            '_token',
            '_method',
            '_form',
            'name',
            'code',
            'driver',
            'is_active',
            'verify_tls',
            'tls_verify',
            'allow_legacy_unbounded_credit_payment',
        ]);
        $request->replace($safeInput);

        return [
            'username' => is_string($username) ? trim($username) : '',
            'password' => is_string($password) ? $password : '',
            'base_url' => $baseUrl,
            'username_provided' => is_string($username) && trim($username) !== '',
            'password_provided' => is_string($password) && $password !== '',
            'valid_types' => ($username === null || is_string($username))
                && ($password === null || is_string($password)),
        ];
    }

    private function validateCredentials(array $sensitive, bool $firstSave): void
    {
        $errors = [];
        if (! $sensitive['valid_types']) {
            $errors['password'] = '上游凭据格式无效';
        }
        if ($firstSave && ! $sensitive['username_provided']) {
            $errors['username'] = '首次保存必须填写上游登录标识';
        }
        if ($firstSave && ! $sensitive['password_provided']) {
            $errors['password'] = '首次保存必须填写上游密码';
        }
        if (mb_strlen($sensitive['username']) > 191) {
            $errors['username'] = '上游登录标识不能超过 191 个字符';
        }
        if (strlen($sensitive['password']) > 4096) {
            $errors['password'] = '上游密码长度超出限制';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function takeMappingInput(Request $request): array
    {
        $input = [
            'page_token' => $request->input('mapping_page_token'),
            'page' => $request->input('mapping_page'),
        ];
        $request->replace(array_filter([
            '_token' => $request->input('_token'),
            '_method' => $request->input('_method'),
            '_form' => $request->input('_form'),
            'mappings' => $request->input('mappings'),
        ], fn (mixed $value): bool => $value !== null));

        return $input;
    }

    private function mappingPageNumber(Request $request, int $rowCount): int
    {
        $page = filter_var($request->query('mapping_page', 1), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $lastPage = max(1, (int) ceil($rowCount / self::MAPPINGS_PER_PAGE));

        return $page === false ? 1 : min($page, $lastPage);
    }

    private function mappingPageToken(
        SupplierAccount $account,
        ?User $user,
        array $rows,
        int $page,
    ): string {
        return Crypt::encryptString(json_encode([
            'version' => 2,
            'supplier_account_id' => (int) $account->id,
            'administrator_id' => (int) $user?->id,
            'page' => $page,
            'rows' => $rows,
            'issued_at' => now()->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    private function validateMappingPageToken(
        mixed $token,
        SupplierAccount $account,
        ?User $user,
        mixed $submittedPage,
        array $submittedPairs,
    ): array {
        try {
            if (! is_string($token) || $token === '' || strlen($token) > 100000) {
                throw new DecryptException('Invalid mapping page token.');
            }
            $payload = json_decode(
                Crypt::decryptString($token),
                true,
                16,
                JSON_THROW_ON_ERROR,
            );
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'mappings' => '映射页面上下文无效或已过期，请刷新页面后重试',
            ]);
        }

        if (! is_array($payload)) {
            throw ValidationException::withMessages([
                'mappings' => '映射页面上下文无效或已过期，请刷新页面后重试',
            ]);
        }
        $page = filter_var($submittedPage, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $issuedAt = is_int($payload['issued_at'] ?? null) ? $payload['issued_at'] : null;
        $rows = $payload['rows'] ?? null;
        $valid = ($payload['version'] ?? null) === 2
            && ($payload['supplier_account_id'] ?? null) === (int) $account->id
            && ($payload['administrator_id'] ?? null) === (int) $user?->id
            && $page !== false
            && ($payload['page'] ?? null) === $page
            && $issuedAt !== null
            && $issuedAt <= now()->timestamp + 60
            && $issuedAt >= now()->timestamp - self::MAPPING_TOKEN_TTL_SECONDS
            && is_array($rows)
            && count($rows) <= self::MAPPINGS_PER_PAGE
            && collect($rows)->every(fn (mixed $state, mixed $pair): bool => is_string($pair)
                && preg_match('/^[1-9][0-9]*\|[a-z0-9_.-]{1,32}$/D', $pair) === 1
                && $this->validMappingRevisionState($state));
        if (! $valid
            || collect($submittedPairs)->contains(fn (string $pair): bool => ! array_key_exists($pair, $rows))) {
            throw ValidationException::withMessages([
                'mappings' => '映射页面上下文无效或已过期，请刷新页面后重试',
            ]);
        }

        return $rows;
    }

    private function mappingRevisionState(iterable $mappings): array
    {
        $active = collect($mappings)
            ->where('is_active', true)
            ->sortBy('id')
            ->values();
        $revisions = $active->map(fn (SupplierProductMapping $mapping): array => [
            'mapping_id' => (int) $mapping->id,
            'revision' => $mapping->updated_at?->format('Y-m-d H:i:s.uP'),
            'supplier_account_id' => (int) $mapping->supplier_account_id,
            'target_catalog_product_id' => (int) $mapping->supplier_catalog_product_id,
            'target_billing_cycle' => (string) $mapping->upstream_billing_cycle,
            'options_hash' => $this->mappingOptionsHash($mapping->options),
        ])->all();
        $current = $revisions[0] ?? null;

        return [
            'active_count' => count($revisions),
            'mapping_id' => $current['mapping_id'] ?? null,
            'revision' => $current['revision'] ?? null,
            'supplier_account_id' => $current['supplier_account_id'] ?? null,
            'target_catalog_product_id' => $current['target_catalog_product_id'] ?? null,
            'target_billing_cycle' => $current['target_billing_cycle'] ?? null,
            'options_hash' => $current['options_hash'] ?? null,
            'active_set_hash' => hash('sha256', json_encode(
                $revisions,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )),
        ];
    }

    private function mappingOptionsHash(mixed $options): string
    {
        $options = is_array($options) ? $this->canonicalMappingOptions($options) : [];

        return hash('sha256', json_encode(
            $options,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function canonicalMappingOptions(array $options): array
    {
        foreach ($options as $key => $value) {
            if (is_array($value)) {
                $options[$key] = $this->canonicalMappingOptions($value);
            }
        }
        if (! array_is_list($options)) {
            ksort($options);
        }

        return $options;
    }

    private function validMappingRevisionState(mixed $state): bool
    {
        if (! is_array($state) || array_keys($state) !== [
            'active_count',
            'mapping_id',
            'revision',
            'supplier_account_id',
            'target_catalog_product_id',
            'target_billing_cycle',
            'options_hash',
            'active_set_hash',
        ]) {
            return false;
        }
        if (! is_int($state['active_count'])
            || $state['active_count'] < 0
            || ! is_string($state['active_set_hash'])
            || preg_match('/\A[0-9a-f]{64}\z/', $state['active_set_hash']) !== 1) {
            return false;
        }
        if ($state['active_count'] === 0) {
            return collect($state)->except(['active_count', 'active_set_hash'])
                ->every(fn (mixed $value): bool => $value === null);
        }

        return is_int($state['mapping_id']) && $state['mapping_id'] > 0
            && is_string($state['revision']) && $state['revision'] !== ''
            && strlen($state['revision']) <= 64
            && is_int($state['supplier_account_id']) && $state['supplier_account_id'] > 0
            && is_int($state['target_catalog_product_id']) && $state['target_catalog_product_id'] > 0
            && is_string($state['target_billing_cycle'])
            && preg_match('/\A[a-z0-9_.-]{1,32}\z/', $state['target_billing_cycle']) === 1
            && is_string($state['options_hash'])
            && preg_match('/\A[0-9a-f]{64}\z/', $state['options_hash']) === 1;
    }

    private function throwMappingPageConflict(): never
    {
        throw ValidationException::withMessages([
            'mappings' => '映射状态已被其他管理员或标签页修改，本次提交未保存，请刷新后重试',
        ])->status(409);
    }

    private function normalizeBaseUrl(mixed $url): string
    {
        if (! is_string($url) || trim($url) === '' || strlen($url) > 2048) {
            throw ValidationException::withMessages([
                'base_url' => '请填写有效的上游 HTTPS 地址',
            ]);
        }

        $url = rtrim(trim($url), '/');
        try {
            new FinanceClient(new SupplierAccount([
                'driver' => SupplierAccount::DRIVER_IDCSMART_FINANCE,
                'base_url' => $url,
                'credentials' => ['username' => 'validation', 'password' => 'validation'],
                'is_active' => true,
            ]));
        } catch (FinanceException) {
            throw ValidationException::withMessages([
                'base_url' => '上游地址必须是可公开路由且不含凭据、查询参数或片段的 HTTPS 地址',
            ]);
        }

        return $url;
    }

    private function savedCredentials(SupplierAccount $account): array
    {
        try {
            return is_array($account->credentials) ? $account->credentials : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function safeBaseUrl(mixed $url, array $sensitive = []): string
    {
        $sensitive = $this->credentialValues($sensitive);
        if (! is_string($url) || ($parts = parse_url($url)) === false) {
            return '地址配置无效';
        }
        if (! is_string($parts['scheme'] ?? null) || ! is_string($parts['host'] ?? null)) {
            return '地址配置无效';
        }

        $host = str_contains($parts['host'], ':') ? '['.$parts['host'].']' : $parts['host'];
        $origin = strtolower($parts['scheme']).'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return $origin;
        }

        $safeUrl = $origin.($parts['path'] ?? '');

        return $this->containsSensitiveValue($safeUrl, $sensitive) ? '地址已安全隐藏' : $safeUrl;
    }

    private function maskIdentifier(mixed $identifier): string
    {
        if (! is_string($identifier) || trim($identifier) === '') {
            return '未配置';
        }

        $identifier = trim($identifier);
        $length = mb_strlen($identifier);
        if ($length <= 2) {
            return str_repeat('*', $length);
        }

        return mb_substr($identifier, 0, 1)
            .str_repeat('*', min(8, $length - 2))
            .mb_substr($identifier, -1);
    }

    private function safeDisplayValue(mixed $value, array $sensitive, string $fallback): string
    {
        if (! is_string($value) || $value === '') {
            return $fallback;
        }
        $sensitive = $this->credentialValues($sensitive);
        if ($this->containsSensitiveValue($value, $sensitive)) {
            return $fallback;
        }

        $value = SupplierErrorSanitizer::sanitize(
            $value,
            [['password' => $sensitive]],
        ) ?? '';
        $value = preg_replace(
            '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)?\b/',
            '[REDACTED]',
            $value,
        ) ?? '';

        return $value === '' ? $fallback : $value;
    }

    private function safeError(
        SupplierAccount $account,
        Throwable $exception,
        array ...$sensitivePayloads,
    ): string {
        $sensitiveValues = $this->credentialValues([
            $this->savedCredentials($account),
            $sensitivePayloads,
        ]);
        $error = SupplierErrorSanitizer::sanitize(
            $exception->getMessage(),
            [['password' => $sensitiveValues]],
        );

        return mb_substr($error ?? 'Supplier connection failed.', 0, 2000);
    }

    private function safeAuditState(
        SupplierAccount $account,
        array ...$sensitivePayloads,
    ): array {
        $sensitiveValues = $this->credentialValues([
            $this->savedCredentials($account),
            $sensitivePayloads,
        ]);
        $state = [
            'code' => $account->code,
            'name' => $account->name,
            'driver' => $account->driver,
            'base_url' => $this->safeBaseUrl($account->base_url, $sensitiveValues),
            'is_active' => $account->is_active,
            'allow_legacy_unbounded_credit_payment' => $account
                ->allowsLegacyUnboundedCreditPayment(),
        ];

        return $this->sanitizeAuditPayload($state, $sensitiveValues);
    }

    private function recordAudit(
        Request $request,
        string $action,
        SupplierAccount $account,
        ?array $before,
        ?array $after,
        array $sensitive,
    ): void {
        $sensitive = $this->credentialValues($sensitive);
        $auditRequest = clone $request;
        $userAgent = (string) $auditRequest->userAgent();
        $sanitizedUserAgent = SupplierErrorSanitizer::sanitize(
            $userAgent,
            [['password' => $sensitive]],
        ) ?? '';
        if ($this->containsSensitiveValue($userAgent, $sensitive)
            || ! hash_equals($userAgent, $sanitizedUserAgent)
            || preg_match('/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)?\b/', $userAgent)) {
            $auditRequest->headers->set('User-Agent', '[REDACTED]');
        }

        AuditLog::record(
            $auditRequest,
            $action,
            $account,
            $before === null ? null : $this->sanitizeAuditPayload($before, $sensitive),
            $after === null ? null : $this->sanitizeAuditPayload($after, $sensitive),
        );
    }

    private function containsSensitiveValue(string $value, array $sensitive): bool
    {
        $decoded = rawurldecode($value);

        foreach ($sensitive as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }
            if (str_contains($value, $candidate) || str_contains($decoded, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function payloadContainsSensitiveValue(mixed $payload, array $sensitive): bool
    {
        if (is_array($payload)) {
            foreach ($payload as $value) {
                if ($this->payloadContainsSensitiveValue($value, $sensitive)) {
                    return true;
                }
            }

            return false;
        }

        return (is_string($payload) || is_int($payload) || is_float($payload))
            && $this->containsSensitiveValue((string) $payload, $sensitive);
    }

    private function sanitizeAuditPayload(array $payload, array $sensitive): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sanitizeAuditPayload($value, $sensitive);

                continue;
            }
            if (! is_string($value)) {
                continue;
            }
            if ($this->containsSensitiveValue($value, $sensitive)) {
                $payload[$key] = '[REDACTED]';

                continue;
            }

            $sanitized = SupplierErrorSanitizer::sanitize(
                $value,
                [['password' => $sensitive]],
            ) ?? '';
            $sanitized = preg_replace(
                '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)?\b/',
                '[REDACTED]',
                $sanitized,
            ) ?? '';
            $payload[$key] = $sanitized === '' ? '[REDACTED]' : $sanitized;
        }

        return $payload;
    }

    private function accountFingerprint(SupplierAccount $account): string
    {
        return hash('sha256', json_encode([
            'id' => $account->getKey(),
            'code' => $account->code,
            'driver' => $account->driver,
            'base_url' => $account->base_url,
            'credentials' => $this->savedCredentials($account),
            'options' => $account->options,
            'is_active' => $account->is_active,
            'last_catalog_synced_at' => $account->last_catalog_synced_at?->format('Y-m-d H:i:s.uP'),
        ], JSON_THROW_ON_ERROR));
    }

    private function secretValues(array $sensitive): array
    {
        return array_values(array_filter([
            $sensitive['username'] ?? null,
            $sensitive['password'] ?? null,
        ], fn (mixed $value): bool => is_string($value) && $value !== ''));
    }

    private function credentialValues(array $credentials): array
    {
        $values = [];
        foreach ($credentials as $value) {
            if (is_array($value)) {
                $values = array_merge($values, $this->credentialValues($value));
            } elseif (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    private function requireSupportedAccount(SupplierAccount $account): void
    {
        abort_unless($account->driver === SupplierAccount::DRIVER_IDCSMART_FINANCE, 404);
    }
}
