<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hubspot_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('hubspot_contact_id');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('hubspot_contact_id');
        });

        $this->backfillFromSyncLogs();
    }

    public function down(): void
    {
        Schema::dropIfExists('hubspot_contacts');
    }

    /**
     * Seed the mapping for installs that already have sync history, so that
     * existing contacts stay linked rather than being recreated on the next
     * sync.
     *
     * One row per user, taken from that user's most recent successful sync
     * that recorded a contact id; a trailing delete carries the link over as
     * already archived. These rules are a deliberate frozen copy of
     * SyncContactToHubspot's resolution as of this migration, not a call into
     * it, so that later changes there cannot alter what this migration did.
     *
     * Written as a single INSERT ... SELECT so the cost is one indexed pass
     * over the log regardless of how many rows it holds.
     */
    private function backfillFromSyncLogs(): void
    {
        if (!Schema::hasTable('hubspot_sync_logs')) {
            return;
        }

        $prefix = DB::getTablePrefix();
        $contacts = $prefix . 'hubspot_contacts';
        $logs = $prefix . 'hubspot_sync_logs';

        DB::statement("
            INSERT INTO {$contacts} (user_id, hubspot_contact_id, archived_at, created_at, updated_at)
            SELECT
                log.user_id,
                log.hubspot_contact_id,
                CASE WHEN log.action = 'delete' THEN COALESCE(log.created_at, CURRENT_TIMESTAMP) END,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            FROM {$logs} log
            INNER JOIN (
                SELECT user_id, MAX(id) AS latest_id
                FROM {$logs}
                WHERE hubspot_contact_id IS NOT NULL
                  AND status_code >= 200
                  AND status_code < 300
                GROUP BY user_id
            ) latest ON latest.latest_id = log.id
        ");
    }
};
