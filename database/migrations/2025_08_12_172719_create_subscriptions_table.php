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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('listing_url', 500);
            $table->string('listing_title')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->string('verification_token', 64)->unique()->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['email']);
            $table->index(['listing_url']);
            $table->index(['is_verified']);
            $table->index(['verification_token']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
