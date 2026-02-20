<?php

namespace App\Imports;

use App\Models\ExamQuestion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;

class ZipgradeQuestionStatsImport implements ToCollection, WithChunkReading, WithHeadingRow
{
    private int $examSessionId;

    private int $rowCount = 0;

    private int $processedCount = 0;

    private int $skippedCount = 0;

    public function __construct(int $examSessionId)
    {
        $this->examSessionId = $examSessionId;

        // Registrar un formateador de headers personalizado para Zipgrade
        // Este formateador distingue entre "Response 1" (letra) y "Response 1 %" (porcentaje)
        HeadingRowFormatter::extend('zipgrade', function ($value) {
            // Primero normalizar: trim y lowercase
            $value = strtolower(trim($value));

            // Manejar columnas con % - estas son porcentajes
            if (str_contains($value, '%')) {
                // "Response 1 %" -> "response_1_pct"
                // "% Correct" -> "pct_correct"
                $value = str_replace('%', 'pct', $value);
                $value = preg_replace('/\s+/', '_', $value);
                $value = preg_replace('/_+/', '_', $value);
                $value = trim($value, '_');
                return $value;
            }

            // Manejar "# Correct" -> "num_correct"
            if (str_contains($value, '#')) {
                $value = str_replace('#', 'num', $value);
            }

            // Convertir espacios a underscores
            $value = preg_replace('/\s+/', '_', $value);
            // Eliminar caracteres especiales excepto underscore
            $value = preg_replace('/[^a-z0-9_]/', '', $value);
            // Eliminar underscores múltiples
            $value = preg_replace('/_+/', '_', $value);
            return trim($value, '_');
        });

    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function collection(Collection $rows)
    {
        $this->rowCount = $rows->count();

        if ($this->rowCount === 0) {
            Log::warning('Zipgrade stats import - No rows to process', [
                'session_id' => $this->examSessionId,
            ]);

            return;
        }

        DB::beginTransaction();

        try {
            // Debug header columns
            $firstRow = $rows->first();
            if ($firstRow) {
                Log::info('Zipgrade stats import - Available columns', [
                    'columns' => array_keys($firstRow->toArray()),
                ]);
            }

            foreach ($rows as $index => $row) {
                // Debug first row
                if ($index === 0) {
                    Log::info('Zipgrade stats import - Processing first row', [
                        'row_data' => $row->toArray(),
                    ]);
                }

                // Las columnas del Excel de Zipgrade (con el formateador personalizado):
                // response_1 = Letra de la respuesta más elegida
                // response_1_pct = Porcentaje de esa respuesta
                // pct_correct = Porcentaje de acierto

                $questionNum = (int) ($row['question_number'] ?? 0);
                $correctAnswer = strtoupper(trim($row['primary_answer'] ?? ''));

                // Skip rows with missing question number
                if ($questionNum <= 0) {
                    $this->skippedCount++;
                    Log::debug('Zipgrade stats import - Skipping row', [
                        'index' => $index,
                        'question_num' => $questionNum,
                    ]);

                    continue;
                }

                // Leer las respuestas con el nuevo formato de columnas
                // response_1 = letra, response_1_pct = porcentaje
                $response1 = strtoupper(trim($row['response_1'] ?? ''));
                $response1Pct = $this->parsePercentage($row['response_1_pct'] ?? '0');

                $response2 = strtoupper(trim($row['response_2'] ?? ''));
                $response2Pct = $this->parsePercentage($row['response_2_pct'] ?? '0');

                $response3 = strtoupper(trim($row['response_3'] ?? ''));
                $response3Pct = $this->parsePercentage($row['response_3_pct'] ?? '0');

                $response4 = strtoupper(trim($row['response_4'] ?? ''));
                $response4Pct = $this->parsePercentage($row['response_4_pct'] ?? '0');

                // Find the question
                $question = ExamQuestion::where('exam_session_id', $this->examSessionId)
                    ->where('question_number', $questionNum)
                    ->first();

                if (! $question) {
                    Log::warning('Zipgrade stats import - Question not found', [
                        'session_id' => $this->examSessionId,
                        'question_num' => $questionNum,
                    ]);
                    $this->skippedCount++;

                    continue;
                }

                // Update question with stats
                $question->update([
                    'correct_answer' => $correctAnswer ?: null,
                    'response_1' => $response1 ?: null,
                    'response_1_pct' => $response1Pct,
                    'response_2' => $response2 ?: null,
                    'response_2_pct' => $response2Pct,
                    'response_3' => $response3 ?: null,
                    'response_3_pct' => $response3Pct,
                    'response_4' => $response4 ?: null,
                    'response_4_pct' => $response4Pct,
                ]);

                $this->processedCount++;
            }

            DB::commit();

            Log::info('Zipgrade stats import completed', [
                'session_id' => $this->examSessionId,
                'rows_total' => $this->rowCount,
                'rows_processed' => $this->processedCount,
                'rows_skipped' => $this->skippedCount,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Zipgrade stats import failed', [
                'session_id' => $this->examSessionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Obtiene valor de columna buscando en múltiples nombres posibles.
     */
    private function getColumnValue($row, array $possibleNames, string $default = ''): string
    {
        foreach ($possibleNames as $name) {
            if (isset($row[$name]) && ! empty($row[$name])) {
                return trim($row[$name]);
            }
        }

        return $default;
    }

    /**
     * Parse percentage value from various formats.
     * Zipgrade usa formato: "78.46" o "78.46%"
     */
    private function parsePercentage($value): ?float
    {
        if (empty($value)) {
            return null;
        }

        // Remove % sign if present
        $value = str_replace('%', '', $value);

        // Replace comma with dot for decimal separator
        $value = str_replace(',', '.', $value);

        // Parse as float
        $parsed = floatval($value);

        return $parsed > 0 ? $parsed : null;
    }

    public function getRowCount(): int
    {
        return $this->rowCount;
    }

    public function getProcessedCount(): int
    {
        return $this->processedCount;
    }

    public function getSkippedCount(): int
    {
        return $this->skippedCount;
    }
}
