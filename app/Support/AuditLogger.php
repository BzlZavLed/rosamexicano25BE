<?php

namespace App\Support;

use App\Models\Usuario;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditLogger
{
    protected static bool $allowLogging = true;
    protected static bool $checkedTable = false;
    protected static bool $tableExists = false;

    public static function handle(QueryExecuted $query): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        if (! static::$allowLogging) {
            return;
        }

        if (! static::tableIsReady()) {
            return;
        }

        if (! Auth::hasUser()) {
            return;
        }

        $authUser = Auth::user();
        if (! $authUser instanceof Usuario) {
            return;
        }

        $action = static::detectAction($query->sql);
        if (! $action) {
            return;
        }

        $table = static::extractTable($query->sql, $action);
        if (! $table || strcasecmp($table, 'audit_logs') === 0) {
            return;
        }

        static::record([
            'user_id'    => $authUser->id,
            'action'     => $action,
            'table_name' => $table,
            'record_id'  => static::guessPrimaryKey($query->bindings),
            'connection' => $query->connectionName,
            'statement'  => trim($query->sql),
            'bindings'   => json_encode($query->bindings),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected static function detectAction(string $sql): ?string
    {
        $sql = ltrim($sql);
        if (strncasecmp($sql, 'insert', 6) === 0) {
            return 'insert';
        }

        if (strncasecmp($sql, 'update', 6) === 0) {
            return 'update';
        }

        if (strncasecmp($sql, 'delete', 6) === 0) {
            return 'delete';
        }

        return null;
    }

    protected static function extractTable(string $sql, string $action): ?string
    {
        $pattern = match ($action) {
            'insert' => '/^insert\s+into\s+["`]?([^\s"`(]+)/i',
            'update' => '/^update\s+["`]?([^\s"`]+)/i',
            'delete' => '/from\s+["`]?([^\s"`]+)/i',
            default => null,
        };

        if (! $pattern || ! preg_match($pattern, $sql, $matches)) {
            return null;
        }

        return trim($matches[1], '"`');
    }

    protected static function guessPrimaryKey(array $bindings): ?int
    {
        foreach ($bindings as $binding) {
            if (is_int($binding)) {
                return $binding;
            }
        }

        return null;
    }

    protected static function record(array $payload): void
    {
        static::$allowLogging = false;

        try {
            DB::table('audit_logs')->insert($payload);
        } finally {
            static::$allowLogging = true;
        }
    }

    protected static function tableIsReady(): bool
    {
        if (! static::$checkedTable) {
            static::$checkedTable = true;

            try {
                static::$tableExists = Schema::hasTable('audit_logs');
            } catch (\Throwable $e) {
                static::$tableExists = false;
            }
        }

        return static::$tableExists;
    }
}
