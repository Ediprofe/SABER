<?php

namespace App\Console\Commands;

use App\Imports\ZipgradeTagsImport;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSession;
use App\Models\QuestionTag;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\TagHierarchy;
use App\Models\ZipgradeImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportZipgradeSession extends Command
{
    protected $signature = 'zipgrade:import-session 
                            {exam_id : ID del examen}
                            {session_number : Número de sesión (1 o 2)}
                            {file_path : Ruta completa al archivo CSV}';

    protected $description = 'Importa una sesión de Zipgrade desde archivo CSV (evita límites de subida web)';

    private int $chunkSize = 1000;

    private int $totalRows = 0;

    private int $processedRows = 0;

    private int $studentsCount = 0;

    private array $processedStudents = [];

    private array $processedQuestions = [];

    public function handle(): int
    {
        // Aumentar límites de memoria y tiempo para archivos grandes
        ini_set('memory_limit', '1G');
        ini_set('max_execution_time', 600);

        $examId = $this->argument('exam_id');
        $sessionNumber = $this->argument('session_number');
        $filePath = $this->argument('file_path');

        // Buscar el examen
        $exam = Exam::find($examId);
        if (! $exam) {
            $this->error("Examen con ID {$examId} no encontrado");

            return 1;
        }

        $this->info("Importando Sesión {$sessionNumber} para: {$exam->name}");
        $this->info("Archivo: {$filePath}");

        // Verificar que el archivo existe
        if (! file_exists($filePath)) {
            $this->error("Archivo no encontrado: {$filePath}");

            return 1;
        }

        $fileSize = filesize($filePath);
        $this->info('Tamaño del archivo: '.round($fileSize / 1024 / 1024, 2).' MB');

        // Contar filas totales
        $this->totalRows = count(file($filePath)) - 1;
        $this->info("Filas totales a procesar: {$this->totalRows}");

        // Crear o encontrar la sesión
        $session = ExamSession::firstOrCreate(
            ['exam_id' => $exam->id, 'session_number' => $sessionNumber],
            ['name' => "Sesión {$sessionNumber}"]
        );

        $this->info("Sesión creada/encontrada: {$session->name} (ID: {$session->id})");

        // Analizar tags nuevos
        $this->info('Analizando tags en el archivo...');
        $newTags = ZipgradeTagsImport::analyzeFile($filePath);

        if (! empty($newTags)) {
            $this->warn('Se detectaron '.count($newTags).' tags nuevos:');
            foreach ($newTags as $tag) {
                $this->line("  - {$tag['csv_name']} (Sugerido: {$tag['suggested_type']} en {$tag['suggested_area']})");
            }

            if ($this->confirm('¿Desea continuar con estos tags detectados?', true)) {
                // Crear los tags automáticamente con las sugerencias
                foreach ($newTags as $tagData) {
                    \App\Models\TagHierarchy::create([
                        'tag_name' => $tagData['csv_name'],
                        'tag_type' => $tagData['suggested_type'] ?? 'componente',
                        'parent_area' => $tagData['suggested_area'] ?: null,
                    ]);
                    $this->info("  ✓ Creado tag: {$tagData['csv_name']}");
                }
            } else {
                $this->error("Importación cancelada. Configure los tags manualmente en el menú 'Jerarquía de Tags'");

                return 1;
            }
        } else {
            $this->info('No hay tags nuevos. Procediendo con importación...');
        }

        // Crear registro de importación
        $import = ZipgradeImport::create([
            'exam_session_id' => $session->id,
            'filename' => basename($filePath),
            'total_rows' => 0,
            'status' => 'processing',
        ]);

        try {
            // Procesar la importación por chunks
            $this->info('Procesando importación por chunks...');
            $this->importByChunks($filePath, $session->id, $exam->academic_year_id);

            // Actualizar estadísticas
            $session->refresh();
            $session->total_questions = $session->questions()->count();
            $session->save();

            $import->update([
                'status' => 'completed',
                'total_rows' => $this->processedRows,
            ]);

            $this->info('✅ Importación completada exitosamente!');
            $this->info("  - Estudiantes importados: {$this->studentsCount}");
            $this->info("  - Preguntas: {$session->total_questions}");
            $this->info("  - Filas procesadas: {$this->processedRows}");

            return 0;

        } catch (\Exception $e) {
            $import->markAsError($e->getMessage());
            $this->error('❌ Error en la importación: '.$e->getMessage());
            Log::error('Importación fallida', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return 1;
        }
    }

    private function importByChunks(string $filePath, int $sessionId, int $academicYearId): void
    {
        $handle = fopen($filePath, 'r');
        if (! $handle) {
            throw new \Exception("No se pudo abrir el archivo: {$filePath}");
        }

        // Leer encabezados
        $headers = fgetcsv($handle);
        if (! $headers) {
            fclose($handle);
            throw new \Exception('No se pudieron leer los encabezados del CSV');
        }

        // Normalizar encabezados
        $headerMap = [];
        foreach ($headers as $index => $header) {
            $headerMap[strtolower(trim($header))] = $index;
        }

        $chunk = [];
        $rowNum = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            $chunk[] = $row;

            if (count($chunk) >= $this->chunkSize) {
                $this->processChunk($chunk, $headerMap, $sessionId, $academicYearId, $rowNum);
                $chunk = [];
                gc_collect_cycles(); // Liberar memoria
            }
        }

        // Procesar último chunk si queda
        if (! empty($chunk)) {
            $this->processChunk($chunk, $headerMap, $sessionId, $academicYearId, $rowNum);
        }

        fclose($handle);
    }

    private function processChunk(array $rows, array $headerMap, int $sessionId, int $academicYearId, int $currentRow): void
    {
        DB::beginTransaction();

        try {
            $uniqueStudents = [];
            $uniqueQuestions = [];
            $answers = [];

            foreach ($rows as $row) {
                // Extraer datos usando los índices de columnas
                $tagName = trim($row[$headerMap['tag'] ?? $headerMap['tag'] ?? 0] ?? '');
                $studentId = trim($row[$headerMap['studentid'] ?? 3] ?? '');
                $studentFirstName = trim($row[$headerMap['studentfirstname'] ?? 1] ?? '');
                $studentLastName = trim($row[$headerMap['studentlastname'] ?? 2] ?? '');
                $questionNum = (int) ($row[$headerMap['questionnumber'] ?? 7] ?? 0);
                $earnedPoints = (float) str_replace(',', '.', $row[$headerMap['earnedpoints'] ?? 8] ?? '0');
                $quizName = trim($row[$headerMap['quizname'] ?? 5] ?? '');

                if (empty($tagName) || empty($studentId) || $questionNum <= 0) {
                    continue;
                }

                // Guardar estudiante único
                if (! isset($uniqueStudents[$studentId])) {
                    $uniqueStudents[$studentId] = [
                        'zipgrade_id' => $studentId,
                        'first_name' => $studentFirstName,
                        'last_name' => $studentLastName,
                    ];
                }

                // Guardar pregunta única
                $questionKey = "{$sessionId}_{$questionNum}";
                if (! isset($uniqueQuestions[$questionKey])) {
                    $uniqueQuestions[$questionKey] = [
                        'exam_session_id' => $sessionId,
                        'question_number' => $questionNum,
                    ];
                }

                // Guardar respuesta
                $answers[] = [
                    'student_id' => $studentId,
                    'question_num' => $questionNum,
                    'tag_name' => $tagName,
                    'is_correct' => $earnedPoints > 0,
                    'quiz_name' => $quizName,
                ];
            }

            // Crear o encontrar estudiantes
            $studentIds = $this->findOrCreateStudents($uniqueStudents, $academicYearId);

            // Crear o encontrar preguntas
            $questionIds = $this->createOrFindQuestions($uniqueQuestions);

            // Crear tags de preguntas
            $this->createQuestionTagsForChunk($answers, $questionIds);

            // Crear respuestas de estudiantes
            $this->createStudentAnswersForChunk($answers, $questionIds, $studentIds, $academicYearId);

            DB::commit();

            $this->processedRows += count($rows);
            $progress = round(($this->processedRows / $this->totalRows) * 100, 1);
            $this->info("Progreso: {$progress}% ({$this->processedRows} / {$this->totalRows} filas)");

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function findOrCreateStudents(array $uniqueStudents, int $academicYearId): array
    {
        $studentIds = [];

        foreach ($uniqueStudents as $zipgradeId => $data) {
            // Buscar por zipgrade_id
            $student = Student::where('zipgrade_id', $zipgradeId)->first();

            if (! $student && ! empty($data['first_name']) && ! empty($data['last_name'])) {
                // Buscar por nombre como fallback
                $student = Student::where('first_name', $data['first_name'])
                    ->where('last_name', $data['last_name'])
                    ->first();

                if ($student) {
                    $student->zipgrade_id = $zipgradeId;
                    $student->save();
                }
            }

            // Crear nuevo estudiante si no existe
            if (! $student) {
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

        $this->studentsCount = count($this->processedStudents);

        return $studentIds;
    }

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
            $this->processedQuestions[$data['question_number']] = $question->id;
        }

        return $questionIds;
    }

    private function createQuestionTagsForChunk(array $answers, array $questionIds): void
    {
        // Obtener tags únicos de este chunk
        $uniqueTags = [];
        foreach ($answers as $answer) {
            $tagName = $answer['tag_name'];
            $questionNum = $answer['question_num'];

            if (isset($questionIds[$questionNum])) {
                $uniqueTags[$tagName][$questionNum] = true;
            }
        }

        // Obtener IDs de tags existentes
        $tagIds = [];
        foreach (array_keys($uniqueTags) as $tagName) {
            $tag = TagHierarchy::where('tag_name', $tagName)->first();
            if ($tag) {
                $tagIds[$tagName] = $tag->id;
            }
        }

        // Crear question_tags
        foreach ($uniqueTags as $tagName => $questions) {
            if (! isset($tagIds[$tagName])) {
                continue;
            }

            $tagId = $tagIds[$tagName];
            $tag = TagHierarchy::find($tagId);

            foreach (array_keys($questions) as $questionNum) {
                if (! isset($questionIds[$questionNum])) {
                    continue;
                }

                $questionId = $questionIds[$questionNum];

                // Calcular inferred_area
                $inferredArea = null;
                if ($tag) {
                    if ($tag->isArea()) {
                        $inferredArea = $tag->tag_name;
                    } else {
                        $inferredArea = $tag->parent_area;
                    }
                }

                // Usar insertOrIgnore para evitar duplicados
                QuestionTag::insertOrIgnore([
                    'exam_question_id' => $questionId,
                    'tag_hierarchy_id' => $tagId,
                    'inferred_area' => $inferredArea,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function createStudentAnswersForChunk(array $answers, array $questionIds, array $studentIds, int $academicYearId): void
    {
        // Procesar combinaciones únicas estudiante-pregunta
        $uniqueAnswers = [];

        foreach ($answers as $answer) {
            $studentId = $answer['student_id'];
            $questionNum = $answer['question_num'];

            if (! isset($studentIds[$studentId]) || ! isset($questionIds[$questionNum])) {
                continue;
            }

            $studentDbId = $studentIds[$studentId];
            $questionDbId = $questionIds[$questionNum];

            // Obtener enrollment_id
            $enrollment = \App\Models\Enrollment::where('student_id', $studentDbId)
                ->where('academic_year_id', $academicYearId)
                ->where('status', 'ACTIVE')
                ->first();

            if (! $enrollment) {
                continue;
            }

            $key = "{$enrollment->id}_{$questionDbId}";

            if (! isset($uniqueAnswers[$key])) {
                $uniqueAnswers[$key] = [
                    'exam_question_id' => $questionDbId,
                    'enrollment_id' => $enrollment->id,
                    'is_correct' => $answer['is_correct'],
                ];
            } elseif ($answer['is_correct']) {
                // Si encontramos una respuesta correcta, preferirla
                $uniqueAnswers[$key]['is_correct'] = true;
            }
        }

        // Insertar respuestas en batch
        if (! empty($uniqueAnswers)) {
            $answersToInsert = [];
            foreach ($uniqueAnswers as $answerData) {
                $answersToInsert[] = [
                    'exam_question_id' => $answerData['exam_question_id'],
                    'enrollment_id' => $answerData['enrollment_id'],
                    'is_correct' => $answerData['is_correct'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Insertar ignorando duplicados
            StudentAnswer::insertOrIgnore($answersToInsert);
        }
    }
}
