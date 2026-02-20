<?php

namespace App\Console\Commands;

use App\Services\DatabaseSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class MigrateZipgradeSqliteToPgsql extends Command
{
    protected $signature = 'zipgrade:migrate-sqlite-to-pgsql
                            {--source=sqlite : Conexión origen (por defecto sqlite)}
                            {--target=pgsql : Conexión destino (por defecto pgsql)}
                            {--tables= : Lista de tablas separadas por coma}
                            {--chunk=1000 : Tamaño de bloque para copiar filas}
                            {--truncate-target : Vaciar tablas destino antes de copiar}
                            {--fingerprint : Validar huella SHA-256 después de migrar}
                            {--dry-run : Solo mostrar plan y diferencias de conteo}
                            {--force : Ejecutar sin confirmación interactiva}
                            {--output= : Guardar reporte JSON en esta ruta}
                            {--target-host= : Host de PostgreSQL destino}
                            {--target-port= : Puerto de PostgreSQL destino}
                            {--target-database= : Base de datos destino}
                            {--target-username= : Usuario destino}
                            {--target-password= : Password destino}
                            {--target-schema=public : Schema destino}';

    protected $description = 'Migra datos de SQLite a PostgreSQL con validación de integridad (conteos y huella opcional)';

    /**
     * @var list<string>
     */
    private const DEFAULT_TABLES = [
        'academic_years',
        'students',
        'enrollments',
        'exams',
        'exam_sessions',
        'zipgrade_imports',
        'tag_hierarchy',
        'tag_normalizations',
        'exam_questions',
        'question_tags',
        'student_answers',
        'exam_results',
        'exam_area_configs',
        'exam_area_items',
        'exam_detail_results',
        'report_generations',
        'users',
    ];

    public function handle(DatabaseSnapshotService $snapshotService): int
    {
        $source = (string) $this->option('source');
        $target = (string) $this->option('target');
        $dryRun = (bool) $this->option('dry-run');
        $withFingerprint = (bool) $this->option('fingerprint');
        $truncateTarget = (bool) $this->option('truncate-target');
        $chunk = max(100, (int) $this->option('chunk'));

        if (! $dryRun && $source === $target) {
            $this->error('La conexión origen y destino no pueden ser la misma para una migración real.');

            return self::FAILURE;
        }

        if (! $this->configureTargetOverrides($target)) {
            return self::FAILURE;
        }

        if (! $this->canConnect($source) || ! $this->canConnect($target)) {
            return self::FAILURE;
        }

        $sourceDriver = (string) config("database.connections.{$source}.driver");
        $targetDriver = (string) config("database.connections.{$target}.driver");

        if ($sourceDriver !== 'sqlite') {
            $this->warn("La conexión origen [{$source}] no usa sqlite (driver actual: {$sourceDriver}).");
        }
        if ($targetDriver !== 'pgsql') {
            $this->warn("La conexión destino [{$target}] no usa pgsql (driver actual: {$targetDriver}).");
        }

        $requestedTables = $this->parseTablesOption();
        $requestedTables = $requestedTables === [] ? self::DEFAULT_TABLES : $requestedTables;

        $sourceTables = $snapshotService->resolveTables($source, $requestedTables);
        $targetAvailable = $snapshotService->getAvailableTables($target);

        $tables = array_values(array_filter(
            $sourceTables,
            fn (string $table): bool => in_array($table, $targetAvailable, true)
        ));

        $missingInTarget = array_values(array_diff($sourceTables, $tables));
        if ($missingInTarget !== []) {
            $this->warn('Tablas omitidas (no existen en destino): '.implode(', ', $missingInTarget));
        }

        if ($tables === []) {
            $this->error('No hay tablas comunes para migrar entre origen y destino.');

            return self::FAILURE;
        }

        $this->info("Preparando migración {$source} -> {$target}");
        $this->line('Tablas: '.implode(', ', $tables));

        $sourceSnapshot = $snapshotService->getSnapshot($source, $tables, $withFingerprint, $chunk);
        $targetBefore = $snapshotService->getSnapshot($target, $tables, false, $chunk);

        $this->renderPreflight($sourceSnapshot, $targetBefore);

        if ($dryRun) {
            $this->info('Dry-run completado. No se realizaron cambios.');
            $this->writeReport($source, $target, $tables, $sourceSnapshot, $targetBefore, null, null);

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('¿Continuar con la migración de datos?', false)) {
            $this->warn('Migración cancelada por usuario.');

            return self::INVALID;
        }

        DB::connection($target)->beginTransaction();

        try {
            if ($truncateTarget) {
                $this->truncateTables($target, $tables);
            }

            foreach ($tables as $table) {
                $this->migrateTable($source, $target, $table, $chunk);
            }

            $this->resetSequences($target, $tables);
            DB::connection($target)->commit();
        } catch (\Throwable $e) {
            DB::connection($target)->rollBack();
            $this->error("Migración fallida: {$e->getMessage()}");

            return self::FAILURE;
        }

        $targetAfter = $snapshotService->getSnapshot($target, $tables, $withFingerprint, $chunk);
        $mismatches = $this->compareSnapshots($sourceSnapshot, $targetAfter, $withFingerprint);

        if ($mismatches === []) {
            $this->info('Validación post-migración OK: conteos (y huellas si aplica) coinciden.');
        } else {
            $this->warn('Se detectaron diferencias post-migración:');
            $this->table(['Tabla', 'Tipo', 'Origen', 'Destino'], $mismatches);
        }

        $this->writeReport($source, $target, $tables, $sourceSnapshot, $targetBefore, $targetAfter, $mismatches);

        return $mismatches === [] ? self::SUCCESS : self::FAILURE;
    }

    private function configureTargetOverrides(string $target): bool
    {
        if (! array_key_exists($target, config('database.connections', []))) {
            $this->error("La conexión destino [{$target}] no existe en config/database.php.");

            return false;
        }

        $overrides = [
            'host' => $this->option('target-host'),
            'port' => $this->option('target-port'),
            'database' => $this->option('target-database'),
            'username' => $this->option('target-username'),
            'password' => $this->option('target-password'),
            'search_path' => $this->option('target-schema'),
        ];

        foreach ($overrides as $key => $value) {
            if (is_string($value) && $value !== '') {
                Config::set("database.connections.{$target}.{$key}", $value);
            }
        }

        DB::purge($target);

        return true;
    }

    private function canConnect(string $connection): bool
    {
        if (! array_key_exists($connection, config('database.connections', []))) {
            $this->error("La conexión [{$connection}] no está definida.");

            return false;
        }

        try {
            DB::connection($connection)->getPdo();

            return true;
        } catch (\Throwable $e) {
            $this->error("No se pudo conectar a [{$connection}]: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * @param  list<string>  $tables
     */
    private function truncateTables(string $target, array $tables): void
    {
        $driver = (string) config("database.connections.{$target}.driver");
        $this->info('Limpiando tablas destino...');

        foreach (array_reverse($tables) as $table) {
            if (! preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                throw new \RuntimeException("Nombre de tabla inválido para truncar: {$table}");
            }

            if ($driver === 'pgsql') {
                DB::connection($target)->statement(sprintf(
                    'TRUNCATE TABLE "%s" RESTART IDENTITY CASCADE',
                    $table
                ));
            } else {
                DB::connection($target)->table($table)->truncate();
            }
        }
    }

    private function migrateTable(string $source, string $target, string $table, int $chunk): void
    {
        $sourceColumns = Schema::connection($source)->getColumnListing($table);
        $targetColumns = Schema::connection($target)->getColumnListing($table);

        $columns = array_values(array_intersect($sourceColumns, $targetColumns));
        if ($columns === []) {
            $this->warn("{$table}: sin columnas compatibles, se omite.");

            return;
        }

        $orderColumn = app(DatabaseSnapshotService::class)->guessOrderColumn($columns);
        $sourceTotal = (int) DB::connection($source)->table($table)->count();
        $this->line("Migrando {$table} ({$sourceTotal} filas)...");

        $page = 1;
        $copied = 0;
        $uniqueBy = $this->resolveUniqueBy($columns);
        $updateColumns = array_values(array_diff($columns, $uniqueBy));

        while (true) {
            $rows = DB::connection($source)
                ->table($table)
                ->orderBy($orderColumn)
                ->forPage($page, $chunk)
                ->get($columns);

            if ($rows->isEmpty()) {
                break;
            }

            $payload = [];
            foreach ($rows as $row) {
                $record = [];
                foreach ($columns as $column) {
                    $value = $row->{$column} ?? null;
                    $record[$column] = is_bool($value) ? (int) $value : $value;
                }
                $payload[] = $record;
            }

            if ($uniqueBy !== []) {
                DB::connection($target)->table($table)->upsert($payload, $uniqueBy, $updateColumns);
            } else {
                DB::connection($target)->table($table)->insert($payload);
            }

            $copied += count($payload);
            $page++;
        }

        $this->line("{$table}: {$copied} filas copiadas.");
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function resolveUniqueBy(array $columns): array
    {
        if (in_array('id', $columns, true)) {
            return ['id'];
        }

        if (in_array('email', $columns, true)) {
            return ['email'];
        }

        return [];
    }

    /**
     * @param  list<string>  $tables
     */
    private function resetSequences(string $target, array $tables): void
    {
        if ((string) config("database.connections.{$target}.driver") !== 'pgsql') {
            return;
        }

        foreach ($tables as $table) {
            $columns = Schema::connection($target)->getColumnListing($table);
            if (! in_array('id', $columns, true) || ! preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                continue;
            }

            DB::connection($target)->statement(sprintf(
                'SELECT setval(pg_get_serial_sequence(\'"%s"\', \'id\'), COALESCE(MAX(id), 0) + 1, false) FROM "%s"',
                $table,
                $table
            ));
        }
    }

    /**
     * @param  array<string,array{count:int,columns:list<string>,order_column:string,fingerprint:?string}>  $source
     * @param  array<string,array{count:int,columns:list<string>,order_column:string,fingerprint:?string}>  $target
     * @return list<array{0:string,1:string,2:string,3:string}>
     */
    private function compareSnapshots(array $source, array $target, bool $withFingerprint): array
    {
        $mismatches = [];

        foreach ($source as $table => $sourceMeta) {
            $targetMeta = $target[$table] ?? null;
            if (! $targetMeta) {
                $mismatches[] = [$table, 'missing_table', (string) $sourceMeta['count'], 'n/a'];
                continue;
            }

            if ($sourceMeta['count'] !== $targetMeta['count']) {
                $mismatches[] = [$table, 'count', (string) $sourceMeta['count'], (string) $targetMeta['count']];
            }

            if ($withFingerprint && $sourceMeta['fingerprint'] !== $targetMeta['fingerprint']) {
                $mismatches[] = [
                    $table,
                    'fingerprint',
                    (string) ($sourceMeta['fingerprint'] ?? ''),
                    (string) ($targetMeta['fingerprint'] ?? ''),
                ];
            }
        }

        return $mismatches;
    }

    /**
     * @param  array<string,array{count:int,columns:list<string>,order_column:string,fingerprint:?string}>  $source
     * @param  array<string,array{count:int,columns:list<string>,order_column:string,fingerprint:?string}>  $targetBefore
     */
    private function renderPreflight(array $source, array $targetBefore): void
    {
        $rows = [];

        foreach ($source as $table => $meta) {
            $rows[] = [
                $table,
                (string) $meta['count'],
                (string) ($targetBefore[$table]['count'] ?? 0),
            ];
        }

        $this->table(['Tabla', 'Filas origen', 'Filas destino (antes)'], $rows);
    }

    /**
     * @return list<string>
     */
    private function parseTablesOption(): array
    {
        $raw = (string) ($this->option('tables') ?? '');
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * @param  list<string>  $tables
     * @param  array<string,array{count:int,columns:list<string>,order_column:string,fingerprint:?string}>  $source
     * @param  array<string,array{count:int,columns:list<string>,order_column:string,fingerprint:?string}>  $targetBefore
     * @param  array<string,array{count:int,columns:list<string>,order_column:string,fingerprint:?string}>|null  $targetAfter
     * @param  list<array{0:string,1:string,2:string,3:string}>|null  $mismatches
     */
    private function writeReport(
        string $sourceConnection,
        string $targetConnection,
        array $tables,
        array $source,
        array $targetBefore,
        ?array $targetAfter,
        ?array $mismatches
    ): void {
        $outputPath = $this->option('output');
        if (! is_string($outputPath) || $outputPath === '') {
            return;
        }

        $report = [
            'timestamp' => now()->toIso8601String(),
            'source_connection' => $sourceConnection,
            'target_connection' => $targetConnection,
            'tables' => $tables,
            'source_snapshot' => $source,
            'target_before' => $targetBefore,
            'target_after' => $targetAfter,
            'mismatches' => $mismatches,
        ];

        File::ensureDirectoryExists(dirname($outputPath));
        File::put($outputPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("Reporte guardado en {$outputPath}");
    }
}

