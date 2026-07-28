<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (Schema::hasColumn('servers', 'hetzner_server_status')) {
                $table->dropColumn('hetzner_server_status');
            }
            if (Schema::hasColumn('servers', 'hetzner_server_id')) {
                $table->dropColumn('hetzner_server_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'hetzner_server_id')) {
                $table->bigInteger('hetzner_server_id')->nullable()->after('id');
            }
            if (! Schema::hasColumn('servers', 'hetzner_server_status')) {
                $table->string('hetzner_server_status')->nullable()->after('hetzner_server_id');
            }
        });
    }
};
