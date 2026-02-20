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
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ZipgradeTagsImport implements ToCollection, WithHeadingRow
{
    private int $examSessionId;

    private array $tagMappings;

    private int $rowCount = 0;

    private array $processedStudents = [];

    private array $processedQuestions = [];

    private array $newTags = [];

    private array $tagsForNormalization = [];

    private array $enrollmentCache = [];

    private ?ExamSession $session = null;

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

    public function collection(Collection $rows)
    {
        $this->rowCount = $rows->count();

        if ($this->rowCount > 0) {
            $sampleRow = $rows->first();
            Log::info('Zipgrade import - Start processing', [
                'rows' => $this->rowCount,
                'keys' => array_keys($sampleRow->toArray()),
                'session_id' => $this->examSessionId,
            ]);
        }

        if ($this->rowCount === 0) {
            Log::warning('Zipgrade import - No rows to process', ['session_id' => $this->examSessionId]);
            return;
        }

        // Cargar sesión y examen una sola vez
        $this->session = ExamSession::with('exam')->find($this->examSessionId);
        if (!$this->session || !$this->session->exam) {
            throw new \Exception("Sesión o examen no encontrado.");
        }

        DB::beginTransaction();

        try {
            $uniqueStudents = [];
            $uniqueQuestions = [];
            $uniqueTags = [];
            $answers = [];
            $processedRows = 0;

            foreach ($rows as $index => $row) {
                $earnedPoints = (float) str_replace(',', '.', $row['EarnedPoints'] ?? $row['earnedpoints'] ?? $row['earned_points'] ?? '0');
                $tagName = trim($row['Tag'] ?? $row['tag'] ?? '');
                $studentId = trim($row['StudentID'] ?? $row['studentid'] ?? $row['student_id'] ?? '');
                $studentFirstName = trim($row['StudentFirstName'] ?? $row['studentfirstname'] ?? $row['student_first_name'] ?? '');
                $studentLastName = trim($row['StudentLastName'] ?? $row['studentlastname'] ?? $row['student_last_name'] ?? '');
                $questionNum = (int) ($row['QuestionNumber'] ?? $row['questionnumber'] ?? $row['question_num'] ?? $row['QuestionNum'] ?? $row['questionnum'] ?? 0);
                $quizName = trim($row['QuizName'] ?? $row['quizname'] ?? '');

                if (empty($tagName) || empty($studentId) || $questionNum <= 0) {
                    continue;
                }

                $processedRows++;

                if (!isset($uniqueStudents[$studentId])) {
                    $uniqueStudents[$studentId] = [
                        'zipgrade_id' => $studentId,
                        'first_name' => $studentFirstName,
                        'last_name' => $studentLastName,
                    ];
                }

                $questionKey = "{$this->examSessionId}_{$questionNum}";
                if (!isset($uniqueQuestions[$questionKey])) {
                    $uniqueQuestions[$questionKey] = [
                        'exam_session_id' => $this->examSessionId,
                        'question_number' => $questionNum,
                    ];
                }

                if (!isset($uniqueTags[$tagName])) {
                    $uniqueTags[$tagName] = $tagName;
                }

                $answers[] = [
                    'student_id' => $studentId,
                    'question_num' => $questionNum,
                    'tag_name' => $tagName,
                    'is_correct' => $earnedPoints > 0,
                    'quiz_name' => $quizName,
                ];
            }

            // --- OPTIMIZACIÓN: Pre-carga de datos ---
            $this->detectNewTags($uniqueTags);
            $tagIds = $this->ensureTagsExist($uniqueTags);
            $studentIds = $this->findOrCreateStudents($uniqueStudents);
            $questionIds = $this->createOrFindQuestions($uniqueQuestions);

            // Pre-cargar jerarquía de tags para inferencia rápida
            $tagsInfo = TagHierarchy::whereIn('id', array_values($tagIds))->get()->keyBy('id');

            // --- OPTIMIZACIÓN: Question Tags en lote ---
            $this->createQuestionTagsBulk($rows, $questionIds, $tagIds, $tagsInfo);

            // --- OPTIMIZACIÓN: Student Answers en lote ---
            $this->createStudentAnswersBulk($answers, $questionIds, $studentIds);

            if ($this->session && !empty($answers)) {
                $this->session->zipgrade_quiz_name = $answers[0]['quiz_name'] ?? null;
                $this->session->save();
            }

            DB::commit();

            Log::info('Zipgrade import completed', [
                'session_id' => $this->examSessionId,
                'rows_total' => $this->rowCount,
                'rows_processed' => $processedRows,
                'students_count' => count($uniqueStudents),
                'questions_count' => count($uniqueQuestions),
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
        $zipIds = array_keys($uniqueStudents);

        // Pre-cargar todos los estudiantes que tienen zipgrade_id en una sola consulta
        $existingStudents = Student::whereIn('zipgrade_id', $zipIds)->get()->keyBy('zipgrade_id');

        foreach ($uniqueStudents as $zipgradeId => $data) {
            if (isset($existingStudents[$zipgradeId])) {
                $student = $existingStudents[$zipgradeId];
            } else {
                // Si no existe, intentar por nombre SOLO si la coincidencia es única.
                $nameMatches = Student::query()
                    ->where('first_name', $data['first_name'])
                    ->where('last_name', $data['last_name'])
                    ->limit(2)
                    ->get();

                if ($nameMatches->count() === 1) {
                    $candidate = $nameMatches->first();

                    // Si el candidato ya tiene otro zipgrade_id, evitar enlazar mal.
                    if (! empty($candidate->zipgrade_id) && $candidate->zipgrade_id !== $zipgradeId) {
                        Log::warning('Zipgrade import - Candidate student has conflicting zipgrade_id', [
                            'candidate_id' => $candidate->id,
                            'candidate_zipgrade_id' => $candidate->zipgrade_id,
                            'incoming_zipgrade_id' => $zipgradeId,
                            'first_name' => $data['first_name'],
                            'last_name' => $data['last_name'],
                        ]);
                        $student = null;
                    } else {
                        $candidate->zipgrade_id = $zipgradeId;
                        $candidate->save();
                        $student = $candidate;
                    }
                } else {
                    if ($nameMatches->count() > 1) {
                        Log::warning('Zipgrade import - Ambiguous student name, creating temporary student', [
                            'incoming_zipgrade_id' => $zipgradeId,
                            'first_name' => $data['first_name'],
                            'last_name' => $data['last_name'],
                        ]);
                    }
                    $student = null;
                }

                if (! $student) {
                    // Si aún no existe, crear uno temporal (fallback seguro).
                    $tempCode = 'TEMP-'.strtoupper(uniqid());
                    $student = Student::create([
                        'code' => $tempCode,
                        'zipgrade_id' => $zipgradeId,
                        'first_name' => $data['first_name'] ?: 'Estudiante',
                        'last_name' => $data['last_name'] ?: $zipgradeId,
                    ]);
                }
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
     * Crea los tags de las preguntas de forma masiva.
     */
    private function createQuestionTagsBulk(Collection $rows, array $questionIds, array $tagIds, Collection $tagsInfo): void
    {
        $inserts = [];
        $seen = [];

        foreach ($rows as $row) {
            $tagName = trim($row['Tag'] ?? $row['tag'] ?? '');
            $questionNum = (int) ($row['QuestionNumber'] ?? $row['questionnumber'] ?? $row['question_num'] ?? $row['QuestionNum'] ?? $row['questionnum'] ?? 0);

            if (empty($tagName) || $questionNum <= 0) continue;
            if (!isset($questionIds[$questionNum]) || !isset($tagIds[$tagName])) continue;

            $questionId = $questionIds[$questionNum];
            $tagId = $tagIds[$tagName];
            $key = "{$questionId}_{$tagId}";

            if (!isset($seen[$key])) {
                $tag = $tagsInfo->get($tagId);
                $inferredArea = $tag ? ($tag->isArea() ? $tag->tag_name : $tag->parent_area) : null;

                $inserts[] = [
                    'exam_question_id' => $questionId,
                    'tag_hierarchy_id' => $tagId,
                    'inferred_area' => $inferredArea,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $seen[$key] = true;
            }

            // Insertar cada 500 para evitar límites de SQL
            if (count($inserts) >= 500) {
                QuestionTag::query()->upsert(
                    $inserts,
                    ['exam_question_id', 'tag_hierarchy_id'],
                    ['inferred_area', 'updated_at']
                );
                $inserts = [];
            }
        }

        if (!empty($inserts)) {
            QuestionTag::query()->upsert(
                $inserts,
                ['exam_question_id', 'tag_hierarchy_id'],
                ['inferred_area', 'updated_at']
            );
        }
    }

    /**
     * Crea las respuestas de los estudiantes de forma masiva.
     */
    private function createStudentAnswersBulk(array $answers, array $questionIds, array $studentIds): void
    {
        // Pre-cargar todas las matrículas necesarias
        $this->preloadEnrollments(array_values($studentIds));

        $uniqueAnswers = [];
        foreach ($answers as $answer) {
            $studentId = $answer['student_id'];
            $questionNum = $answer['question_num'];

            if (!isset($studentIds[$studentId]) || !isset($questionIds[$questionNum])) continue;

            $enrollmentId = $this->enrollmentCache[$studentIds[$studentId]] ?? null;
            if (!$enrollmentId) {
                Log::warning("Zipgrade import - No active enrollment found for student ID: {$studentId} system_id: " . $studentIds[$studentId]);
                continue;
            }

            $questionId = $questionIds[$questionNum];
            $key = "{$enrollmentId}_{$questionId}";

            if (!isset($uniqueAnswers[$key])) {
                $uniqueAnswers[$key] = [
                    'exam_question_id' => $questionId,
                    'enrollment_id' => $enrollmentId,
                    'is_correct' => $answer['is_correct'],
                ];
            } elseif ($answer['is_correct']) {
                $uniqueAnswers[$key]['is_correct'] = true;
            }
        }

        $inserts = [];
        foreach ($uniqueAnswers as $answerData) {
            $inserts[] = array_merge($answerData, [
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (count($inserts) >= 500) {
                StudentAnswer::query()->upsert(
                    $inserts,
                    ['exam_question_id', 'enrollment_id'],
                    ['is_correct', 'updated_at']
                );
                $inserts = [];
            }
        }

        if (!empty($inserts)) {
            StudentAnswer::query()->upsert(
                $inserts,
                ['exam_question_id', 'enrollment_id'],
                ['is_correct', 'updated_at']
            );
        }
    }

    /**
     * Pre-carga las matrículas activas de los estudiantes para el año del examen.
     */
    private function preloadEnrollments(array $systemStudentIds): void
    {
        if (!$this->session || !$this->session->exam) return;

        $enrollments = Enrollment::whereIn('student_id', $systemStudentIds)
            ->where('academic_year_id', $this->session->exam->academic_year_id)
            ->where('status', 'ACTIVE')
            ->get();

        foreach ($enrollments as $enrollment) {
            $this->enrollmentCache[$enrollment->student_id] = $enrollment->id;
        }
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
