<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('price_histories', function (Blueprint $table) {
            $table->id();
            $table->string('listing_url', 500);
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('previous_price', 10, 2)->nullable();
            $table->boolean('is_available')->default(true);
            $table->string('change_reason')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['listing_url', 'checked_at']);
            $table->index(['checked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_histories');
    }
};
