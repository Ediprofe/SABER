<?php

namespace App\Imports;

use App\Models\Enrollment;
use App\Models\ExamQuestion;
use App\Models\ExamSession;
use App\Models\QuestionTag;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\TagHierarchy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ZipgradeTagsImport implements ToCollection, WithChunkReading, WithHeadingRow
{
    private int $examSessionId;

    private array $tagMappings;

    private int $rowCount = 0;

    private array $processedStudents = [];

    private array $processedQuestions = [];

    private array $newTags = [];

    private array $tagsForNormalization = [];

    public function __construct(int $examSessionId, array $tagMappings = [])
    {
        $this->examSessionId = $examSessionId;
        $this->tagMappings = $tagMappings;
    }

    public function chunkSize(): int
    {
        return 1000; // Process 1000 rows at a time to avoid memory issues
    }

    public function collection(Collection $rows)
    {
        $this->rowCount = $rows->count();

        // Log first few rows to debug column names
        if ($this->rowCount > 0) {
            $sampleRow = $rows->first();
            Log::info('Zipgrade import - Sample row keys', [
                'keys' => array_keys($sampleRow->toArray()),
                'session_id' => $this->examSessionId,
            ]);
        }

        if ($this->rowCount === 0) {
            Log::warning('Zipgrade import - No rows to process', [
                'session_id' => $this->examSessionId,
            ]);

            return;
        }

        DB::beginTransaction();

        try {
            // Step 1: Collect unique data
            $uniqueStudents = [];
            $uniqueQuestions = [];
            $uniqueTags = [];
            $answers = [];
            $processedRows = 0;
            $skippedRows = 0;

            foreach ($rows as $index => $row) {
                // Handle both uppercase and lowercase column names
                $earnedPoints = (float) str_replace(',', '.', $row['EarnedPoints'] ?? $row['earnedpoints'] ?? $row['earned_points'] ?? '0');

                // Key fields - try multiple variations
                $tagName = trim($row['Tag'] ?? $row['tag'] ?? '');
                $studentId = trim($row['StudentID'] ?? $row['studentid'] ?? $row['student_id'] ?? '');
                $studentFirstName = trim($row['StudentFirstName'] ?? $row['studentfirstname'] ?? $row['student_first_name'] ?? '');
                $studentLastName = trim($row['StudentLastName'] ?? $row['studentlastname'] ?? $row['student_last_name'] ?? '');
                $questionNum = (int) ($row['QuestionNumber'] ?? $row['questionnumber'] ?? $row['question_num'] ?? $row['QuestionNum'] ?? $row['questionnum'] ?? 0);
                $quizName = trim($row['QuizName'] ?? $row['quizname'] ?? '');

                // Skip rows with missing required data
                if (empty($tagName) || empty($studentId) || $questionNum <= 0) {
                    $skippedRows++;
                    Log::debug('Zipgrade import - Skipping row', [
                        'index' => $index,
                        'tag' => $tagName,
                        'student_id' => $studentId,
                        'question_num' => $questionNum,
                        'raw_row' => $row->toArray(),
                    ]);

                    continue;
                }

                $processedRows++;

                // Store unique student
                if (! isset($uniqueStudents[$studentId])) {
                    $uniqueStudents[$studentId] = [
                        'document_id' => $studentId,
                        'first_name' => $studentFirstName,
                        'last_name' => $studentLastName,
                    ];
                }

                // Store unique question
                $questionKey = "{$this->examSessionId}_{$questionNum}";
                if (! isset($uniqueQuestions[$questionKey])) {
                    $uniqueQuestions[$questionKey] = [
                        'exam_session_id' => $this->examSessionId,
                        'question_number' => $questionNum,
                    ];
                }

                // Store unique tag
                if (! isset($uniqueTags[$tagName])) {
                    $uniqueTags[$tagName] = $tagName;
                }

                // Store answer info (will be processed later)
                $answers[] = [
                    'student_id' => $studentId,
                    'question_num' => $questionNum,
                    'tag_name' => $tagName,
                    'is_correct' => $earnedPoints > 0,
                    'quiz_name' => $quizName,
                ];
            }

            // Step 2: Check for new tags
            $this->detectNewTags($uniqueTags);

            // Step 3: Create or find tags
            $tagIds = $this->ensureTagsExist($uniqueTags);

            // Step 4: Find or create students
            $studentIds = $this->findOrCreateStudents($uniqueStudents);

            // Step 5: Create questions
            $questionIds = $this->createOrFindQuestions($uniqueQuestions);

            // Step 6: Create question tags
            $this->createQuestionTags($rows, $questionIds, $tagIds);

            // Step 7: Create student answers
            $this->createStudentAnswers($answers, $questionIds, $studentIds);

            // Update session quiz name
            $session = ExamSession::find($this->examSessionId);
            if ($session && ! empty($answers)) {
                $session->zipgrade_quiz_name = $answers[0]['quiz_name'] ?? null;
                $session->save();
            }

            DB::commit();

            Log::info('Zipgrade import completed', [
                'session_id' => $this->examSessionId,
                'rows_total' => $this->rowCount,
                'rows_processed' => $processedRows,
                'rows_skipped' => $skippedRows,
                'students_count' => count($uniqueStudents),
                'questions_count' => count($uniqueQuestions),
                'tags_count' => count($uniqueTags),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Zipgrade import failed', [
                'session_id' => $this->examSessionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Detecta tags nuevos que no existen en la base de datos.
     */
    private function detectNewTags(array $uniqueTags): void
    {
        foreach ($uniqueTags as $tagName) {
            $exists = TagHierarchy::where('tag_name', $tagName)->exists();
            if (! $exists) {
                $this->newTags[] = $tagName;
            }
        }
    }

    /**
     * Asegura que todos los tags existan en la base de datos.
     * Checks for TagNormalization and uses normalized values if available.
     */
    private function ensureTagsExist(array $uniqueTags): array
    {
        $tagIds = [];

        foreach ($uniqueTags as $csvTagName) {
            // Step 1: Check for TagNormalization
            $normalization = \App\Models\TagNormalization::findByCsvName($csvTagName);

            // Determine the values to use
            if ($normalization) {
                // Use normalized values
                $systemTagName = $normalization->tag_system_name;
                $tagType = $normalization->tag_type;
                $parentArea = $normalization->parent_area;
            } else {
                // No normalization exists - store for later normalization
                $this->storeTagForNormalization($csvTagName);

                // Use CSV values (fallback to tagMappings if available)
                $systemTagName = $csvTagName;
                if (isset($this->tagMappings[$csvTagName])) {
                    $tagType = $this->tagMappings[$csvTagName]['tag_type'];
                    $parentArea = $this->tagMappings[$csvTagName]['parent_area'] ?? null;
                } else {
                    $tagType = null;
                    $parentArea = null;
                }
            }

            // Step 2: Find or create TagHierarchy using system name
            $tag = TagHierarchy::where('tag_name', $systemTagName)->first();

            if (! $tag && $tagType !== null) {
                // Create tag with determined values
                $tag = TagHierarchy::create([
                    'tag_name' => $systemTagName,
                    'tag_type' => $tagType,
                    'parent_area' => $parentArea,
                ]);
            }

            // Map the CSV tag name to the tag ID (so callers can reference by CSV name)
            if ($tag) {
                $tagIds[$csvTagName] = $tag->id;
            }
        }

        return $tagIds;
    }

    /**
     * Store a tag for later normalization when no normalization exists.
     */
    private function storeTagForNormalization(string $csvTagName): void
    {
        if (! in_array($csvTagName, $this->tagsForNormalization)) {
            $this->tagsForNormalization[] = $csvTagName;
        }
    }

    /**
     * Get tags that were detected but have no normalization.
     */
    public function getTagsForNormalization(): array
    {
        return $this->tagsForNormalization;
    }

    /**
     * Busca o crea estudiantes por document_id.
     */
    private function findOrCreateStudents(array $uniqueStudents): array
    {
        $studentIds = [];
        $this->processedStudents = [];

        foreach ($uniqueStudents as $docId => $data) {
            // First try to find by document_id
            $student = Student::where('document_id', $docId)->first();

            if (! $student && ! empty($data['first_name']) && ! empty($data['last_name'])) {
                // Try to find by name
                $student = Student::where('first_name', $data['first_name'])
                    ->where('last_name', $data['last_name'])
                    ->first();

                // If found by name, update document_id
                if ($student) {
                    $student->document_id = $docId;
                    $student->save();
                }
            }

            // If still not found, create new student
            if (! $student) {
                // Generate a temporary code
                $tempCode = 'TEMP-'.strtoupper(uniqid());

                $student = Student::create([
                    'code' => $tempCode,
                    'document_id' => $docId,
                    'first_name' => $data['first_name'] ?: 'Estudiante',
                    'last_name' => $data['last_name'] ?: $docId,
                ]);
            }

            $studentIds[$docId] = $student->id;
            $this->processedStudents[$docId] = $student->id;
        }

        return $studentIds;
    }

    /**
     * Crea o encuentra preguntas.
     */
    private function createOrFindQuestions(array $uniqueQuestions): array
    {
        $questionIds = [];

        foreach ($uniqueQuestions as $key => $data) {
            $question = ExamQuestion::firstOrCreate(
                [
                    'exam_session_id' => $data['exam_session_id'],
                    'question_number' => $data['question_number'],
                ],
                [
                    'exam_session_id' => $data['exam_session_id'],
                    'question_number' => $data['question_number'],
                ]
            );

            $questionIds[$data['question_number']] = $question->id;
        }

        return $questionIds;
    }

    /**
     * Crea los tags de las preguntas.
     */
    private function createQuestionTags(Collection $rows, array $questionIds, array $tagIds): void
    {
        // Clear existing tags for these questions
        ExamQuestion::whereIn('id', $questionIds)
            ->each(function ($question) {
                $question->questionTags()->delete();
            });

        // Create new tags
        foreach ($rows as $row) {
            // Handle both uppercase and lowercase column names
            $tagName = trim($row['Tag'] ?? $row['tag'] ?? '');
            $questionNum = (int) ($row['QuestionNumber'] ?? $row['questionnumber'] ?? $row['question_num'] ?? $row['QuestionNum'] ?? $row['questionnum'] ?? 0);

            if (empty($tagName) || $questionNum <= 0) {
                continue;
            }

            if (! isset($questionIds[$questionNum]) || ! isset($tagIds[$tagName])) {
                continue;
            }

            $questionId = $questionIds[$questionNum];
            $tagId = $tagIds[$tagName];

            // Check if already exists
            $exists = QuestionTag::where('exam_question_id', $questionId)
                ->where('tag_hierarchy_id', $tagId)
                ->exists();

            if (! $exists) {
                // Infer area if tag is not an area
                $tag = TagHierarchy::find($tagId);
                $inferredArea = null;

                if ($tag && ! $tag->isArea()) {
                    $inferredArea = $tag->parent_area;
                }

                QuestionTag::create([
                    'exam_question_id' => $questionId,
                    'tag_hierarchy_id' => $tagId,
                    'inferred_area' => $inferredArea,
                ]);
            }
        }
    }

    /**
     * Crea las respuestas de los estudiantes.
     */
    private function createStudentAnswers(array $answers, array $questionIds, array $studentIds): void
    {
        // Process unique student-question combinations
        $uniqueAnswers = [];

        foreach ($answers as $answer) {
            $studentId = $answer['student_id'];
            $questionNum = $answer['question_num'];

            if (! isset($studentIds[$studentId]) || ! isset($questionIds[$questionNum])) {
                continue;
            }

            $enrollmentId = $this->getEnrollmentId($studentIds[$studentId]);
            if (! $enrollmentId) {
                continue;
            }

            $questionId = $questionIds[$questionNum];
            $key = "{$enrollmentId}_{$questionId}";

            // Only store the first (or any correct) answer for each question
            if (! isset($uniqueAnswers[$key])) {
                $uniqueAnswers[$key] = [
                    'exam_question_id' => $questionId,
                    'enrollment_id' => $enrollmentId,
                    'is_correct' => $answer['is_correct'],
                ];
            } elseif ($answer['is_correct']) {
                // If we found a correct answer, prefer it
                $uniqueAnswers[$key]['is_correct'] = true;
            }
        }

        // Delete existing answers for these questions
        $questionIdList = array_values($questionIds);
        $enrollmentIdList = array_unique(array_map(fn ($a) => $a['enrollment_id'], $uniqueAnswers));

        if (! empty($questionIdList) && ! empty($enrollmentIdList)) {
            StudentAnswer::whereIn('exam_question_id', $questionIdList)
                ->whereIn('enrollment_id', $enrollmentIdList)
                ->delete();
        }

        // Insert new answers
        foreach ($uniqueAnswers as $answerData) {
            StudentAnswer::create($answerData);
        }
    }

    /**
     * Obtiene el ID de matrícula activa para un estudiante.
     */
    private function getEnrollmentId(int $studentId): ?int
    {
        $session = ExamSession::find($this->examSessionId);
        if (! $session) {
            return null;
        }

        $exam = $session->exam;
        if (! $exam) {
            return null;
        }

        $enrollment = Enrollment::where('student_id', $studentId)
            ->where('academic_year_id', $exam->academic_year_id)
            ->where('status', 'ACTIVE')
            ->first();

        return $enrollment?->id;
    }

    public function getRowCount(): int
    {
        return $this->rowCount;
    }

    public function getStudentsCount(): int
    {
        return count($this->processedStudents);
    }

    public function getNewTags(): array
    {
        return $this->newTags;
    }

    public function hasNewTags(): bool
    {
        return count($this->newTags) > 0;
    }
}
