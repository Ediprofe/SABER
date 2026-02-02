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
            foreach ($rows as $index => $row) {
                // Debug first row
                if ($index === 0) {
                    Log::info('Zipgrade stats import - Processing first row', [
                        'row_data' => $row->toArray(),
                    ]);
                }

                // Handle both uppercase and lowercase column names
                // Excel generator creates snake_case: question_number, primary_answer, etc.
                $questionNum = (int) ($row['question_number'] ?? 0);
                $correctAnswer = trim($row['primary_answer'] ?? '');
                $pctCorrect = $this->parsePercentage($row['correct'] ?? '0');

                // Skip rows with missing question number
                if ($questionNum <= 0) {
                    $this->skippedCount++;
                    Log::debug('Zipgrade stats import - Skipping row', [
                        'index' => $index,
                        'question_num' => $questionNum,
                    ]);

                    continue;
                }

                // Response rankings - in generated Excel: response_1, response_2, etc. are percentages
                // We need to reconstruct the letter + percentage pairs
                // Order them by percentage descending
                $responses = [];

                // Add primary answer with its percentage (correct answer percentage)
                $responses[] = [
                    'letter' => $correctAnswer,
                    'pct' => $pctCorrect,
                ];

                // Get other response percentages
                $resp2Pct = $this->parsePercentage($row['response_2'] ?? '0');
                $resp3Pct = $this->parsePercentage($row['response_3'] ?? '0');
                $resp4Pct = $this->parsePercentage($row['response_4'] ?? '0');

                // Assign letters to other responses (skip the correct answer letter)
                $allLetters = ['A', 'B', 'C', 'D'];
                $otherLetters = array_diff($allLetters, [$correctAnswer]);
                $otherLetters = array_values($otherLetters);

                if (isset($otherLetters[0])) {
                    $responses[] = ['letter' => $otherLetters[0], 'pct' => $resp2Pct ?? 0];
                }
                if (isset($otherLetters[1])) {
                    $responses[] = ['letter' => $otherLetters[1], 'pct' => $resp3Pct ?? 0];
                }
                if (isset($otherLetters[2])) {
                    $responses[] = ['letter' => $otherLetters[2], 'pct' => $resp4Pct ?? 0];
                }

                // Sort by percentage descending
                usort($responses, fn ($a, $b) => $b['pct'] <=> $a['pct']);

                // Assign to response 1-4
                $response1 = $responses[0]['letter'] ?? 'A';
                $response1Pct = $responses[0]['pct'] ?? 0;
                $response2 = $responses[1]['letter'] ?? 'B';
                $response2Pct = $responses[1]['pct'] ?? 0;
                $response3 = $responses[2]['letter'] ?? 'C';
                $response3Pct = $responses[2]['pct'] ?? 0;
                $response4 = $responses[3]['letter'] ?? 'D';
                $response4Pct = $responses[3]['pct'] ?? 0;

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
     * Parse percentage value from various formats.
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
