<?php

namespace App\Integrations\Upstream;

use App\Models\Client;
use App\Models\FinanceApi;
use App\Models\Host;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * The shared resource pool (`shd_zjmf_finance_api`.`is_resource = 1`).
 *
 * This is the buyer side of the downstream feature: a supplier marked as a
 * resource pool sells capacity to *our* clients on demand, and the three
 * `shd_configuration` gates decide who is allowed to draw from it:
 *
 *   allow_resource_api           master switch for the resource API
 *   allow_resource_api_realname  client must be identity-verified
 *   allow_resource_api_phone     client must have a phone number bound
 *
 * The per-client `shd_api_user_product` rows then cap what a given client may
 * buy (and how many trials), per product.
 */
class ResourcePool
{
    /** Configuration keys, in the order the admin form presents them. */
    public const GATE_MASTER = 'allow_resource_api';
    public const GATE_REALNAME = 'allow_resource_api_realname';
    public const GATE_PHONE = 'allow_resource_api_phone';

    protected ?FinanceApi $pool = null;

    public function __construct(FinanceApi|int|null $pool = null)
    {
        if ($pool instanceof FinanceApi) {
            $this->pool = $pool;
        } elseif (is_int($pool) && $pool > 0) {
            $this->pool = FinanceApi::query()->find($pool);
        }
    }

    /**
     * The default pool: the first supplier flagged `is_resource`.
     */
    public static function default(): ?self
    {
        $pool = FinanceApi::query()
            ->where('is_resource', 1)
            ->orderBy('id')
            ->first();

        return $pool === null ? null : new self($pool);
    }

    public function pool(): ?FinanceApi
    {
        return $this->pool;
    }

    /**
     * The API client for the pool, when it is API-reachable.
     */
    public function client(): ?SupplierClient
    {
        return SupplierClientFactory::forApi($this->pool);
    }

    // ---------------------------------------------------------------------
    // Access control
    // ---------------------------------------------------------------------

    /**
     * Whether the resource API is switched on at all.
     */
    public static function apiEnabled(): bool
    {
        return self::flag(self::GATE_MASTER);
    }

    /**
     * Evaluate every gate for one client.
     *
     * @return array{status:bool,msg:string,data:array<string,bool>}
     */
    public static function checkAccess(?Client $client): array
    {
        $gates = [
            'allow_resource_api' => self::apiEnabled(),
            'realname' => true,
            'phone' => true,
        ];

        if (! $gates['allow_resource_api']) {
            return [
                'status' => false,
                'msg' => '资源API未开启',
                'data' => $gates,
            ];
        }

        if ($client === null) {
            return [
                'status' => false,
                'msg' => '请先登录',
                'data' => $gates,
            ];
        }

        if (self::flag(self::GATE_REALNAME)) {
            $gates['realname'] = self::isRealNameVerified($client);

            if (! $gates['realname']) {
                return [
                    'status' => false,
                    'msg' => '请先完成实名认证后再获取密钥',
                    'data' => $gates,
                ];
            }
        }

        if (self::flag(self::GATE_PHONE)) {
            $hasPhone = trim((string) $client->phonenumber) !== '';
            $gates['phone'] = $hasPhone;

            if (! $hasPhone) {
                return [
                    'status' => false,
                    'msg' => '请先绑定手机号后再获取密钥',
                    'data' => $gates,
                ];
            }
        }

        return [
            'status' => true,
            'msg' => '可以获取密钥',
            'data' => $gates,
        ];
    }

    /**
     * A client may draw from the pool only when every enabled gate passes and
     * the client's own API switch is on.
     */
    public static function canAccess(?Client $client): bool
    {
        $access = self::checkAccess($client);

        if (! $access['status']) {
            return false;
        }

        return (int) ($client?->api_open ?? 0) === 1;
    }

    /**
     * Identity verification spans two tables: `shd_certification` is the
     * current panel's record (status 1 = verified), while `shd_certifi_person`
     * and `shd_certifi_company` hold the older per-type submissions.
     */
    public static function isRealNameVerified(Client $client): bool
    {
        $uid = (int) $client->id;

        if ($uid <= 0) {
            return false;
        }

        try {
            $current = DB::table('certification')
                ->where('client_id', $uid)
                ->where('status', 1)
                ->exists();

            if ($current) {
                return true;
            }

            $person = DB::table('certifi_person')
                ->where('auth_user_id', $uid)
                ->where('status', 1)
                ->exists();

            if ($person) {
                return true;
            }

            return DB::table('certifi_company')
                ->where('auth_user_id', $uid)
                ->where('status', 1)
                ->exists();
        } catch (\Throwable) {
            // A missing verification table must not grant access.
            return false;
        }
    }

    // ---------------------------------------------------------------------
    // Per-client product entitlements
    // ---------------------------------------------------------------------

