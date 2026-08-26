<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $binaryCollation = $driver === 'mysql'
            ? 'ascii_bin'
            : 'BINARY';
        $activeRouteExpression = $driver === 'mysql'
            ? "case when `is_active` = 1 then concat(cast(`product_id` as char), '|', `local_billing_cycle`) else null end"
            : "case when \"is_active\" = 1 then cast(\"product_id\" as text) || '|' || \"local_billing_cycle\" else null end";

        Schema::create('supplier_accounts', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->string('code', 64)->unique('sup_accounts_code_uq');
            $table->string('name', 191);
            $table->string('driver', 64)->default('idcsmart_finance');
            $table->text('base_url');
            $table->longText('credentials')->nullable();
            $table->json('options')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_tested_at')->nullable();
            $table->dateTime('last_connected_at')->nullable();
            $table->dateTime('last_catalog_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['driver', 'is_active'], 'sup_accounts_driver_active_idx');
        });

        Schema::create('supplier_catalog_products', function (Blueprint $table) use ($binaryCollation) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('supplier_account_id')->constrained()->restrictOnDelete();
            $table->string('upstream_product_id', 128)
                ->charset('ascii')->collation($binaryCollation);
            $table->string('upstream_group_id', 128)->nullable()
                ->charset('ascii')->collation($binaryCollation);
            $table->string('type', 64)->nullable();
            $table->string('name', 191)->default('');
            $table->text('description')->nullable();
            $table->string('currency', 8)->nullable();
            $table->decimal('minimum_price', 18, 2)->nullable();
            $table->json('billing_cycles')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['supplier_account_id', 'upstream_product_id'],
                'sup_catalog_account_product_uq'
            );
            $table->unique(['id', 'supplier_account_id'], 'sup_catalog_id_account_uq');
            $table->index(['supplier_account_id', 'is_active'], 'sup_catalog_account_active_idx');
        });

        Schema::create('supplier_product_mappings', function (Blueprint $table) use (
            $activeRouteExpression,
            $binaryCollation,
        ) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('supplier_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_catalog_product_id');
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('local_billing_cycle', 32)
                ->charset('ascii')->collation($binaryCollation);
            $table->string('upstream_billing_cycle', 32)
                ->charset('ascii')->collation($binaryCollation);
            $table->json('options')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('active_route_key', 64)->nullable()
                ->charset('ascii')->collation($binaryCollation)
                ->virtualAs($activeRouteExpression);
            $table->timestamps();

            $table->unique(
                'active_route_key',
                'sup_mapping_active_route_uq'
            );
            $table->unique(['id', 'supplier_account_id'], 'sup_mapping_id_account_uq');
            $table->unique(
                ['id', 'supplier_account_id', 'supplier_catalog_product_id', 'product_id'],
                'sup_mapping_route_identity_uq'
            );
            $table->foreign(
                ['supplier_catalog_product_id', 'supplier_account_id'],
                'sup_mapping_catalog_account_fk'
            )->references(['id', 'supplier_account_id'])
                ->on('supplier_catalog_products')
                ->restrictOnDelete();
        });

        Schema::create('supplier_order_item_routes', function (Blueprint $table) use ($binaryCollation) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('supplier_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_product_mapping_id');
            $table->foreignId('supplier_catalog_product_id');
            $table->foreignId('order_item_id')->unique('sup_route_order_item_uq');
            $table->foreignId('local_product_id');
            $table->string('local_billing_cycle', 32)
                ->charset('ascii')->collation($binaryCollation);
            $table->string('upstream_product_id', 128)
                ->charset('ascii')->collation($binaryCollation);
            $table->string('upstream_billing_cycle', 32)
                ->charset('ascii')->collation($binaryCollation);
            $table->decimal('local_unit_amount', 18, 2);
            $table->decimal('local_setup_amount', 18, 2);
            $table->string('local_currency', 8);
            $table->decimal('expected_upstream_amount', 18, 2);
            $table->string('expected_upstream_currency', 8);
            $table->char('account_identity_hash', 64)
                ->charset('ascii')->collation($binaryCollation);
            $table->char('request_hash', 64)
                ->charset('ascii')->collation($binaryCollation);
            $table->longText('snapshot');
            $table->timestamps();

            $table->unique(
                ['id', 'supplier_account_id', 'order_item_id', 'supplier_product_mapping_id'],
                'sup_route_operation_refs_uq'
            );
            $table->unique(['id', 'supplier_account_id'], 'sup_route_id_account_uq');
            $table->foreign('order_item_id', 'sup_route_order_item_fk')
                ->references('id')
                ->on('order_items')
                ->restrictOnDelete();
            $table->foreign('local_product_id', 'sup_route_local_product_fk')
                ->references('id')
                ->on('products')
                ->restrictOnDelete();
            $table->foreign(
                ['supplier_catalog_product_id', 'supplier_account_id'],
                'sup_route_catalog_account_fk'
            )->references(['id', 'supplier_account_id'])
                ->on('supplier_catalog_products')
                ->restrictOnDelete();
            $table->foreign(
                [
                    'supplier_product_mapping_id',
                    'supplier_account_id',
                    'supplier_catalog_product_id',
                    'local_product_id',
                ],
                'sup_route_mapping_identity_fk'
            )->references([
                'id',
                'supplier_account_id',
                'supplier_catalog_product_id',
                'product_id',
            ])->on('supplier_product_mappings')
                ->restrictOnDelete();
        });

        Schema::create('supplier_service_links', function (Blueprint $table) use ($binaryCollation) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('supplier_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_product_mapping_id')->nullable();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->string('upstream_service_id', 128)
                ->charset('ascii')->collation($binaryCollation);
            $table->string('upstream_status', 32)->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['supplier_account_id', 'service_id'], 'sup_service_account_local_uq');
            $table->unique(
                ['supplier_account_id', 'upstream_service_id'],
                'sup_service_account_upstream_uq'
            );
            $table->unique(['id', 'supplier_account_id'], 'sup_service_id_account_uq');
            $table->foreign(
                ['supplier_product_mapping_id', 'supplier_account_id'],
                'sup_service_mapping_account_fk'
            )->references(['id', 'supplier_account_id'])
                ->on('supplier_product_mappings')
                ->restrictOnDelete();
        });

        Schema::create('supplier_invoice_links', function (Blueprint $table) use ($binaryCollation) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('supplier_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_service_link_id')->nullable();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('upstream_order_id', 128)->nullable()
                ->charset('ascii')->collation($binaryCollation);
            $table->string('upstream_invoice_id', 128)->nullable()
                ->charset('ascii')->collation($binaryCollation);
            $table->string('upstream_status', 32)->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['supplier_account_id', 'invoice_id'], 'sup_invoice_account_local_idx');
            $table->unique(
                ['supplier_account_id', 'upstream_invoice_id'],
                'sup_invoice_account_upstream_uq'
            );
            $table->unique(
                ['supplier_account_id', 'upstream_order_id'],
                'sup_invoice_account_order_uq'
            );
            $table->unique(['id', 'supplier_account_id'], 'sup_invoice_id_account_uq');
            $table->foreign(
                ['supplier_service_link_id', 'supplier_account_id'],
                'sup_invoice_service_account_fk'
            )->references(['id', 'supplier_account_id'])
                ->on('supplier_service_links')
                ->restrictOnDelete();
        });

        Schema::create('supplier_operations', function (Blueprint $table) use ($binaryCollation) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('supplier_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_order_item_route_id')->nullable();
            $table->foreignId('supplier_product_mapping_id')->nullable();
            $table->foreignId('supplier_service_link_id')->nullable();
            $table->foreignId('supplier_invoice_link_id')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action', 64);
            $table->string('status', 32)->default('queued');
            $table->string('step', 64)->nullable();
            $table->string('idempotency_key', 128)
                ->charset('ascii')->collation($binaryCollation);
            $table->char('request_hash', 64)
                ->charset('ascii')->collation($binaryCollation);
            $table->string('upstream_reference', 128)->nullable()
                ->charset('ascii')->collation($binaryCollation);
            $table->longText('request_payload')->nullable();
            $table->longText('response_payload')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->json('metadata')->nullable();
            $table->dateTime('available_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['supplier_account_id', 'idempotency_key'],
                'sup_operation_account_idempotency_uq'
            );
            $table->index(['supplier_account_id', 'status'], 'sup_operation_account_status_idx');
            $table->index(['status', 'available_at'], 'sup_operation_status_available_idx');
            $table->foreign('supplier_order_item_route_id', 'sup_operation_route_fk')
                ->references('id')
                ->on('supplier_order_item_routes')
                ->restrictOnDelete();
            $table->foreign(
                ['supplier_order_item_route_id', 'supplier_account_id'],
                'sup_operation_route_account_fk'
            )->references(['id', 'supplier_account_id'])
                ->on('supplier_order_item_routes')
                ->restrictOnDelete();
            $table->foreign(
                [
                    'supplier_order_item_route_id',
                    'supplier_account_id',
                    'order_item_id',
                    'supplier_product_mapping_id',
                ],
                'sup_operation_route_refs_fk'
            )->references([
                'id',
                'supplier_account_id',
                'order_item_id',
                'supplier_product_mapping_id',
            ])->on('supplier_order_item_routes')
                ->restrictOnDelete();
            $table->foreign(
                ['supplier_product_mapping_id', 'supplier_account_id'],
                'sup_operation_mapping_account_fk'
            )->references(['id', 'supplier_account_id'])
                ->on('supplier_product_mappings')
                ->restrictOnDelete();
            $table->foreign(
                ['supplier_service_link_id', 'supplier_account_id'],
                'sup_operation_service_account_fk'
            )->references(['id', 'supplier_account_id'])
                ->on('supplier_service_links')
                ->restrictOnDelete();
            $table->foreign(
                ['supplier_invoice_link_id', 'supplier_account_id'],
                'sup_operation_invoice_account_fk'
            )->references(['id', 'supplier_account_id'])
                ->on('supplier_invoice_links')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_operations');
        Schema::dropIfExists('supplier_invoice_links');
        Schema::dropIfExists('supplier_service_links');
        Schema::dropIfExists('supplier_order_item_routes');
        Schema::dropIfExists('supplier_product_mappings');
        Schema::dropIfExists('supplier_catalog_products');
        Schema::dropIfExists('supplier_accounts');
    }
};
