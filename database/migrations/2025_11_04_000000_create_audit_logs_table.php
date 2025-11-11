<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('user_id')->nullable();
                $table->string('action', 20);
                $table->string('table_name')->nullable();
                $table->unsignedBigInteger('record_id')->nullable();
                $table->string('connection', 60)->nullable();
                $table->text('statement');
                $table->json('bindings')->nullable();
                $table->timestamps();

                $table->index(['table_name', 'action']);
                $table->index('created_at');
                $table->foreign('user_id')->references('id')->on('usuarios')->nullOnDelete();
            });

            return;
        }

        $self = $this;
        Schema::table('audit_logs', function (Blueprint $table) use ($self) {
            if (Schema::hasColumn('audit_logs', 'user_id')) {
                $table->unsignedInteger('user_id')->nullable()->change();
            } else {
                $table->unsignedInteger('user_id')->nullable()->first();
            }

            if (!Schema::hasColumn('audit_logs', 'action')) {
                $table->string('action', 20)->after('user_id');
            }

            if (!Schema::hasColumn('audit_logs', 'table_name')) {
                $table->string('table_name')->nullable()->after('action');
            }

            if (!Schema::hasColumn('audit_logs', 'record_id')) {
                $table->unsignedBigInteger('record_id')->nullable()->after('table_name');
            }

            if (!Schema::hasColumn('audit_logs', 'connection')) {
                $table->string('connection', 60)->nullable()->after('record_id');
            }

            if (!Schema::hasColumn('audit_logs', 'statement')) {
                $table->text('statement')->after('connection');
            }

            if (!Schema::hasColumn('audit_logs', 'bindings')) {
                $table->json('bindings')->nullable()->after('statement');
            }

            if (!Schema::hasColumn('audit_logs', 'created_at')) {
                $table->timestamps();
            }

            if (! $self->foreignKeyExists('audit_logs', 'audit_logs_user_id_foreign')) {
                $table->foreign('user_id')->references('id')->on('usuarios')->nullOnDelete();
            }
        });
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
