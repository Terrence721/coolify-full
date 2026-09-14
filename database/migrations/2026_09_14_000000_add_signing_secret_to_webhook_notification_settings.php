<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AddSigningSecretToWebhookNotificationSettings extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_notification_settings', function ($table) {
            $table->text('signing_secret')->nullable()->after('webhook_url');
        });

        // Every existing row needs a real secret, not just rows created from here on -
        // WebhookNotificationSettings::booted()'s creating hook only covers new teams.
        // Matches the established pattern from 2026_04_19_000000_backfill_and_encrypt_webhook_secrets.php:
        // raw DB queries (not the Eloquent model) so this doesn't depend on app code that may
        // change shape later, chunked so a large teams table doesn't load everything at once.
        DB::table('webhook_notification_settings')
            ->whereNull('signing_secret')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('webhook_notification_settings')
                        ->where('id', $row->id)
                        ->update(['signing_secret' => Crypt::encryptString(Str::random(40))]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('webhook_notification_settings', function ($table) {
            $table->dropColumn('signing_secret');
        });
    }
}
