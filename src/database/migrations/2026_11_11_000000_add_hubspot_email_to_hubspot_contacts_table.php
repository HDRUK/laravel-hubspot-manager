<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The email a link was made for, normalised, so that a sync can tell when a
 * model's email has changed without asking HubSpot.
 *
 * Nullable: links made before this column hold no email, and nothing local
 * can say which one they were made for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubspot_contacts', function (Blueprint $table) {
            $table->string('hubspot_email')->nullable()->after('hubspot_contact_id');
        });
    }

    public function down(): void
    {
        Schema::table('hubspot_contacts', function (Blueprint $table) {
            $table->dropColumn('hubspot_email');
        });
    }
};
