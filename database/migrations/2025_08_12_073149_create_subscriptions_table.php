<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('listing_url')->index();
            $table->string('email');
            $table->decimal('current_price', 10, 2)->nullable();
            $table->boolean('is_verified')->default(false);
            $table->string('verification_token')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();


            $table->index(['listing_url', 'is_verified']);
            $table->index(['email', 'is_verified']);
            $table->unique(['listing_url', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
