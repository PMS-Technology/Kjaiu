<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_catalog_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_catalog_product_id')->unique();
            $table->foreignId('product_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(
                ['supplier_catalog_product_id', 'supplier_account_id'],
                'sup_import_catalog_account_fk',
            )->references(['id', 'supplier_account_id'])
                ->on('supplier_catalog_products')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_catalog_imports');
    }
};
