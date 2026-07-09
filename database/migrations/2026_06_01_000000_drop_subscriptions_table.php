<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Removes the Stripe billing/subscription tables entirely — this app no longer
     * has a billing subsystem. Safe on both a fresh install (table never created,
     * since the migrations that created it were removed) and an existing database
     * that already ran those migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('subscriptions');
    }

    public function down(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }
};
