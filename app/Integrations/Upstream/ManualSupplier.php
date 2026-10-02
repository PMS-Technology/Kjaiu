<?php

namespace App\Integrations\Upstream;

use App\Models\Host;
use App\Models\UpperReach;
use App\Models\UpperReachIp;
use App\Models\UpperReachRes;
use Illuminate\Support\Facades\DB;

/**
 * Supplier backed by hand-entered inventory (`manual` type).
 *
 * A manual upstream has no API: the administrator keys servers into
 * `shd_upper_reaches` and its child tables, and provisioning is a local
 * bookkeeping operation. Product rows reference the pool through
 * `shd_products`.`upper_reaches_id`.
 *
 * Allocation state is encoded the way the original stores it:
 *   - `shd_upper_reaches_res`.`pid`  = 0 while free, the host id once used
 *   - `shd_upper_reaches_res`.`mark` = remaining quantity for multi-unit rows
 *   - `shd_upper_reaches_ip`.`resid` = 0 while free, the resource id once bound
 */
class ManualSupplier
{
    protected ?UpperReach $upstream = null;

    protected int $upstreamId = 0;

    public function __construct(UpperReach|int|null $upstream = null)
    {
        if ($upstream instanceof UpperReach) {
            $this->upstream = $upstream;
            $this->upstreamId = (int) $upstream->id;
        } elseif (is_int($upstream)) {
            $this->upstreamId = $upstream;
        }
    }

    /**
     * Resolve from the product a host was sold from.
     */
    public static function forHost(Host $host): ?self
    {
        $product = $host->product;

        if ($product === null) {
            return null;
        }

        $id = (int) $product->upper_reaches_id;

        if ($id <= 0) {
            return null;
        }

        return new self($id);
    }

    public function upstream(): ?UpperReach
    {
        if ($this->upstream === null && $this->upstreamId > 0) {
            $this->upstream = UpperReach::query()->find($this->upstreamId);
        }

        return $this->upstream;
    }

    /**
     * Every resource row belonging to this manual upstream.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resources(): array
    {
        if ($this->upstreamId <= 0) {
            return [];
        }

        return UpperReachRes::query()
            ->where('hid', $this->upstreamId)
            ->orderBy('id')
            ->get()
            ->map(fn (UpperReachRes $res) => $this->present($res))
            ->all();
    }

    /**
     * Resources still available for allocation.
     *
     * @return array<int, array<string, mixed>>
     */
    public function available(): array
    {
        return array_values(array_filter($this->resources(), fn (array $res) => $res['free']));
    }

    /**
     * Resource currently bound to a host, if any.
     *
     * A consumed row points at the host through `pid`. A service taken from a
     * multi-unit row leaves the row in the pool, so it is found through the
     * address that was handed out (`resid` = -host id).
     */
    public function forHostResource(int $hostId): ?UpperReachRes
    {
        if ($this->upstreamId <= 0 || $hostId <= 0) {
            return null;
        }

        $res = UpperReachRes::query()
            ->where('hid', $this->upstreamId)
            ->where('pid', $hostId)
            ->orderBy('id')
            ->first();

        if ($res !== null) {
            return $res;
        }

        $ip = UpperReachIp::query()->where('resid', -$hostId)->orderBy('id')->first();

        if ($ip === null) {
            return null;
        }

        return UpperReachRes::query()
            ->where('hid', $this->upstreamId)
            ->where('ip', $ip->ip)
            ->orderBy('id')
            ->first();
    }

