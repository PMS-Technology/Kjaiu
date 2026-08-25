<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('Pending')->index();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->string('currency', 8)->default('CNY');
            $table->string('idempotency_key', 64)->nullable();
            $table->string('promo_code')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->string('billing_cycle', 32);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('reserved_quantity')->default(0);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('setup_fee', 18, 2)->default(0);
            $table->decimal('amount', 18, 2);
            $table->json('configuration')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('number', 48)->unique();
            $table->string('idempotency_key', 64)->nullable();
            $table->string('renewal_key', 64)->nullable()->unique();
            $table->dateTime('renewal_due_at')->nullable();
            $table->string('status', 32)->default('Unpaid')->index();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->string('currency', 8)->default('CNY');
            $table->dateTime('due_at')->nullable()->index();
            $table->dateTime('paid_at')->nullable();
            $table->string('payment_method')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('unit_index')->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('domain')->default('');
            $table->string('type', 64)->default('server');
            $table->string('status', 32)->default('Pending')->index();
            $table->decimal('first_payment_amount', 18, 2)->default(0);
            $table->decimal('renew_amount', 18, 2)->default(0);
            $table->string('billing_cycle', 32)->default('monthly');
            $table->unsignedTinyInteger('billing_anchor_day')->nullable();
            $table->dateTime('registered_at')->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('next_due_at')->nullable()->index();
            $table->string('dedicated_ip', 64)->nullable();
            $table->json('assigned_ips')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamps();
            $table->unique(['order_item_id', 'unit_index']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('unit_index')->nullable();
            $table->string('type', 32)->default('host');
            $table->string('billing_cycle', 32)->nullable();
            $table->unsignedBigInteger('rel_id')->nullable()->index();
            $table->text('description');
            $table->decimal('amount', 18, 2);
            $table->timestamps();
            $table->unique(['invoice_id', 'order_item_id', 'unit_index']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('transaction_number', 191)->unique();
            $table->string('idempotency_key', 64)->nullable();
            $table->string('type', 32)->index();
            $table->string('gateway', 64)->default('Credit');
            $table->decimal('amount_in', 18, 2)->default(0);
            $table->decimal('amount_out', 18, 2)->default(0);
            $table->decimal('fee', 18, 2)->default(0);
            $table->decimal('balance_before', 18, 2)->default(0);
            $table->decimal('balance_after', 18, 2)->default(0);
            $table->string('currency', 8)->default('CNY');
            $table->dateTime('paid_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('billing_cycle', 32);
            $table->unsignedInteger('quantity')->default(1);
            $table->json('configuration')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('services');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
