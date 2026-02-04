<?php

namespace App\Console\Commands;

use App\Imports\ZipgradeTagsImport;
use App\Models\Exam;
use App\Models\ExamSession;
use App\Models\StudentAnswer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ImportZipgradeCommand extends Command
{
    protected $signature = 'zipgrade:import {exam_id} {session_number} {file_path}';

    protected $description = 'Importa un CSV de Zipgrade directamente desde consola';

    public function handle(): int
    {
        set_time_limit(0); 
        $examId = $this->argument('exam_id');
        $sessionNumber = (int) $this->argument('session_number');
        $filePath = $this->argument('file_path');

        // Validar archivo
        if (!file_exists($filePath)) {
            $this->error("Archivo no encontrado: {$filePath}");
            return 1;
        }

        // Validar examen
        $exam = Exam::find($examId);
        if (!$exam) {
            $this->error("Examen no encontrado con ID: {$examId}");
            return 1;
        }

        $this->info("Examen: {$exam->name}");
        $this->info("Sesión: {$sessionNumber}");
        $this->info("Archivo: {$filePath}");
        $this->newLine();

        // Obtener o crear sesión
        $session = $exam->sessions()->firstOrCreate(
            ['session_number' => $sessionNumber],
            ['name' => "Sesión {$sessionNumber}"]
        );

        // Preguntar si limpiar datos existentes
        if ($session->questions()->count() > 0) {
            if ($this->confirm('¿Limpiar datos existentes de esta sesión?', true)) {
                $this->info('Limpiando datos existentes...');

                DB::table('student_answers')
                    ->whereIn('exam_question_id', function ($query) use ($session) {
                        $query->select('id')
                            ->from('exam_questions')
                            ->where('exam_session_id', $session->id);
                    })
                    ->delete();

                DB::table('question_tags')
                    ->whereIn('exam_question_id', function ($query) use ($session) {
                        $query->select('id')
                            ->from('exam_questions')
                            ->where('exam_session_id', $session->id);
                    })
                    ->delete();

                $session->questions()->delete();
                $session->update(['total_questions' => 0]);

                $this->info('Datos limpiados.');
            }
        }

        // Analizar tags nuevos
        $this->info('Analizando archivo para detectar tags nuevos...');
        $newTags = ZipgradeTagsImport::analyzeFile($filePath);

        if (!empty($newTags)) {
            $this->warn('Se detectaron tags nuevos que necesitan clasificación:');
            foreach ($newTags as $tag) {
                $this->line("  - {$tag['csv_name']}");
            }
            $this->error('Por favor, configure estos tags en la Jerarquía de Tags antes de importar.');
            return 1;
        }

        $this->info('No hay tags nuevos. Iniciando importación...');
        $this->newLine();

        // Importar con barra de progreso
        $this->info('Importando datos...');

        try {
            $import = new ZipgradeTagsImport($session->id, []);
            Excel::import($import, $filePath);

            // Actualizar contador de preguntas
            $questionCount = $session->questions()->count();
            $session->update(['total_questions' => $questionCount]);

            // Contar estudiantes
            $studentCount = StudentAnswer::whereIn('exam_question_id',
                $session->questions()->pluck('id')
            )->distinct('enrollment_id')->count('enrollment_id');

            // Contar respuestas
            $answerCount = StudentAnswer::whereIn('exam_question_id',
                $session->questions()->pluck('id')
            )->count();

            $this->newLine();
            $this->info('=== IMPORTACIÓN COMPLETADA ===');
            $this->table(
                ['Métrica', 'Valor'],
                [
                    ['Preguntas importadas', $questionCount],
                    ['Estudiantes con respuestas', $studentCount],
                    ['Total de respuestas', $answerCount],
                ]
            );

            if ($studentCount === 0) {
                $this->warn('ADVERTENCIA: No se importaron estudiantes. Verifique que los estudiantes tengan matrícula activa para el año académico ' . $exam->academicYear->year);
            }

            return 0;

        } catch (\Exception $e) {
            $this->error('Error durante la importación: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }
}
