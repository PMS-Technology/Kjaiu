<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('product_groups')->nullOnDelete();
            $table->string('name');
            $table->string('headline')->default('');
            $table->string('tagline')->default('');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_group_id')->constrained('product_groups')->cascadeOnDelete();
            $table->string('type', 64)->default('server');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('pay_method', 32)->default('prepayment');
            $table->string('billing_cycle', 32)->default('monthly');
            $table->decimal('price', 18, 2)->default(0);
            $table->decimal('setup_fee', 18, 2)->default(0);
            $table->boolean('stock_control')->default(false);
            $table->unsignedInteger('quantity')->nullable();
            $table->boolean('auto_setup')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->integer('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('billing_cycle', 32);
            $table->decimal('price', 18, 2);
            $table->decimal('setup_fee', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'billing_cycle']);
        });

        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('title');
            $table->string('icon')->default('');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->longText('configuration')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateways');
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_groups');
    }
};
