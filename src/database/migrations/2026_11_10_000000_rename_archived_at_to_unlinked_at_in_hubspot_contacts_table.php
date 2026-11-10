<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The column records when a model's link to its HubSpot contact ended, not
 * when the contact was archived: a model that clears its properties on
 * delete leaves the contact live in HubSpot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubspot_contacts', function (Blueprint $table) {
            $table->renameColumn('archived_at', 'unlinked_at');
        });
    }

    public function down(): void
    {
        Schema::table('hubspot_contacts', function (Blueprint $table) {
            $table->renameColumn('unlinked_at', 'archived_at');
        });
    }
};
