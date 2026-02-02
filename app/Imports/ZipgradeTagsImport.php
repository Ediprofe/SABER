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

    /**
     * Analiza un archivo CSV de Zipgrade para detectar tags nuevos sin importar datos.
     * Este método es el Paso 1 del flujo de 2 pasos para clasificación de tags.
     * Usa lectura nativa de CSV para mejor rendimiento con archivos grandes.
     *
     * @return array Lista de tags nuevos que necesitan clasificación
     */
    public static function analyzeFile(string $filePath): array
    {
        $newTags = [];
        $uniqueTags = [];

        if (! file_exists($filePath)) {
            throw new \Exception("Archivo no encontrado: {$filePath}");
        }

        // Usar lectura nativa de CSV para mejor rendimiento en memoria
        $handle = fopen($filePath, 'r');
        if (! $handle) {
            throw new \Exception("No se pudo abrir el archivo: {$filePath}");
        }

        // Leer encabezados
        $headers = fgetcsv($handle);
        if (! $headers) {
            fclose($handle);

            return [];
        }

        // Normalizar encabezados (buscar columna Tag)
        $tagColumnIndex = null;
        foreach ($headers as $index => $header) {
            if (strcasecmp(trim($header), 'Tag') === 0) {
                $tagColumnIndex = $index;
                break;
            }
        }

        if ($tagColumnIndex === null) {
            fclose($handle);
            throw new \Exception("No se encontró la columna 'Tag' en el archivo CSV");
        }

        // Recolectar tags únicos línea por línea (bajo consumo de memoria)
        $rowCount = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $rowCount++;
            if (! isset($row[$tagColumnIndex])) {
                continue;
            }

            $tagName = trim($row[$tagColumnIndex]);

            if (! empty($tagName) && ! isset($uniqueTags[$tagName])) {
                $uniqueTags[$tagName] = $tagName;

                // Verificar si el tag existe en tag_hierarchy
                $exists = TagHierarchy::where('tag_name', $tagName)->exists();

                if (! $exists) {
                    // Verificar si existe en normalizaciones
                    $normalization = \App\Models\TagNormalization::findByCsvName($tagName);

                    if (! $normalization) {
                        $newTags[] = [
                            'csv_name' => $tagName,
                            'suggested_type' => self::inferTagType($tagName),
                            'suggested_area' => self::inferTagArea($tagName),
                        ];
                    }
                }
            }

            // Cada 1000 filas, liberar memoria
            if ($rowCount % 1000 === 0) {
                gc_collect_cycles();
            }
        }

        fclose($handle);

        return $newTags;
    }

    /**
     * Intenta inferir el tipo de tag basándose en patrones comunes.
     */
    private static function inferTagType(string $tagName): ?string
    {
        // Patrones comunes para áreas
        $areaPatterns = ['Ciencias', 'Matemáticas', 'Sociales', 'Lectura', 'Inglés'];
        foreach ($areaPatterns as $area) {
            if (stripos($tagName, $area) !== false) {
                return 'area';
            }
        }

        // Patrones específicos para Nivel de Lectura (Lectura Crítica)
        $nivelLecturaPatterns = ['literal', 'inferencial', 'crítico', 'evaluativo', 'inferencia', 'crítica'];
        foreach ($nivelLecturaPatterns as $pattern) {
            if (stripos($tagName, $pattern) !== false) {
                return 'nivel_lectura';
            }
        }

        // Patrones comunes para competencias (términos de acción)
        $competenciaPatterns = ['uso', 'interpretación', 'formulación', 'indagación', 'comprensivo', 'inferir', 'identificar', 'argumentación'];
        foreach ($competenciaPatterns as $pattern) {
            if (stripos($tagName, $pattern) !== false) {
                return 'competencia';
            }
        }

        // Patrones comunes para componentes (temas específicos)
        $componentePatterns = ['químico', 'físico', 'biológico', 'vivo', 'cts', 'numérico', 'geométrico', 'aleatorio', 'aleatorio', 'historia', 'geografía', 'político'];
        foreach ($componentePatterns as $pattern) {
            if (stripos($tagName, $pattern) !== false) {
                return 'componente';
            }
        }

        return null;
    }

    /**
     * Intenta inferir el área padre basándose en patrones comunes.
     */
    private static function inferTagArea(string $tagName): ?string
    {
        if (stripos($tagName, 'químico') !== false || stripos($tagName, 'físico') !== false ||
            stripos($tagName, 'biológico') !== false || stripos($tagName, 'vivo') !== false) {
            return 'Ciencias';
        }

        if (stripos($tagName, 'numérico') !== false || stripos($tagName, 'geométrico') !== false ||
            stripos($tagName, 'aleatorio') !== false) {
            return 'Matemáticas';
        }

        if (stripos($tagName, 'historia') !== false || stripos($tagName, 'geografía') !== false ||
            stripos($tagName, 'político') !== false || stripos($tagName, 'ético') !== false) {
            return 'Sociales';
        }

        if (stripos($tagName, 'continuo') !== false || stripos($tagName, 'discontinuo') !== false ||
            stripos($tagName, 'literario') !== false) {
            return 'Lectura';
        }

        // Nivel de Lectura siempre pertenece al área de Lectura
        $nivelLecturaPatterns = ['literal', 'inferencial', 'crítico', 'evaluativo', 'inferencia', 'crítica'];
        foreach ($nivelLecturaPatterns as $pattern) {
            if (stripos($tagName, $pattern) !== false) {
                return 'Lectura';
            }
        }

        return null;
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
                // IMPORTANTE: $studentId es el zipgrade_id (ID interno de Zipgrade), NO el documento de identidad
                if (! isset($uniqueStudents[$studentId])) {
                    $uniqueStudents[$studentId] = [
                        'zipgrade_id' => $studentId,
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
                // IMPORTANTE: $studentId es el zipgrade_id
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
     * Busca o crea estudiantes por zipgrade_id.
     * El StudentID en CSV de Zipgrade es el ID interno de Zipgrade, no el documento de identidad.
     */
    private function findOrCreateStudents(array $uniqueStudents): array
    {
        $studentIds = [];
        $this->processedStudents = [];

        foreach ($uniqueStudents as $zipgradeId => $data) {
            // First try to find by zipgrade_id (campo correcto del CSV de Zipgrade)
            $student = Student::where('zipgrade_id', $zipgradeId)->first();

            if (! $student && ! empty($data['first_name']) && ! empty($data['last_name'])) {
                // Try to find by name as fallback
                $student = Student::where('first_name', $data['first_name'])
                    ->where('last_name', $data['last_name'])
                    ->first();

                // If found by name, update zipgrade_id
                if ($student) {
                    $student->zipgrade_id = $zipgradeId;
                    $student->save();
                }
            }

            // If still not found, create new student
            if (! $student) {
                // Generate a temporary code
                $tempCode = 'TEMP-'.strtoupper(uniqid());

                $student = Student::create([
                    'code' => $tempCode,
                    'zipgrade_id' => $zipgradeId,
                    'first_name' => $data['first_name'] ?: 'Estudiante',
                    'last_name' => $data['last_name'] ?: $zipgradeId,
                ]);
            }

            $studentIds[$zipgradeId] = $student->id;
            $this->processedStudents[$zipgradeId] = $student->id;
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

                if ($tag) {
                    if ($tag->isArea()) {
                        // Si es un tag de área (ej: "Ciencias Naturales", "Matemáticas"), usar el nombre del tag como área
                        $inferredArea = $tag->tag_name;
                    } else {
                        // Si es un tag hijo (competencia, componente, etc.), usar el área padre
                        $inferredArea = $tag->parent_area;
                    }
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
