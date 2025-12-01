<?php

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
        Schema::create('biometric_credentials', function (Blueprint $table) {
            $table->id();
            $table->uuid('credential_id')->unique();
            $table->string('identifier', 150)->index();
            $table->string('token_hash', 128);
            $table->string('device_label')->nullable();
            $table->string('user_agent')->nullable();
            $table->nullableMorphs('authenticatable');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            // Custom index name to avoid collisions in Postgres where index names are schema-global
            $table->index(['authenticatable_type', 'authenticatable_id'], 'biometric_credentials_auth_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('biometric_credentials');
    }
};
