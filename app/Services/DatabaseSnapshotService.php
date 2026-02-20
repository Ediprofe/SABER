<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseSnapshotService
{
    /**
     * @return list<string>
     */
    public function getAvailableTables(string $connection): array
    {
        $tables = array_values(array_map(
            static function (string $table): string {
                if (! str_contains($table, '.')) {
                    return $table;
                }

                $parts = explode('.', $table);

                return (string) end($parts);
            },
            Schema::connection($connection)->getTableListing()
        ));
        if ($tables !== []) {
            return $tables;
        }

        $driver = (string) config("database.connections.{$connection}.driver");

        if ($driver === 'sqlite') {
            $rows = DB::connection($connection)->select(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
            );

            return array_values(array_map(
                static fn (object $row): string => (string) $row->name,
                $rows
            ));
        }

        if ($driver === 'pgsql') {
            $rows = DB::connection($connection)->select(
                "SELECT tablename AS name FROM pg_catalog.pg_tables WHERE schemaname = 'public'"
            );

            return array_values(array_map(
                static fn (object $row): string => (string) $row->name,
                $rows
            ));
        }

        return [];
    }

    /**
     * @param  list<string>|null  $tables
     * @return list<string>
     */
    public function resolveTables(string $connection, ?array $tables = null): array
    {
        $available = $this->getAvailableTables($connection);

        if (! $tables || $tables === []) {
            return $available;
        }

        return array_values(array_filter($tables, fn (string $table): bool => in_array($table, $available, true)));
    }

    /**
     * @param  list<string>  $tables
     * @return array<string,array{count:int,columns:list<string>,order_column:string,fingerprint:?string}>
     */
    public function getSnapshot(
        string $connection,
        array $tables,
        bool $withFingerprint = false,
        int $chunkSize = 1000
    ): array {
        $snapshot = [];

        foreach ($tables as $table) {
            $columns = Schema::connection($connection)->getColumnListing($table);
            if ($columns === []) {
                continue;
            }

            $orderColumn = $this->guessOrderColumn($columns);
            $count = (int) DB::connection($connection)->table($table)->count();

            $fingerprint = null;
            if ($withFingerprint) {
                $fingerprint = $this->calculateFingerprint($connection, $table, $columns, $orderColumn, $chunkSize);
            }

            $snapshot[$table] = [
                'count' => $count,
                'columns' => array_values($columns),
                'order_column' => $orderColumn,
                'fingerprint' => $fingerprint,
            ];
        }

        return $snapshot;
    }

    /**
     * @param  list<string>  $columns
     */
    public function guessOrderColumn(array $columns): string
    {
        $priority = ['id', 'created_at', 'updated_at'];

        foreach ($priority as $column) {
            if (in_array($column, $columns, true)) {
                return $column;
            }
        }

        return $columns[0];
    }

    /**
     * @param  list<string>  $columns
     */
    public function calculateFingerprint(
        string $connection,
        string $table,
        array $columns,
        string $orderColumn,
        int $chunkSize = 1000
    ): string {
        $hash = hash_init('sha256');
        $page = 1;

        while (true) {
            $rows = DB::connection($connection)
                ->table($table)
                ->orderBy($orderColumn)
                ->forPage($page, $chunkSize)
                ->get($columns);

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $normalized = [];
                foreach ($columns as $column) {
                    $value = $row->{$column} ?? null;
                    $normalized[$column] = is_bool($value) ? (int) $value : $value;
                }

                hash_update(
                    $hash,
                    json_encode(
                        $normalized,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
                    ) ?: ''
                );
            }

            $page++;
        }

        return hash_final($hash);
    }
}
