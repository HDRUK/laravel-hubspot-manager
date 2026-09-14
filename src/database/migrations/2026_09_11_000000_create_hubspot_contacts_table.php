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
    }

    public function down(): void
    {
        Schema::dropIfExists('hubspot_contacts');
    }
};
