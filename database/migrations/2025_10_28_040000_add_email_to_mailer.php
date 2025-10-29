<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mailer')) {
            return;
        }

        Schema::table('mailer', function (Blueprint $table) {
            if (!Schema::hasColumn('mailer', 'email')) {
                $table->string('email', 200)->nullable()->after('mail');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('mailer')) {
            return;
        }

        Schema::table('mailer', function (Blueprint $table) {
            if (Schema::hasColumn('mailer', 'email')) {
                $table->dropColumn('email');
            }
        });
    }
};

