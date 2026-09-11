<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubspot_sync_logs', function (Blueprint $table) {
            $table->string('resolved_via')->nullable()->after('hubspot_contact_id');
        });
    }

    public function down(): void
    {
        Schema::table('hubspot_sync_logs', function (Blueprint $table) {
            $table->dropColumn('resolved_via');
        });
    }
};
