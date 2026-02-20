<?php

namespace App\Console\Commands;

use App\Services\DatabaseSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class AuditZipgradeDatabase extends Command
{
    protected $signature = 'zipgrade:audit-database
                            {--connection=sqlite : Conexión a auditar}
                            {--tables= : Lista de tablas separadas por coma}
                            {--fingerprint : Calcular huella SHA-256 por tabla}
                            {--chunk=1000 : Tamaño de bloque para huellas}
                            {--output= : Guardar reporte JSON en esta ruta}';

    protected $description = 'Audita integridad de datos (conteos y huellas opcionales) para preparar migración SQLite -> PostgreSQL';

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
        $connection = (string) $this->option('connection');
        $withFingerprint = (bool) $this->option('fingerprint');
        $chunk = max(100, (int) $this->option('chunk'));

        if (! array_key_exists($connection, config('database.connections', []))) {
            $this->error("La conexión [{$connection}] no está definida en config/database.php.");

            return self::FAILURE;
        }

        try {
            DB::connection($connection)->getPdo();
        } catch (\Throwable $e) {
            $this->error("No se pudo conectar a [{$connection}]: {$e->getMessage()}");

            return self::FAILURE;
        }

        $tables = $this->parseTablesOption();
        $tables = $tables === [] ? self::DEFAULT_TABLES : $tables;
        $tables = $snapshotService->resolveTables($connection, $tables);

        if ($tables === []) {
            $this->error('No se encontraron tablas para auditar con los parámetros dados.');

            return self::FAILURE;
        }

        $this->info("Auditando conexión [{$connection}]...");
        $this->line('Tablas: '.implode(', ', $tables));

        $snapshot = $snapshotService->getSnapshot($connection, $tables, $withFingerprint, $chunk);
        $checks = $this->buildDomainChecks($connection);

        $rows = [];
        foreach ($snapshot as $table => $meta) {
            $rows[] = [
                'tabla' => $table,
                'filas' => $meta['count'],
                'columnas' => count($meta['columns']),
                'orden' => $meta['order_column'],
                'huella' => $withFingerprint ? $meta['fingerprint'] : 'n/a',
            ];
        }

        $this->table(['Tabla', 'Filas', 'Columnas', 'Orden', 'Huella'], $rows);
        $this->newLine();
        $this->table(['Chequeo', 'Valor'], array_map(
            fn (string $key, mixed $value): array => [$key, is_scalar($value) ? (string) $value : json_encode($value)],
            array_keys($checks),
            array_values($checks)
        ));

        $outputPath = $this->option('output');
        if (is_string($outputPath) && $outputPath !== '') {
            $report = [
                'timestamp' => now()->toIso8601String(),
                'connection' => $connection,
                'with_fingerprint' => $withFingerprint,
                'tables' => $snapshot,
                'checks' => $checks,
            ];

            File::ensureDirectoryExists(dirname($outputPath));
            File::put($outputPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("Reporte guardado en {$outputPath}");
        }

        $this->info('Auditoría completada.');

        return self::SUCCESS;
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
     * @return array<string,mixed>
     */
    private function buildDomainChecks(string $connection): array
    {
        $checks = [];

        if (! $this->tableExists($connection, 'exam_sessions') || ! $this->tableExists($connection, 'exams')) {
            return $checks;
        }

        $checks['exams_total'] = DB::connection($connection)->table('exams')->count();

        $checks['exams_with_2_sessions'] = DB::connection($connection)
            ->table('exam_sessions')
            ->select('exam_id')
            ->groupBy('exam_id')
            ->havingRaw('COUNT(*) >= 2')
            ->count();

        if ($this->tableExists($connection, 'exam_questions')) {
            $checks['questions_total'] = DB::connection($connection)->table('exam_questions')->count();
            $checks['questions_with_stats'] = DB::connection($connection)
                ->table('exam_questions')
                ->whereNotNull('correct_answer')
                ->count();
        }

        if ($this->tableExists($connection, 'student_answers')) {
            $checks['answers_total'] = DB::connection($connection)->table('student_answers')->count();
            $checks['distinct_enrollments_with_answers'] = DB::connection($connection)
                ->table('student_answers')
                ->distinct('enrollment_id')
                ->count('enrollment_id');
        }

        if ($this->tableExists($connection, 'zipgrade_imports')) {
            $checks['imports_completed'] = DB::connection($connection)
                ->table('zipgrade_imports')
                ->where('status', 'completed')
                ->count();
            $checks['imports_error'] = DB::connection($connection)
                ->table('zipgrade_imports')
                ->where('status', 'error')
                ->count();
        }

        return $checks;
    }

    private function tableExists(string $connection, string $table): bool
    {
        return in_array($table, app(DatabaseSnapshotService::class)->getAvailableTables($connection), true);
    }
}

