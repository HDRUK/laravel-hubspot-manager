<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hubspot_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('action');
            $table->unsignedSmallInteger('status_code');
            $table->string('hubspot_contact_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('action');
            $table->index('hubspot_contact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hubspot_sync_logs');
    }
};
