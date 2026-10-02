<?php

namespace App\Integrations\Upstream;

use App\Models\FinanceApi;

/**
 * Resolves a supplier row into the client that talks to it.
 *
 * Kept deliberately dumb: callers that only have a `zjmf_api_id` (a product or
 * an admin request) get the right integration without knowing about supplier
 * types. Manual and resource-pool suppliers are not API-reachable, so they
 * resolve to null and the caller falls back to ManualSupplier / ResourcePool.
 */
class SupplierClientFactory
{
    /**
     * @param  int  $financeApiId  `shd_zjmf_finance_api`.`id`
     */
    public static function make(int $financeApiId): ?SupplierClient
    {
        if ($financeApiId <= 0) {
            return null;
        }

        $api = FinanceApi::query()->find($financeApiId);

        if ($api === null) {
            return null;
        }

        return self::forApi($api);
    }

    /**
     * Same, from an already-loaded row.
     */
    public static function forApi(?FinanceApi $api): ?SupplierClient
    {
        if ($api === null) {
            return null;
        }

        // Only `zjmf_api` and `v10` speak the /v1 protocol; `manual` suppliers
        // are backed by the local resource tables instead (see ManualSupplier).
        if (! in_array((string) $api->type, [FinanceApi::TYPE_API, 'v10'], true)) {
            return null;
        }

        if (trim((string) $api->hostname) === '') {
            return null;
        }

        return new SupplierClient($api);
    }

    /**
     * The client for the supplier a product is resold from, if any.
     */
    public static function forProduct(\App\Models\Product $product): ?SupplierClient
    {
        return self::make((int) $product->zjmf_api_id);
    }

    /**
     * Every usable API supplier, keyed by id.
     *
     * @return array<int, SupplierClient>
     */
    public static function all(): array
    {
        $clients = [];

        foreach (FinanceApi::query()->orderBy('id')->get() as $api) {
            $client = self::forApi($api);

            if ($client !== null) {
                $clients[(int) $api->id] = $client;
            }
        }

        return $clients;
    }
}
