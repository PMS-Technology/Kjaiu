<?php

namespace App\Services\Admin;

use App\Integrations\Upstream\SupplierClient;
use App\Integrations\Upstream\SupplierClientFactory;
use App\Models\FinanceApi;
use App\Models\Pricing;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Imports upstream catalogue entries as local products and keeps them in sync.
 *
 * Local products that resell from a supplier carry:
 *   api_type             = 'zjmf_api'
 *   zjmf_api_id          = supplier id
 *   upstream_pid         = the upstream's own product id
 *   upstream_price_type  = 'percent'
 *   upstream_price_value = markup + 100     (100 means "no markup")
 *   rate                 = supplier-currency -> local-currency rate
 *
 * The `+100` encoding is the original's: the stored value is a percentage of
 * the upstream price, so a 20% markup is persisted as 120.
 */
class UpstreamSyncService
{
    /** Cycle columns on `shd_pricing`, in schema order. */
    protected const CYCLES = Pricing::CYCLES;

    /**
     * Import a batch of upstream products into a local product group.
     *
     * @param  array<int, int|string>  $upstreamPids
     * @param  float  $markupPercent  the admin form's "利润百分比"; 20 => stored 120
     * @param  float|null  $rate  supplier currency -> local currency, when they differ
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function importProducts(
        FinanceApi $api,
        int $gid,
        array $upstreamPids,
        float $markupPercent,
        ?float $rate = null,
    ): array {
        $client = SupplierClientFactory::forApi($api);

        if ($client === null) {
            return ['status' => false, 'msg' => '该供应商不支持接口对接', 'data' => null];
        }

        $gid = (int) $gid;
        $upstreamPids = array_values(array_unique(array_map('intval', $upstreamPids)));

        if ($gid <= 0) {
            return ['status' => false, 'msg' => '请选择本地商品分组', 'data' => null];
        }

        if ($upstreamPids === []) {
            return ['status' => false, 'msg' => '请选择上游商品', 'data' => null];
        }

        // The two-coordinate lookup never changes: the local group must exist.
        $group = DB::table('product_groups')->where('id', $gid)->first();

        if ($group === null) {
            return ['status' => false, 'msg' => '商品分组不存在', 'data' => null];
        }

        $catalogue = $this->catalogue($client);

        if (! $catalogue['status']) {
            return $catalogue;
        }

        $rate = $this->resolveRate($rate, $catalogue['data']['currency'] ?? []);
        $markupPercent = $markupPercent > 0 ? $markupPercent : 0.0;

        $created = [];
        $skipped = [];
        $failed = [];

        foreach ($upstreamPids as $pid) {
            $remote = $catalogue['data']['products'][$pid] ?? null;

            if ($remote === null) {
                $skipped[] = $pid;

                continue;
            }

            try {
                $product = $this->persistProduct($api, $gid, $pid, $remote, $markupPercent, $rate, $catalogue['data'], $client);

                $created[] = [
                    'upstream_pid' => $pid,
                    'product_id' => (int) $product->id,
                    'name' => (string) $product->name,
                ];
            } catch (\Throwable $e) {
                $failed[] = ['upstream_pid' => $pid, 'msg' => $e->getMessage()];

                Log::warning('upstream.import.failed', [
                    'api_id' => (int) $api->id,
                    'upstream_pid' => $pid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // The supplier's advertised catalogue size drives 可售 in the list view.
        $this->updateProductCount($api, count($catalogue['data']['products']));

        return [
            'status' => true,
            'msg' => sprintf(
                '导入完成：成功 %d，跳过 %d，失败 %d',
                count($created),
                count($skipped),
                count($failed),
            ),
            'data' => [
                'created' => $created,
                'skipped' => $skipped,
                'failed' => $failed,
                'rate' => $rate,
                'count' => count($created),
            ],
        ];
    }

    /**
     * Pull one product's current state from its supplier and reconcile the
     * local row, recording the change in the resource log.
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function refreshProduct(Product $product): array
    {
        $api = FinanceApi::query()->find((int) $product->zjmf_api_id);

        if ($api === null) {
            return ['status' => false, 'msg' => '该商品未关联上游', 'data' => null];
        }

        $client = SupplierClientFactory::forApi($api);

        if ($client === null) {
            return ['status' => false, 'msg' => '该供应商不支持接口对接', 'data' => null];
        }

        $detail = $this->fetchDetail($client, (int) $product->upstream_pid);

        if (! $detail['status']) {
            $this->logResource($product, '同步失败：' . (string) $detail['msg'], 'upstream-sync');

            return $detail;
        }

        $upstream = $detail['data'];
        $before = [
            'upstream_qty' => (int) $product->upstream_qty,
            'upstream_price' => (float) $product->upstream_price,
            'upstream_cycle' => (string) $product->upstream_cycle,
        ];

        $now = time();
        $rate = $this->resolveRate(null, $upstream['currency'] ?? []);

        // Re-derive our own prices from the upstream price whenever the
        // supplier reports one; a missing price leaves local pricing alone.
        $priced = $this->pricingFrom($upstream, (float) $product->upstream_price_value, $rate);

        $product->upstream_version = (int) ($upstream['upstream_version'] ?? $product->upstream_version);
        // The local schema names this column `location_version`; the upstream
        // calls the same idea `local_version`.
        $product->location_version = (int) ($upstream['local_version'] ?? $product->location_version);
        $product->upstream_qty = (int) ($upstream['qty'] ?? $product->upstream_qty);
        $product->upstream_stock_control = (int) ($upstream['stock_control'] ?? $product->upstream_stock_control);
        $product->upstream_auto_setup = (string) ($upstream['auto_setup'] ?? $product->upstream_auto_setup);
        $product->upstream_ontrial_status = (int) ($upstream['ontrial'] ?? $product->upstream_ontrial_status);
        $product->upstream_price = (float) ($upstream['price'] ?? $product->upstream_price);
        $product->upstream_cycle = (string) ($upstream['cycle'] ?? $product->upstream_cycle);
        $product->upstream_product_shopping_url = (string) ($upstream['url'] ?? $product->upstream_product_shopping_url);
        $product->update_time = $now;
        $product->save();

        if (($priced['prices'] ?? []) !== []) {
            $this->writePricing((int) $product->id, $priced);
        }

        $after = [
            'upstream_qty' => (int) $product->upstream_qty,
            'upstream_price' => (float) $product->upstream_price,
            'upstream_cycle' => (string) $product->upstream_cycle,
        ];

        $changes = [];

        foreach ($after as $key => $value) {
            if ($before[$key] !== $value) {
                $changes[] = sprintf('%s: %s -> %s', $key, (string) $before[$key], (string) $value);
            }
        }

        $this->logResource(
            $product,
            $changes === [] ? '同步完成，无变化' : '同步完成：' . implode(', ', $changes),
            'upstream-sync',
        );

        return [
            'status' => true,
            'msg' => '同步成功',
            'data' => [
                'product_id' => (int) $product->id,
                'changes' => $changes,
                'upstream' => $after,
            ],
        ];
    }

    /**
     * Refresh every product resold from one supplier.
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function refreshAll(FinanceApi $api): array
    {
        $client = SupplierClientFactory::forApi($api);

        if ($client === null) {
            return ['status' => false, 'msg' => '该供应商不支持接口对接', 'data' => null];
        }

        $products = Product::query()
            ->where('zjmf_api_id', (int) $api->id)
            ->orderBy('id')
            ->get();

        if ($products->isEmpty()) {
            return [
                'status' => true,
                'msg' => '该供应商下没有已导入的商品',
                'data' => ['total' => 0, 'updated' => 0, 'failed' => 0],
            ];
        }

        // One catalogue call covers every product; the per-product detail call
        // is only needed for the entries we actually resell.
        $catalogue = $this->catalogue($client);
        $remote = $catalogue['status'] ? ($catalogue['data']['products'] ?? []) : [];
        $currency = $catalogue['status'] ? ($catalogue['data']['currency'] ?? []) : [];
        $rate = $this->resolveRate(null, $currency);

        $updated = 0;
        $failed = 0;
        $missing = [];
        $errors = [];

        foreach ($products as $product) {
            $pid = (int) $product->upstream_pid;

            if ($remote !== [] && ! isset($remote[$pid])) {
                // The supplier withdrew this product: mark our copy not for sale.
                $missing[] = ['product_id' => (int) $product->id, 'upstream_pid' => $pid];

                $this->logResource($product, '上游已下架该商品', 'upstream-sync');

                continue;
            }

            $result = $this->refreshProduct($product);

            if ($result['status']) {
                $updated++;
            } else {
                $failed++;
                $errors[] = ['product_id' => (int) $product->id, 'msg' => (string) $result['msg']];
            }
        }

        if ($catalogue['status']) {
            $this->updateProductCount($api, count($remote));
        }

        return [
            'status' => $failed === 0,
            'msg' => sprintf('同步完成：更新 %d，失败 %d，上游已下架 %d', $updated, $failed, count($missing)),
            'data' => [
                'total' => $products->count(),
                'updated' => $updated,
                'failed' => $failed,
                'missing' => $missing,
                'errors' => $errors,
                'rate' => $rate,
            ],
        ];
    }

    /**
     * Record that a service originated upstream, for the downstream push log.
     *
     * `shd_zjmf_pushhost` is the outbound audit trail: every time we hand a
     * service on to a downstream installation we record where it went and
     * whether it landed. `status` is a char column ('1' success / '0' failure).
     */
    public function pushHostDownstream(
        int $hostId,
        string $url,
        mixed $postData,
        bool $success,
        int $num = 1,
    ): void {
        try {
            DB::table('zjmf_pushhost')->insert([
                'host_id' => $hostId,
                'status' => $success ? '1' : '0',
                'url' => $url,
                'post_data' => is_string($postData)
                    ? $postData
                    : json_encode($postData, JSON_UNESCAPED_UNICODE),
                'time' => time(),
                'num' => $num,
            ]);
        } catch (\Throwable $e) {
            Log::warning('upstream.pushhost.log_failed', [
                'host_id' => $hostId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Number of times a host has been pushed, for retry back-off.
     */
    public function pushAttempts(int $hostId): int
    {
        return (int) DB::table('zjmf_pushhost')->where('host_id', $hostId)->max('num');
    }

    // ---------------------------------------------------------------------
    // Catalogue access
    // ---------------------------------------------------------------------

    /**
     * Fetch and flatten the supplier catalogue into `pid => product` plus the
     * upstream currency hint.
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function catalogue(SupplierClient $client): array
    {
        $result = $client->products();

        if (! $result['status']) {
            return $result;
        }

        $products = [];
        $currency = [];

        foreach ($this->productRows($result['data']) as $row) {
            $pid = (int) ($row['id'] ?? 0);

            if ($pid <= 0) {
                continue;
            }

            $products[$pid] = $this->normaliseProduct($row);

            if ($currency === [] && isset($row['currency']) && is_array($row['currency'])) {
                $currency = $row['currency'];
            }
        }

        // Currency is reported once at the envelope level, not per product.
        if ($currency === []) {
            $currency = $this->extractCurrency($result['data']);
        }

        return [
            'status' => true,
            'msg' => (string) $result['msg'],
            'data' => [
                'products' => $products,
                'currency' => $currency,
                'count' => count($products),
            ],
        ];
    }

    /**
     * Product detail, normalised. Accepts either the cart-product shape or the
     * richer productsconfig shape the upstream uses for a single product.
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function fetchDetail(SupplierClient $client, int $upstreamPid): array
    {
        $detail = $client->productDetail($upstreamPid);
        $data = $detail['status'] ? $detail['data'] : null;
        $row = null;

        if (is_array($data)) {
            foreach (['_products', '_product', 'product'] as $key) {
                if (isset($data[$key]) && is_array($data[$key])) {
                    $row = array_is_list($data[$key]) ? ($data[$key][0] ?? null) : $data[$key];
                    break;
                }
            }

            if ($row === null && isset($data['id'])) {
                $row = $data;
            }
        }

        // The detail payload is thin; the catalogue entry carries the name,
        // stock and type we need on the product row.
        $catalogue = $this->catalogue($client);
        $base = $catalogue['status'] ? ($catalogue['data']['products'][$upstreamPid] ?? null) : null;

        if (! is_array($row) && $base === null) {
            return $detail['status']
                ? ['status' => false, 'msg' => '上游未找到该商品', 'data' => null]
                : $detail;
        }

        // Merge catalogue first, then only the fields the detail payload
        // genuinely carries: the detail row is thin (a description and the
        // cycle table) and its normalised defaults would otherwise blank out
        // the stock and cycle we already know.
        $normalised = $base ?? [];

        if (is_array($row)) {
            foreach ($this->normaliseProduct($row) as $key => $value) {
                if ($value === null || $value === '' || $value === 0 || $value === []) {
                    continue;
                }

                $normalised[$key] = $value;
            }
        }

        if (isset($data['_cycle']) && is_array($data['_cycle'])) {
            $normalised['cycles'] = $this->cycleMap($data['_cycle']);
        }

        return [
            'status' => true,
            'msg' => (string) ($detail['status'] ? $detail['msg'] : '获取成功'),
            'data' => $normalised + ['currency' => $this->extractCurrency($data) ?: ($catalogue['data']['currency'] ?? [])],
        ];
    }

    // ---------------------------------------------------------------------
    // Persistence
    // ---------------------------------------------------------------------

    /**
     * Create or update the local product and its pricing row.
     *
     * The catalogue entry only prices the product's default cycle, so when a
     * client is supplied the detail call is used to pick up the full cycle
     * table (monthly, quarterly, annually…) before pricing is derived.
     */
    protected function persistProduct(
        FinanceApi $api,
        int $gid,
        int $upstreamPid,
        array $remote,
        float $markupPercent,
        float $rate,
        array $catalogue,
        ?SupplierClient $client = null,
    ): Product {
        if ($client !== null) {
            $detail = $this->fetchDetail($client, $upstreamPid);

            if ($detail['status'] && is_array($detail['data'])) {
                $remote = array_merge($remote, array_filter(
                    $detail['data'],
                    fn ($value, $key) => $key === 'cycles' || ! in_array($value, [null, '', 0, []], true),
                    ARRAY_FILTER_USE_BOTH,
                ));
            }
        }

        $existing = Product::query()
            ->where('zjmf_api_id', (int) $api->id)
            ->where('upstream_pid', $upstreamPid)
            ->first();

        $now = time();
        $rate = $rate > 0 ? $rate : 1.0;

        $attributes = [
            'type' => (string) ($remote['type'] ?? 'server'),
            'gid' => $gid,
            'name' => (string) ($remote['name'] ?? ('上游商品 #' . $upstreamPid)),
            'description' => (string) ($remote['description'] ?? ''),
            'hidden' => 0,
            'retired' => 0,
            'stock_control' => 0,
            'qty' => 0,
            'pay_method' => 'prepayment',
            'allow_qty' => (int) ($remote['allow_qty'] ?? 1),
            'auto_setup' => (string) ($remote['auto_setup'] ?? ''),
            'server_type' => (string) ($remote['type'] ?? 'server'),
            'api_type' => Product::API_TYPE_ZJMF,
            'zjmf_api_id' => (int) $api->id,
            'upstream_pid' => $upstreamPid,
            'upstream_price_type' => 'percent',
            // The original stores markup + 100 (100 == no markup).
            'upstream_price_value' => round($markupPercent + 100, 2),
            'rate' => $rate,
            'upstream_qty' => (int) ($remote['qty'] ?? 0),
            'upstream_stock_control' => (int) ($remote['stock_control'] ?? 0),
            'upstream_auto_setup' => (string) ($remote['auto_setup'] ?? ''),
            'upstream_ontrial_status' => (int) ($remote['ontrial'] ?? 0),
            'upstream_price' => (float) ($remote['price'] ?? 0),
            'upstream_cycle' => (string) ($remote['cycle'] ?? ''),
            'upstream_product_shopping_url' => (string) ($remote['url'] ?? ''),
            'update_time' => $now,
        ];

        if ($existing === null) {
            $attributes['create_time'] = $now;
            $attributes['order'] = 0;

            $product = Product::create($attributes);
        } else {
            $product = $existing;

            // Never clobber the local pricing multiplier on a re-import.
            unset($attributes['upstream_price_value'], $attributes['rate']);

            $product->fill($attributes);
            $product->save();
        }

        $pricing = $this->pricingFrom(
            $remote + $catalogue,
            round($markupPercent + 100, 2),
            $rate,
        );

        if (($pricing['prices'] ?? []) !== []) {
            $this->writePricing((int) $product->id, $pricing);
        }

        return $product;
    }

    /**
     * Derive local cycle prices and setup fees from an upstream product
     * payload.
     *
     * `priceValue` is the stored percentage (markup + 100); `rate` converts the
     * upstream currency into ours. Cycles the upstream does not offer are
     * written as -1 so the storefront hides them.
     *
     * @return array{prices:array<string,float>,setups:array<string,float>}
     */
    protected function pricingFrom(array $remote, float $priceValue, float $rate): array
    {
        $multiplier = $priceValue > 0 ? $priceValue / 100 : 1.0;
        $rate = $rate > 0 ? $rate : 1.0;

        // A multi-cycle payload (productsconfig `_cycle`) prices each cycle
        // separately; a flat payload only carries one.
        $cyclePrices = $remote['cycles'] ?? [];
        $flatSetup = (float) ($remote['setup_fee'] ?? 0);

        if ($cyclePrices === []) {
            $cycle = (string) ($remote['cycle'] ?? 'monthly');
            $price = $remote['price'] ?? null;

            if ($price === null) {
                return ['prices' => [], 'setups' => []];
            }

            $cyclePrices = [($cycle !== '' ? $cycle : 'monthly') => [
                'price' => (float) $price,
                'setup_fee' => $flatSetup,
            ]];
        }

        $prices = [];
        $setups = [];

        foreach (self::CYCLES as $cycle) {
            if (! isset($cyclePrices[$cycle])) {
                // Not offered upstream: hide it locally, exactly as the
                // original does with a -1 sentinel.
                $prices[$cycle] = -1.0;

                continue;
            }

            $entry = is_array($cyclePrices[$cycle]) ? $cyclePrices[$cycle] : ['price' => $cyclePrices[$cycle]];
            $base = (float) ($entry['price'] ?? 0);

            $prices[$cycle] = round($base * $rate * $multiplier, 2);
            // The setup fee is converted but never marked up: charging a
            // margin on a pass-through setup cost would double-dip.
            $setups[$cycle] = round((float) ($entry['setup_fee'] ?? $flatSetup) * $rate, 2);
        }

        // Trial pricing is separate from the paid cycles.
        if (isset($remote['ontrial_price'])) {
            $prices['ontrial'] = round((float) $remote['ontrial_price'] * $rate * $multiplier, 2);
            $setups['ontrial'] = round((float) ($remote['ontrial_setup_fee'] ?? 0) * $rate, 2);
        } elseif (($remote['ontrial'] ?? 0) && array_key_exists('ontrial', $prices) && $prices['ontrial'] < 0) {
            $prices['ontrial'] = 0.0;
            $setups['ontrial'] = 0.0;
        }

        return ['prices' => $prices, 'setups' => $setups];
    }

    /**
     * Write the cycle columns of the product's pricing row.
     *
     * @param  array{prices:array<string,float>,setups:array<string,float>}  $pricing
     */
    protected function writePricing(int $productId, array $pricing): void
    {
        $prices = $pricing['prices'] ?? [];
        $setups = $pricing['setups'] ?? [];

        $currency = $this->localCurrencyId();

        $row = Pricing::query()
            ->where('type', 'product')
            ->where('relid', $productId)
            ->where('currency', $currency)
            ->first();

        $attributes = ['type' => 'product', 'relid' => $productId, 'currency' => $currency];

        foreach (self::CYCLES as $cycle) {
            $available = ($prices[$cycle] ?? -1) >= 0;

            // Absent upstream cycles are explicitly marked unavailable.
            $attributes[$cycle] = $available ? $prices[$cycle] : -1.0;

            $setupColumn = Pricing::SETUP_COLUMNS[$cycle] ?? null;

            if ($setupColumn !== null) {
                $attributes[$setupColumn] = $available ? round((float) ($setups[$cycle] ?? 0), 2) : 0.0;
            }
        }

        if ($row === null) {
            Pricing::create($attributes);
        } else {
            $row->fill($attributes);
            $row->save();
        }
    }

    // ---------------------------------------------------------------------
    // Normalisation
    // ---------------------------------------------------------------------

    /**
     * Flatten one catalogue row into the fields we persist.
     *
     * @return array<string, mixed>
     */
    protected function normaliseProduct(array $row): array
    {
        $cycle = (string) ($row['billingcycle'] ?? '');
        $pricing = $row['_cycle'] ?? null;

        $normalised = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'type' => (string) ($row['type'] ?? 'server'),
            'description' => (string) ($row['description'] ?? ''),
            'cycle' => $cycle,
            'price' => isset($row['product_price']) ? (float) $row['product_price'] : null,
            'setup_fee' => isset($row['setup_fee']) ? (float) $row['setup_fee'] : 0.0,
            'stock_control' => isset($row['stock_control']) ? (int) $row['stock_control'] : 0,
            'qty' => isset($row['qty']) ? (int) $row['qty'] : 0,
            'ontrial' => isset($row['ontrial']) ? (int) $row['ontrial'] : 0,
            'ontrial_price' => isset($row['ontrial_price']) ? (float) $row['ontrial_price'] : null,
            'allow_qty' => (int) ($row['allow_qty'] ?? 1),
            'auto_setup' => (string) ($row['auto_setup'] ?? ''),
            'url' => (string) ($row['product_shopping_url'] ?? ''),
        ];

        if (is_array($pricing)) {
            $normalised['cycles'] = $this->cycleMap($pricing);
        }

        return $normalised;
    }

    /**
     * Turn the upstream `_cycle` array into `cycle => {price, setup_fee}`.
     *
     * The upstream emits one entry per offered cycle, each carrying the cycle
     * key itself (`Lbillingcycle`), so the key is the contract here.
     *
     * @return array<string, array{price:float,setup_fee:float}>
     */
    protected function cycleMap(array $cycles): array
    {
        $map = [];

        foreach ($cycles as $key => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $cycle = (string) ($entry['billingcycle'] ?? (is_string($key) ? $key : ''));

            if ($cycle === '' || ! in_array($cycle, self::CYCLES, true)) {
                continue;
            }

            $map[$cycle] = [
                'price' => (float) ($entry['product_price'] ?? $entry['price'] ?? 0),
                'setup_fee' => (float) ($entry['setup_fee'] ?? 0),
            ];
        }

        return $map;
    }

    /**
     * Pull the product list out of whichever envelope depth arrived.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function productRows(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        foreach (['_product', 'product', 'list'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_filter($data[$key], 'is_array');
            }
        }

        // Grouped payload: products live under `_group`/`_first_group` trees.
        foreach (['_group', '_first_group'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $this->rowsFromGroups($data[$key]);
            }
        }

        if (array_is_list($data)) {
            return array_filter($data, 'is_array');
        }

        return [];
    }

    /**
     * Walk the nested first-group/group/product tree the catalogue endpoint
     * returns and collect every product row.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rowsFromGroups(array $nodes): array
    {
        $rows = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            foreach (['_product', 'product', '_products'] as $key) {
                if (isset($node[$key]) && is_array($node[$key])) {
                    foreach ($node[$key] as $product) {
                        if (is_array($product)) {
                            $rows[] = $product;
                        }
                    }
                }
            }

            foreach (['_group', '_first_group'] as $key) {
                if (isset($node[$key]) && is_array($node[$key])) {
                    $rows = array_merge($rows, $this->rowsFromGroups($node[$key]));
                }
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractCurrency(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        if (isset($data['_currency']) && is_array($data['_currency'])) {
            $currency = $data['_currency'];

            if (array_is_list($currency)) {
                $currency = $currency[0] ?? [];
            }

            return is_array($currency) ? $currency : [];
        }

        return isset($data['currency']) && is_array($data['currency']) ? $data['currency'] : [];
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Decide the supplier-currency -> local-currency rate.
     *
     * An explicit rate from the import dialog always wins. Otherwise, when the
     * supplier quotes a different currency code than ours, the stored currency
     * rate is used so pricing stays sane without asking the admin.
     */
    protected function resolveRate(?float $rate, array $upstreamCurrency): float
    {
        if ($rate !== null && $rate > 0) {
            return round($rate, 5);
        }

        $upstreamCode = strtoupper((string) ($upstreamCurrency['code'] ?? ''));
        $currency = $this->localCurrency();

        if ($upstreamCode !== '' && $currency !== null && $upstreamCode === strtoupper((string) $currency->code)) {
            return 1.0;
        }

        // Different currency: trust the configured conversion rate.
        $configured = (float) ($currency?->rate ?? 1);

        return $configured > 0 ? round($configured, 5) : 1.0;
    }

    protected function localCurrency(): ?object
    {
        return DB::table('currencies')->orderByDesc('default')->orderBy('id')->first();
    }

    protected function localCurrencyId(): int
    {
        return (int) ($this->localCurrency()?->id ?? 0);
    }

    protected function updateProductCount(FinanceApi $api, int $count): void
    {
        try {
            $api->product_num = $count;
            $api->save();
        } catch (\Throwable) {
            // Non-fatal: the count is display-only.
        }
    }

    /**
     * Append to the resource log the admin's 上下游 pages read.
     */
    protected function logResource(Product $product, string $description, string $source = 'API'): void
    {
        try {
            $now = time();

            DB::table('api_resource_log')->insert([
                'uid' => 0,
                'pid' => (int) $product->id,
                'version' => (string) $product->upstream_version,
                'description' => $description,
                'ip' => '',
                'create_time' => $now,
                'update_time' => $now,
                'port' => '',
                'source' => $source,
            ]);
        } catch (\Throwable) {
            // Logging must never fail a sync.
        }
    }
}
