<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('price_histories', function (Blueprint $table) {
            $table->id();
            $table->string('listing_url')->index();
            $table->decimal('price', 10, 2);
            $table->decimal('previous_price', 10, 2)->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();


            $table->index(['listing_url', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_histories');
    }
};
