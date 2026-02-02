<?php

namespace App\Console\Commands;

use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSession;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportZipgradeStats extends Command
{
    protected $signature = 'zipgrade:import-stats {exam_id} {session_number} {file_path}';

    protected $description = 'Importa estadísticas de preguntas desde Excel de Zipgrade (corregido para columnas duplicadas)';

    public function handle(): int
    {
        $examId = $this->argument('exam_id');
        $sessionNumber = $this->argument('session_number');
        $filePath = $this->argument('file_path');

        // Verificar examen
        $exam = Exam::find($examId);
        if (! $exam) {
            $this->error("Examen ID {$examId} no encontrado");

            return 1;
        }

        // Verificar sesión
        $session = ExamSession::where('exam_id', $examId)
            ->where('session_number', $sessionNumber)
            ->first();

        if (! $session) {
            $this->error("Sesión {$sessionNumber} no encontrada para el examen");

            return 1;
        }

        // Verificar archivo
        if (! file_exists($filePath)) {
            $this->error("Archivo no encontrado: {$filePath}");

            return 1;
        }

        $this->info("Importando estadísticas para Sesión {$sessionNumber}...");

        try {
            // Cargar Excel con PhpSpreadsheet directamente
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            // La primera fila son los encabezados
            $headers = array_shift($rows);

            $this->info('Columnas detectadas: '.implode(', ', $headers));

            // Encontrar índices de columnas importantes
            $colIndex = [];
            foreach ($headers as $index => $header) {
                $header = trim($header);
                if ($header === 'Question_Number') {
                    $colIndex['question_number'] = $index;
                }
                if ($header === 'Primary_Answer') {
                    $colIndex['primary_answer'] = $index;
                }
                if ($header === 'Response 1') {
                    $colIndex['response_1'] = $index;
                }
                if ($header === 'Response 1 %') {
                    $colIndex['response_1_pct'] = $index;
                }
                if ($header === 'Response 2') {
                    $colIndex['response_2'] = $index;
                }
                if ($header === 'Response 2 %') {
                    $colIndex['response_2_pct'] = $index;
                }
                if ($header === 'Response 3') {
                    $colIndex['response_3'] = $index;
                }
                if ($header === 'Response 3 %') {
                    $colIndex['response_3_pct'] = $index;
                }
                if ($header === 'Response 4') {
                    $colIndex['response_4'] = $index;
                }
                if ($header === 'Response 4 %') {
                    $colIndex['response_4_pct'] = $index;
                }
            }

            $this->info('Índices de columnas encontrados: '.json_encode($colIndex));

            $processed = 0;
            $skipped = 0;

            foreach ($rows as $rowIndex => $row) {
                $questionNum = isset($colIndex['question_number']) ? (int) $row[$colIndex['question_number']] : 0;

                if ($questionNum <= 0) {
                    $skipped++;

                    continue;
                }

                // Buscar la pregunta
                $question = ExamQuestion::where('exam_session_id', $session->id)
                    ->where('question_number', $questionNum)
                    ->first();

                if (! $question) {
                    $this->warn("Pregunta {$questionNum} no encontrada en sesión {$sessionNumber}");
                    $skipped++;

                    continue;
                }

                // Extraer valores por índice de columna
                $correctAnswer = isset($colIndex['primary_answer']) ? trim($row[$colIndex['primary_answer']]) : null;

                $response1 = isset($colIndex['response_1']) ? trim($row[$colIndex['response_1']]) : null;
                $response1Pct = isset($colIndex['response_1_pct']) ? $this->parsePercentage($row[$colIndex['response_1_pct']]) : null;

                $response2 = isset($colIndex['response_2']) ? trim($row[$colIndex['response_2']]) : null;
                $response2Pct = isset($colIndex['response_2_pct']) ? $this->parsePercentage($row[$colIndex['response_2_pct']]) : null;

                $response3 = isset($colIndex['response_3']) ? trim($row[$colIndex['response_3']]) : null;
                $response3Pct = isset($colIndex['response_3_pct']) ? $this->parsePercentage($row[$colIndex['response_3_pct']]) : null;

                $response4 = isset($colIndex['response_4']) ? trim($row[$colIndex['response_4']]) : null;
                $response4Pct = isset($colIndex['response_4_pct']) ? $this->parsePercentage($row[$colIndex['response_4_pct']]) : null;

                // Actualizar pregunta
                $question->update([
                    'correct_answer' => $correctAnswer,
                    'response_1' => $response1,
                    'response_1_pct' => $response1Pct,
                    'response_2' => $response2,
                    'response_2_pct' => $response2Pct,
                    'response_3' => $response3,
                    'response_3_pct' => $response3Pct,
                    'response_4' => $response4,
                    'response_4_pct' => $response4Pct,
                ]);

                $processed++;

                // Mostrar progreso cada 20 filas
                if ($processed % 20 === 0) {
                    $this->info("Progreso: {$processed} preguntas procesadas...");
                }
            }

            $this->info('✅ Importación completada!');
            $this->info("   - Procesadas: {$processed}");
            $this->info("   - Omitidas: {$skipped}");

            // Mostrar ejemplo de los datos importados
            $sample = ExamQuestion::where('exam_session_id', $session->id)
                ->where('question_number', 1)
                ->first();

            if ($sample) {
                $this->info("\n📋 Ejemplo de datos importados (Pregunta 1):");
                $this->info("   Correcta: {$sample->correct_answer}");
                $this->info("   1° Elegida: {$sample->response_1} ({$sample->response_1_pct}%)");
                $this->info("   2° Elegida: {$sample->response_2} ({$sample->response_2_pct}%)");
                $this->info("   3° Elegida: {$sample->response_3} ({$sample->response_3_pct}%)");
                $this->info("   4° Elegida: {$sample->response_4} ({$sample->response_4_pct}%)");
            }

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Error en importación: '.$e->getMessage());
            $this->error($e->getTraceAsString());

            return 1;
        }
    }

    private function parsePercentage($value): ?float
    {
        if (empty($value)) {
            return null;
        }

        // Quitar % si existe
        $value = str_replace('%', '', $value);
        // Reemplazar coma por punto
        $value = str_replace(',', '.', $value);

        $parsed = floatval($value);

        return $parsed > 0 ? $parsed : null;
    }
}