    /**
     * `shd_api_user_product` rows for a client.
     *
     * @return array<int, array{pid:int,qty:int,ontrial:int}>
     */
    public static function entitlements(int $uid): array
    {
        if ($uid <= 0) {
            return [];
        }

        return DB::table('api_user_product')
            ->where('uid', $uid)
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => [
                'pid' => (int) $row->pid,
                'qty' => (int) $row->qty,
                'ontrial' => (int) $row->ontrial,
            ])
            ->all();
    }

    /**
     * Create or update a client's cap for one product.
     */
    public static function grant(int $uid, int $productId, int $qty, int $ontrial = 0): void
    {
        $existing = DB::table('api_user_product')
            ->where('uid', $uid)
            ->where('pid', $productId)
            ->first();

        if ($existing !== null) {
            DB::table('api_user_product')->where('id', $existing->id)->update([
                'qty' => $qty,
                'ontrial' => $ontrial,
            ]);

            return;
        }

        DB::table('api_user_product')->insert([
            'uid' => $uid,
            'pid' => $productId,
            'qty' => $qty,
            'ontrial' => $ontrial,
        ]);
    }

    public static function revoke(int $uid, int $productId): void
    {
        DB::table('api_user_product')->where('uid', $uid)->where('pid', $productId)->delete();
    }

    /**
     * May this client buy `$qty` more of this product from the pool?
     *
     * A client with no entitlement row for the product is unrestricted only
     * when the pool has no per-client caps at all; once any row exists for the
     * client, an absent product row means "not granted".
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    public static function checkProductQuota(?Client $client, Product $product, int $qty = 1): array
    {
        if ($client === null) {
            return UpstreamResponse::fail('请先登录')->toArray();
        }

        $entitlements = self::entitlements((int) $client->id);

        if ($entitlements === []) {
            return UpstreamResponse::ok(null, '无数量限制')->toArray();
        }

        $limit = null;

        foreach ($entitlements as $row) {
            if ($row['pid'] === (int) $product->id) {
                $limit = $row['qty'];
                break;
            }
        }

        if ($limit === null) {
            return UpstreamResponse::fail('该商品未授权使用资源池')->toArray();
        }

        $owned = self::ownedCount((int) $client->id, (int) $product->id);

        if ($limit >= 0 && ($owned + $qty) > $limit) {
            return UpstreamResponse::fail('已达到该商品的最大购买数量')->toArray();
        }

        return UpstreamResponse::ok(['owned' => $owned, 'limit' => $limit], '可以购买')->toArray();
    }

    public static function ownedCount(int $uid, int $productId): int
    {
        return (int) DB::table('host')
            ->where('uid', $uid)
            ->where('productid', $productId)
            ->whereIn('domainstatus', [
                Host::STATUS_PENDING,
                Host::STATUS_ACTIVE,
                Host::STATUS_SUSPENDED,
            ])
            ->count();
    }

    // ---------------------------------------------------------------------
    // Allocation
    // ---------------------------------------------------------------------

    /**
     * Products resellable from the pool.
     *
     * @return array<int, array<string, mixed>>
     */
    public function products(): array
    {
        if ($this->pool === null) {
            return [];
        }

        return Product::query()
            ->where('zjmf_api_id', (int) $this->pool->id)
            ->whereIn('api_type', [Product::API_TYPE_RESOURCE, Product::API_TYPE_ZJMF])
            ->orderBy('id')
            ->get()
            ->map(fn (Product $product) => [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'upstream_pid' => (int) $product->upstream_pid,
                'qty' => (int) $product->upstream_qty,
                'stock_control' => (int) $product->upstream_stock_control,
                'auto_setup' => (string) $product->upstream_auto_setup,
                'ontrial' => (int) $product->upstream_ontrial_status,
            ])
            ->all();
    }

    /**
     * Draw one service for a client out of the pool.
     *
     * Enforces the access gates, the per-client quota and stock before
     * touching the upstream, so a client without resource API access can never
     * pull from the pool even by calling this directly.
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function allocate(Client $client, Product $product, array $options = []): array
    {
        if ($this->pool === null) {
            return UpstreamResponse::fail('未配置资源池')->toArray();
        }

        $access = self::checkAccess($client);

        if (! $access['status']) {
            return $access;
        }

        if ((int) $client->api_open !== 1) {
            return UpstreamResponse::fail('该客户未开启资源API')->toArray();
        }

        $qty = max(1, (int) ($options['qty'] ?? 1));

        $quota = self::checkProductQuota($client, $product, $qty);

        if (! $quota['status']) {
            return $quota;
        }

        if ((int) $product->upstream_stock_control === 1 && (int) $product->upstream_qty < $qty) {
            return UpstreamResponse::fail('上游库存不足')->toArray();
        }

        $clientApi = $this->client();

        if ($clientApi === null) {
            return UpstreamResponse::fail('资源池未配置有效接口')->toArray();
        }

        $result = $clientApi->addToCart([
            'product_id' => (int) $product->upstream_pid,
            'billingcycle' => (string) ($options['billingcycle'] ?? 'monthly'),
            'qty' => $qty,
            'host' => (string) ($options['host'] ?? ''),
            'password' => (string) ($options['password'] ?? ''),
            'configoption' => $options['configoption'] ?? [],
            'customfield' => $options['customfield'] ?? [],
        ]);

        if (! $result['status']) {
            return $result;
        }

        return [
            'status' => true,
            'msg' => '加入资源池购物车成功',
            'data' => [
                'cart' => $result['data'],
                'qty' => $qty,
                'pool_id' => (int) $this->pool->id,
            ],
        ];
    }

    /**
     * Hand a pool-drawn service back.
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function release(Host $host): array
    {
        $clientApi = $this->client();

        if ($clientApi === null) {
            return UpstreamResponse::fail('资源池未配置有效接口')->toArray();
        }

        return $clientApi->terminateAccount($host);
    }

    /**
     * Pool balance, for the admin overview.
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function credit(): array
    {
        $clientApi = $this->client();

        if ($clientApi === null) {
            return UpstreamResponse::fail('资源池未配置有效接口')->toArray();
        }

        return $clientApi->credit();
    }

    /**
     * One configuration flag as a boolean.
     */
    protected static function flag(string $key): bool
    {
        try {
            $value = DB::table('configuration')->where('setting', $key)->value('value');
        } catch (\Throwable) {
            return false;
        }

        return $value !== null && in_array((string) $value, ['1', 'true', 'on'], true);
    }
}
