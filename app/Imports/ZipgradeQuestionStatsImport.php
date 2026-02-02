<?php

namespace App\Imports;

use App\Models\ExamQuestion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ZipgradeQuestionStatsImport implements ToCollection, WithChunkReading, WithHeadingRow
{
    private int $examSessionId;

    private int $rowCount = 0;

    private int $processedCount = 0;

    private int $skippedCount = 0;

    public function __construct(int $examSessionId)
    {
        $this->examSessionId = $examSessionId;
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

                // Las columnas del Excel real de Zipgrade:
                // Question_Number, Primary_Answer, % Correct
                // Response 1, Response 1 %, Response 2, Response 2 %, etc.
                //
                // Laravel Excel convierte a snake_case:
                // response_1 (letra), response_1_ (porcentaje con % incluido)

                $questionNum = (int) ($row['question_number'] ?? $row['Question_Number'] ?? $row['questionnumber'] ?? 0);
                $correctAnswer = trim($row['primary_answer'] ?? $row['Primary_Answer'] ?? '');

                // Skip rows with missing question number
                if ($questionNum <= 0) {
                    $this->skippedCount++;
                    Log::debug('Zipgrade stats import - Skipping row', [
                        'index' => $index,
                        'question_num' => $questionNum,
                    ]);

                    continue;
                }

                // Los datos de Zipgrade YA vienen ordenados por % descendente
                // Response 1 = 1° más elegida, Response 2 = 2° más elegida, etc.
                // Múltiples variantes de nombres de columnas para compatibilidad

                // Response 1 (letra) - buscar en múltiples formatos
                $response1 = $this->getColumnValue($row, ['response_1', 'Response 1', 'response1', 'respuesta_1']);
                $response1Pct = $this->parsePercentage($this->getColumnValue($row, ['response_1_', 'Response 1 %', 'response_1_pct', 'response1_pct', 'respuesta_1_pct'], '0'));

                // Response 2 (letra)
                $response2 = $this->getColumnValue($row, ['response_2', 'Response 2', 'response2', 'respuesta_2']);
                $response2Pct = $this->parsePercentage($this->getColumnValue($row, ['response_2_', 'Response 2 %', 'response_2_pct', 'response2_pct', 'respuesta_2_pct'], '0'));

                // Response 3 (letra)
                $response3 = $this->getColumnValue($row, ['response_3', 'Response 3', 'response3', 'respuesta_3']);
                $response3Pct = $this->parsePercentage($this->getColumnValue($row, ['response_3_', 'Response 3 %', 'response_3_pct', 'response3_pct', 'respuesta_3_pct'], '0'));

                // Response 4 (letra)
                $response4 = $this->getColumnValue($row, ['response_4', 'Response 4', 'response4', 'respuesta_4']);
                $response4Pct = $this->parsePercentage($this->getColumnValue($row, ['response_4_', 'Response 4 %', 'response_4_pct', 'response4_pct', 'respuesta_4_pct'], '0'));

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