    /**
     * Bind a free resource (and its IPs) to a host.
     *
     * Runs inside a transaction with a row lock so two concurrent
     * provisioning jobs cannot be handed the same machine.
     *
     * @param  Host|int  $host
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function allocate(Host|int $host): array
    {
        $hostId = $host instanceof Host ? (int) $host->id : (int) $host;

        if ($this->upstreamId <= 0) {
            return UpstreamResponse::fail('未指定手动资源上游')->toArray();
        }

        if ($hostId <= 0) {
            return UpstreamResponse::fail('缺少产品ID，无法分配资源')->toArray();
        }

        try {
            return DB::transaction(function () use ($hostId) {
                // Already allocated to this host: idempotent re-run. A consumed
                // row points at the host via `pid`; a multi-unit row via the
                // address that was handed out.
                $existing = $this->forHostResource($hostId);

                if ($existing !== null) {
                    return [
                        'status' => true,
                        'msg' => '资源已分配',
                        'data' => $this->assignedPayload($existing, $this->heldIp($hostId)),
                    ];
                }

                $res = $this->nextFreeRow();

                if ($res === null) {
                    return UpstreamResponse::fail('手动资源池已无可用资源')->toArray();
                }

                $now = time();
                $ips = $this->ipsFor($res);
                $assignedIp = '';

                // A row still carrying quantity stays in the pool: `mark` is
                // the remaining count and `pid` stays 0 so the row can be
                // allocated again. The last unit is consumed outright.
                $remaining = (int) $res->mark;

                if ($remaining > 1) {
                    // Bind one of the row's own IPs to this host by handing it
                    // out of the row's address list. The row keeps its own
                    // `ip` column so the remaining units stay identifiable.
                    $assignedIp = array_shift($ips) ?? trim((string) $res->ip);

                    if ($assignedIp !== '') {
                        $this->unbindIp($assignedIp, $hostId);
                    }

                    UpperReachRes::query()->where('id', $res->id)->update([
                        'mark' => $remaining - 1,
                        'update_time' => $now,
                    ]);
                } else {
                    // Consume the row, keeping its own address as the service IP.
                    $assignedIp = $this->bindIp($res, $hostId);

                    UpperReachRes::query()->where('id', $res->id)->update([
                        'pid' => $hostId,
                        'mark' => 0,
                        'ip' => $assignedIp !== '' ? $assignedIp : $res->ip,
                        'update_time' => $now,
                    ]);
                }

                $res = $res->refresh();

                return [
                    'status' => true,
                    'msg' => '资源分配成功',
                    'data' => $this->assignedPayload($res, $assignedIp),
                ];
            });
        } catch (\Throwable $e) {
            return UpstreamResponse::fail('资源分配失败：' . $e->getMessage())->toArray();
        }
    }

    /**
     * Release whatever this host holds back into the pool.
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function release(Host|int $host): array
    {
        $hostId = $host instanceof Host ? (int) $host->id : (int) $host;

        if ($this->upstreamId <= 0 || $hostId <= 0) {
            return UpstreamResponse::fail('未指定手动资源上游')->toArray();
        }

        try {
            return DB::transaction(function () use ($hostId) {
                $res = UpperReachRes::query()
                    ->where('hid', $this->upstreamId)
                    ->where('pid', $hostId)
                    ->lockForUpdate()
                    ->first();

                $now = time();

                if ($res !== null) {
                    // The row itself was consumed: return it to the pool.
                    UpperReachRes::query()->where('id', $res->id)->update([
                        'pid' => 0,
                        'mark' => 1,
                        'power_status' => '',
                        'update_time' => $now,
                    ]);

                    UpperReachIp::query()->where('resid', $res->id)->update(['resid' => 0]);

                    return [
                        'status' => true,
                        'msg' => '资源已释放',
                        'data' => ['id' => (int) $res->id, 'host_id' => $hostId],
                    ];
                }

                // The service came out of a multi-unit row, which stayed in the
                // pool: give its address back and restore the unit count.
                $handedOut = UpperReachIp::query()
                    ->where('resid', -$hostId)
                    ->get();

                if ($handedOut->isEmpty()) {
                    return UpstreamResponse::fail('该产品未占用手动资源')->toArray();
                }

                foreach ($handedOut as $ip) {
                    // Only restore addresses that actually belong to a pool row.
                    $owner = UpperReachRes::query()
                        ->where('hid', $this->upstreamId)
                        ->where('ip', $ip->ip)
                        ->lockForUpdate()
                        ->first();

                    if ($owner !== null) {
                        UpperReachIp::query()->where('id', $ip->id)->update(['resid' => 0]);

                        UpperReachRes::query()->where('id', $owner->id)->update([
                            'mark' => min(5000, (int) $owner->mark + 1),
                            'update_time' => $now,
                        ]);
                    } else {
                        UpperReachIp::query()->where('id', $ip->id)->update(['resid' => 0]);
                    }
                }

                return [
                    'status' => true,
                    'msg' => '资源已释放',
                    'data' => [
                        'ips' => $handedOut->pluck('ip')->all(),
                        'host_id' => $hostId,
                    ],
                ];
            });
        } catch (\Throwable $e) {
            return UpstreamResponse::fail('资源释放失败：' . $e->getMessage())->toArray();
        }
    }

    // ---------------------------------------------------------------------
    // SupplierClient-compatible surface
    // ---------------------------------------------------------------------

    /**
     * Manual delivery: allocate inventory and report the credentials.
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function createAccount(Host $host): array
    {
        return $this->allocate($host);
    }

    /**
     * There is nothing to power off on hand-entered inventory, so suspending
     * only records the state. The resource stays bound to the host.
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function suspendAccount(Host $host): array
    {
        return $this->setPowerStatus($host, 'off');
    }

    /**
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function unsuspendAccount(Host $host): array
    {
        return $this->setPowerStatus($host, 'on');
    }

    /**
     * Terminating returns the machine to the pool.
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function terminateAccount(Host $host): array
    {
        return $this->release($host);
    }

    /**
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function hostStatus(Host $host): array
    {
        $res = $this->forHostResource((int) $host->id);

        if ($res === null) {
            return UpstreamResponse::fail('该产品未占用手动资源')->toArray();
        }

        return [
            'status' => true,
            'msg' => '获取成功',
            'data' => [
                'status' => (string) ($res->power_status ?: 'unknown'),
                'ip' => (string) ($res->in_ip ?: $res->ip),
            ],
        ];
    }

    /**
     * Credentials on a manual resource live in the `root`/`pwd` columns when
     * the resource was imported from DCIM, and in `username`/`password`
     * otherwise. Prefer the hand-entered pair.
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function changePassword(Host $host, string $password): array
    {
        $res = $this->forHostResource((int) $host->id);

        if ($res === null) {
            return UpstreamResponse::fail('该产品未占用手动资源')->toArray();
        }

        UpperReachRes::query()->where('id', $res->id)->update([
            'password' => $password,
            'pwd' => $password,
            'update_time' => time(),
        ]);

        $host->password = $password;
        $host->save();

        return UpstreamResponse::ok(null, '密码修改成功')->toArray();
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Next allocatable resource row, locked for update.
     *
     * A row is free when it is not bound to a host (`pid` = 0). `mark` is the
     * remaining sellable quantity and is left untouched by the check — a
     * single-unit row legitimately carries 0 and is still allocatable.
     */
    protected function nextFreeRow(): ?UpperReachRes
    {
        return UpperReachRes::query()
            ->where('hid', $this->upstreamId)
            ->where(function ($query) {
                $query->where('pid', 0)->orWhereNull('pid');
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Bind a free IP to the resource.
     *
     * Prefers the resource's own address when it is still unbound, so a row
     * keeps the IP it was imported with; otherwise takes the next free address
     * from the upstream's IP table.
     */
    protected function bindIp(UpperReachRes $res, int $hostId): string
    {
        $own = trim((string) $res->ip);

        if ($own !== '' && $this->isIpFree($own)) {
            $this->markIpUsed($own, $res->id);

            return $own;
        }

        $ip = UpperReachIp::query()
            ->where(function ($query) {
                $query->where('resid', 0)->orWhereNull('resid');
            })
            ->where('ip', '!=', '')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($ip !== null) {
            UpperReachIp::query()->where('id', $ip->id)->update(['resid' => $res->id]);

            return (string) $ip->ip;
        }

        // No IP inventory left for this upstream: fall back to the row's own.
        return $own;
    }

    /**
     * The address currently issued to a host, if it came out of a pool row.
     */
    protected function heldIp(int $hostId): string
    {
        return (string) (UpperReachIp::query()
            ->where('resid', -$hostId)
            ->orderBy('id')
            ->value('ip') ?? '');
    }

    /**
     * Hand one specific address out of a multi-unit row.
     *
     * `resid` is set to the negated host id: negative means "issued from a
     * pool row", which is what lets release find it again.
     */
    protected function unbindIp(string $ip, int $hostId): void
    {
        $row = UpperReachIp::query()->where('ip', $ip)->orderBy('id')->first();

        if ($row !== null) {
            UpperReachIp::query()->where('id', $row->id)->update(['resid' => -$hostId]);

            return;
        }

        // No inventory row for this address; record it so it is not reissued.
        UpperReachIp::query()->insert(['ip' => $ip, 'resid' => -$hostId]);
    }

    protected function isIpFree(string $ip): bool
    {
        return ! UpperReachIp::query()
            ->where('ip', $ip)
            ->where('resid', '>', 0)
            ->exists();
    }

    protected function markIpUsed(string $ip, int $resId): void
    {
        $row = UpperReachIp::query()->where('ip', $ip)->where('resid', '<=', 0)->orderBy('id')->first();

        if ($row !== null) {
            UpperReachIp::query()->where('id', $row->id)->update(['resid' => $resId]);

            return;
        }

        if (! UpperReachIp::query()->where('ip', $ip)->exists()) {
            UpperReachIp::query()->insert(['ip' => $ip, 'resid' => $resId]);
        }
    }

    /**
     * @return array{status:bool,msg:string,data:mixed}
     */
    protected function setPowerStatus(Host $host, string $status): array
    {
        $res = $this->forHostResource((int) $host->id);

        if ($res === null) {
            return UpstreamResponse::fail('该产品未占用手动资源')->toArray();
        }

        UpperReachRes::query()->where('id', $res->id)->update([
            'power_status' => $status,
            'update_time' => time(),
        ]);

        return UpstreamResponse::ok(null, $status === 'on' ? '开机成功' : '关机成功')->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(UpperReachRes $res): array
    {
        $ips = $this->ipsFor($res);

        return [
            'id' => (int) $res->id,
            'host_id' => (int) $res->pid,
            // Free means "not bound to a host and still has sellable units".
            'free' => (int) $res->pid <= 0 && (int) $res->mark > 0,
            'name' => (string) ($res->username ?: $res->root),
            'ip' => (string) ($res->ip ?: ($ips[0] ?? '')),
            'in_ip' => (string) $res->in_ip,
            'ips' => $ips,
            'qty' => (int) $res->mark,
            'power_status' => (string) $res->power_status,
            'os' => (string) $res->pz,
            'create_time' => (int) $res->create_time,
        ];
    }

    /**
     * Addresses currently held against a pool row.
     *
     * Both `resid = row id` (the row was consumed) and `resid < 0` with the
     * row's own address (issued from a multi-unit row) count as held.
     *
     * @return array<int, string>
     */
    protected function ipsFor(UpperReachRes $res): array
    {
        return UpperReachIp::query()
            ->where(function ($query) use ($res) {
                $query->where('resid', $res->id)
                    ->orWhere(function ($inner) use ($res) {
                        $inner->where('resid', '<', 0)->where('ip', $res->ip);
                    });
            })
            ->pluck('ip')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Payload returned to the provisioning caller.
     *
     * @return array<string, mixed>
     */
    protected function assignedPayload(UpperReachRes $res, string $ip = ''): array
    {
        $ips = $ip !== '' ? array_merge([$ip], $this->ipsFor($res)) : $this->ipsFor($res);

        return [
            'resource_id' => (int) $res->id,
            'upstream_id' => $this->upstreamId,
            'dedicatedip' => $ip !== '' ? $ip : (string) $res->ip,
            'assignedips' => array_values(array_unique(array_filter($ips))),
            'in_ip' => (string) $res->in_ip,
            'username' => (string) ($res->username ?: $res->root),
            'password' => (string) ($res->password ?: $res->pwd),
            'os' => (string) $res->pz,
            'ipmi' => (string) $res->ipmi,
        ];
    }
}
