<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSession;
use App\Models\QuestionTag;
use App\Models\StudentAnswer;
use App\Models\TagHierarchy;
use App\Models\TagNormalization;
use App\Models\ZipgradeImport;
use App\Support\AreaConfig;
use App\Support\TagClassificationConfig;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ZipgradeSessionImportService
{
    private const PREVIEW_CACHE_PREFIX = 'zipgrade:preview:';

    /**
     * @return array{token:string,summary:array<string,mixed>}
     */
    public function analyzeSessionUpload(Exam $exam, int $sessionNumber, string $blueprintRelativePath, string $responsesRelativePath): array
    {
        $blueprintPath = $this->storedUploadPath($blueprintRelativePath);
        $responsesPath = $this->storedUploadPath($responsesRelativePath);

        $blueprint = $this->parseBlueprint($blueprintPath);
        $responses = $this->parseResponses($responsesPath, false);
        $this->ensureEachQuestionHasExplicitAreaTag($blueprint);

        $enrollmentMap = $this->loadEnrollmentMap($exam);

        $uniqueStudentIds = [];
        foreach ($responses['students'] as $studentRow) {
            $studentId = $studentRow['zipgrade_id'];
            if ($studentId !== '') {
                $uniqueStudentIds[$studentId] = true;
            }
        }

        $matched = [];
        $unmatched = [];
        foreach (array_keys($uniqueStudentIds) as $studentId) {
            if (isset($enrollmentMap[$studentId])) {
                $matched[] = $studentId;
            } else {
                $unmatched[] = $studentId;
            }
        }

        sort($matched);
        sort($unmatched);

        $blueprintQuestionNumbers = array_keys($blueprint['questions']);
        $responsesQuestionNumbers = $responses['question_numbers'];

        $missingInBlueprint = array_values(array_diff($responsesQuestionNumbers, $blueprintQuestionNumbers));
        $missingInResponses = array_values(array_diff($blueprintQuestionNumbers, $responsesQuestionNumbers));
        $tagSuggestions = $this->buildTagSuggestions($blueprint);

        $summary = [
            'session_number' => $sessionNumber,
            'students_in_file' => count($uniqueStudentIds),
            'students_matched' => count($matched),
            'students_unmatched' => count($unmatched),
            'question_count_blueprint' => count($blueprintQuestionNumbers),
            'question_count_responses' => count($responsesQuestionNumbers),
            'area_question_counts' => $blueprint['area_counts'],
            'detected_tags' => $blueprint['tags'],
            'matched_student_ids' => array_slice($matched, 0, 25),
            'unmatched_student_ids' => array_slice($unmatched, 0, 25),
            'missing_questions_in_blueprint' => $missingInBlueprint,
            'missing_questions_in_responses' => $missingInResponses,
            'tag_suggestions' => $tagSuggestions,
            'classification_catalog' => TagClassificationConfig::catalogForUi(),
        ];

        $token = (string) Str::uuid();

        Cache::put(
            $this->previewCacheKey($token),
            [
                'exam_id' => $exam->id,
                'session_number' => $sessionNumber,
                'blueprint_relative_path' => $blueprintRelativePath,
                'responses_relative_path' => $responsesRelativePath,
                'summary' => $summary,
            ],
            now()->addHours(2)
        );

        return [
            'token' => $token,
            'summary' => $summary,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getPreview(Exam $exam, int $sessionNumber, string $token): ?array
    {
        $payload = Cache::get($this->previewCacheKey($token));

        if (! is_array($payload)) {
            return null;
        }

        if (($payload['exam_id'] ?? null) !== $exam->id) {
            return null;
        }

        if (($payload['session_number'] ?? null) !== $sessionNumber) {
            return null;
        }

        return $payload;
    }

    /**
     * @return array{questions_imported:int,answers_imported:int,students_matched:int,students_unmatched:int,tags_detected:int}
     */
    /**
     * @param  array<string,array{area?:string,type?:string}>  $tagClassifications
     */
    public function importFromPreviewToken(
        Exam $exam,
        int $sessionNumber,
        string $token,
        array $tagClassifications = [],
        bool $saveNormalizations = true
    ): array
    {
        $preview = $this->getPreview($exam, $sessionNumber, $token);

        if ($preview === null) {
            throw new \RuntimeException('La vista previa expiró o no es válida. Sube los archivos nuevamente.');
        }

        $blueprintPath = $this->storedUploadPath($preview['blueprint_relative_path']);
        $responsesPath = $this->storedUploadPath($preview['responses_relative_path']);

        $blueprint = $this->parseBlueprint($blueprintPath);
        $responses = $this->parseResponses($responsesPath, true);
        $this->ensureEachQuestionHasExplicitAreaTag($blueprint);

        $result = DB::transaction(function () use ($exam, $sessionNumber, $blueprint, $responses, $tagClassifications, $saveNormalizations) {
            $session = ExamSession::firstOrCreate(
                ['exam_id' => $exam->id, 'session_number' => $sessionNumber],
                ['name' => "Sesión {$sessionNumber}"]
            );

            $import = ZipgradeImport::create([
                'exam_session_id' => $session->id,
                'filename' => sprintf('pipeline_s%d_%s', $sessionNumber, now()->format('Ymd_His')),
                'status' => 'processing',
                'total_rows' => 0,
            ]);

            try {
                // Reimportacion completa por sesion: evita mezclar tags/respuestas de cargas anteriores.
                $this->clearSessionData($session);

                $questionNumbers = $this->collectQuestionNumbers($blueprint, $responses);
                $fallbackAnswers = $this->fallbackCorrectAnswersFromResponses($responses);

                $questionRows = [];
                foreach ($questionNumbers as $questionNumber) {
                    $blueprintQuestion = $blueprint['questions'][$questionNumber] ?? null;

                    $questionRows[] = [
                        'exam_session_id' => $session->id,
                        'question_number' => $questionNumber,
                        'correct_answer' => $blueprintQuestion['correct_answer'] ?? ($fallbackAnswers[$questionNumber] ?? null),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if ($questionRows !== []) {
                    ExamQuestion::upsert(
                        $questionRows,
                        ['exam_session_id', 'question_number'],
                        ['correct_answer', 'updated_at']
                    );
                }

                $questionsByNumber = ExamQuestion::query()
                    ->where('exam_session_id', $session->id)
                    ->whereIn('question_number', $questionNumbers)
                    ->get()
                    ->keyBy('question_number');

                $tagAreaHints = $this->buildTagAreaHints($blueprint);
                $tagIds = $this->ensureTagsExist($blueprint['tags'], $tagAreaHints, $tagClassifications);
                $this->storeTagNormalizations($tagClassifications, $saveNormalizations);

                $questionTags = [];
                foreach ($blueprint['questions'] as $questionNumber => $questionData) {
                    $question = $questionsByNumber->get($questionNumber);
                    if (! $question) {
                        continue;
                    }

                    foreach ($questionData['tags'] as $tagName) {
                        $tagId = $tagIds[$tagName] ?? null;
                        if (! $tagId) {
                            continue;
                        }

                        $questionTags[] = [
                            'exam_question_id' => $question->id,
                            'tag_hierarchy_id' => $tagId,
                            'inferred_area' => $questionData['inferred_area'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }

                if ($questionTags !== []) {
                    QuestionTag::upsert(
                        $questionTags,
                        ['exam_question_id', 'tag_hierarchy_id'],
                        ['inferred_area', 'updated_at']
                    );
                }

                $enrollmentMap = $this->loadEnrollmentMap($exam);

                $answerRows = [];
                $responseCounters = [];
                $studentsMatched = 0;
                $studentsUnmatched = 0;

                foreach ($responses['students'] as $studentRow) {
                    $studentZipgradeId = $studentRow['zipgrade_id'];

                    $enrollment = $studentZipgradeId !== '' ? ($enrollmentMap[$studentZipgradeId] ?? null) : null;
                    if (! $enrollment) {
                        $studentsUnmatched++;
                        continue;
                    }

                    $studentsMatched++;

                    foreach ($studentRow['answers'] as $questionNumber => $answerData) {
                        $question = $questionsByNumber->get((int) $questionNumber);
                        if (! $question) {
                            continue;
                        }

                        $answerRows[] = [
                            'exam_question_id' => $question->id,
                            'enrollment_id' => $enrollment['id'],
                            'is_correct' => $answerData['is_correct'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        $selectedAnswer = strtoupper((string) ($answerData['selected_answer'] ?? ''));
                        if (preg_match('/^[A-Z]$/', $selectedAnswer) === 1) {
                            $responseCounters[$question->id][$selectedAnswer] = ($responseCounters[$question->id][$selectedAnswer] ?? 0) + 1;
                        }
                    }
                }

                if ($answerRows !== []) {
                    StudentAnswer::upsert(
                        $answerRows,
                        ['exam_question_id', 'enrollment_id'],
                        ['is_correct', 'updated_at']
                    );
                }

                $this->applyQuestionResponseStats($questionsByNumber, $responseCounters);

                $session->total_questions = count($questionNumbers);
                $session->zipgrade_quiz_name = $responses['quiz_name'] ?: $session->zipgrade_quiz_name;
                $session->save();

                $import->update([
                    'status' => 'completed',
                    'total_rows' => count($responses['students']),
                ]);

                return [
                    'questions_imported' => count($questionNumbers),
                    'answers_imported' => count($answerRows),
                    'students_matched' => $studentsMatched,
                    'students_unmatched' => $studentsUnmatched,
                    'tags_detected' => count($blueprint['tags']),
                ];
            } catch (\Throwable $exception) {
                $import->update([
                    'status' => 'error',
                    'error_message' => $exception->getMessage(),
                ]);

                throw $exception;
            }
        });

        Cache::forget($this->previewCacheKey($token));

        Storage::disk('local')->delete([
            $preview['blueprint_relative_path'],
            $preview['responses_relative_path'],
        ]);

        return $result;
    }

    public function storedUploadPath(string $relativePath): string
    {
        $absolutePath = Storage::disk('local')->path($relativePath);

        if (! file_exists($absolutePath)) {
            throw new \RuntimeException('No se pudo acceder a uno de los archivos cargados. Intenta subirlo nuevamente.');
        }

        return $absolutePath;
    }

    private function previewCacheKey(string $token): string
    {
        return self::PREVIEW_CACHE_PREFIX.$token;
    }

    /**
     * @return array{questions:array<int,array{question_number:int,correct_answer:?string,tags:array<int,string>,inferred_area:?string}>,tags:array<int,string>,area_counts:array<string,int>}
     */
    private function parseBlueprint(string $filePath): array
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('No se pudo abrir el archivo blueprint.');
        }

        $questions = [];
        $allTags = [];

        $firstRow = fgetcsv($handle);
        if ($firstRow === false) {
            fclose($handle);

            return [
                'questions' => [],
                'tags' => [],
                'area_counts' => [],
            ];
        }

        $isHeader = $this->looksLikeBlueprintHeader($firstRow);
        if (! $isHeader) {
            $this->consumeBlueprintRow($firstRow, $questions, $allTags);
        }

        while (($row = fgetcsv($handle)) !== false) {
            $this->consumeBlueprintRow($row, $questions, $allTags);
        }

        fclose($handle);

        ksort($questions);

        $areaCounts = [];
        foreach ($questions as $questionNumber => $questionData) {
            $inferredArea = $this->inferAreaFromTags($questionData['tags']);
            $questions[$questionNumber]['inferred_area'] = $inferredArea;

            if ($inferredArea !== null) {
                $areaCounts[$inferredArea] = ($areaCounts[$inferredArea] ?? 0) + 1;
            }
        }

        ksort($areaCounts);
        sort($allTags);

        return [
            'questions' => $questions,
            'tags' => $allTags,
            'area_counts' => $areaCounts,
        ];
    }

    /**
     * @return array{students:array<int,array{zipgrade_id:string,answers:array<int,array{is_correct:bool,selected_answer:?string}>}>,question_numbers:array<int,int>,quiz_name:string}
     */
    private function parseResponses(string $filePath, bool $withAnswers): array
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('No se pudo abrir el archivo de respuestas.');
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            throw new \RuntimeException('El archivo de respuestas está vacío.');
        }

        $columnIndexes = [];
        foreach ($headers as $index => $header) {
            $columnIndexes[$this->normalizeHeader((string) $header)] = $index;
        }

        $studentIdIndex = $columnIndexes['studentid'] ?? null;
        if ($studentIdIndex === null) {
            fclose($handle);
            throw new \RuntimeException('No se encontró la columna StudentID en el archivo de respuestas.');
        }

        $quizNameIndex = $columnIndexes['quizname'] ?? null;

        $questionColumns = [];
        foreach ($headers as $index => $header) {
            $normalized = $this->normalizeHeader((string) $header);

            if (preg_match('/^stu(\d+)$/', $normalized, $match) === 1) {
                $questionColumns[(int) $match[1]]['stu'] = $index;
                continue;
            }

            if (preg_match('/^prikey(\d+)$/', $normalized, $match) === 1) {
                $questionColumns[(int) $match[1]]['prikey'] = $index;
                continue;
            }

            if (preg_match('/^points(\d+)$/', $normalized, $match) === 1) {
                $questionColumns[(int) $match[1]]['points'] = $index;
                continue;
            }

            if (preg_match('/^mark(\d+)$/', $normalized, $match) === 1) {
                $questionColumns[(int) $match[1]]['mark'] = $index;
            }
        }

        if ($questionColumns === []) {
            fclose($handle);
            throw new \RuntimeException('No se detectaron columnas StuN/PriKeyN/PointsN/MarkN en el archivo de respuestas.');
        }

        ksort($questionColumns);
        $questionNumbers = array_keys($questionColumns);

        $students = [];
        $quizName = null;

        while (($row = fgetcsv($handle)) !== false) {
            $zipgradeId = trim((string) ($row[$studentIdIndex] ?? ''));
            if ($zipgradeId === '') {
                continue;
            }

            if ($quizName === null && $quizNameIndex !== null) {
                $quizName = trim((string) ($row[$quizNameIndex] ?? ''));
            }

            $studentPayload = [
                'zipgrade_id' => $zipgradeId,
                'answers' => [],
            ];

            if ($withAnswers) {
                foreach ($questionColumns as $questionNumber => $indexes) {
                    $mark = strtoupper(trim((string) ($row[$indexes['mark'] ?? -1] ?? '')));
                    $points = trim((string) ($row[$indexes['points'] ?? -1] ?? ''));
                    $selected = strtoupper(trim((string) ($row[$indexes['stu'] ?? -1] ?? '')));
                    $primary = strtoupper(trim((string) ($row[$indexes['prikey'] ?? -1] ?? '')));

                    if ($mark === '' && $points === '' && $selected === '' && $primary === '') {
                        continue;
                    }

                    $studentPayload['answers'][$questionNumber] = [
                        'is_correct' => $this->resolveCorrectness($mark, $points, $selected, $primary),
                        'selected_answer' => preg_match('/^[A-Z]$/', $selected) === 1 ? $selected : null,
                    ];
                }
            }

            $students[] = $studentPayload;
        }

        fclose($handle);

        return [
            'students' => $students,
            'question_numbers' => $questionNumbers,
            'quiz_name' => $quizName ?? '',
        ];
    }

    /**
     * @param  array<int,string|null>  $row
     * @param  array<int,array{question_number:int,correct_answer:?string,tags:array<int,string>,inferred_area:?string}>  $questions
     * @param  array<int,string>  $allTags
     */
    private function consumeBlueprintRow(array $row, array &$questions, array &$allTags): void
    {
        $questionNumber = (int) trim((string) ($row[1] ?? '0'));
        if ($questionNumber <= 0) {
            return;
        }

        $response = strtoupper(trim((string) ($row[2] ?? '')));
        $tags = [];
        foreach (array_slice($row, 4) as $tagRaw) {
            $tag = trim((string) $tagRaw);
            if ($tag === '') {
                continue;
            }

            $tags[$tag] = $tag;
            $allTags[$tag] = $tag;
        }

        if (! isset($questions[$questionNumber])) {
            $questions[$questionNumber] = [
                'question_number' => $questionNumber,
                'correct_answer' => null,
                'tags' => [],
                'inferred_area' => null,
            ];
        }

        if ($questions[$questionNumber]['correct_answer'] === null && preg_match('/^[A-Z]$/', $response) === 1) {
            $questions[$questionNumber]['correct_answer'] = $response;
        }

        $existingTags = array_fill_keys($questions[$questionNumber]['tags'], true);
        foreach (array_keys($tags) as $tagName) {
            if (! isset($existingTags[$tagName])) {
                $questions[$questionNumber]['tags'][] = $tagName;
            }
        }
    }

    /**
     * @param  array<int,string|null>  $firstRow
     */
    private function looksLikeBlueprintHeader(array $firstRow): bool
    {
        $first = Str::lower(trim((string) ($firstRow[0] ?? '')));
        $second = Str::lower(trim((string) ($firstRow[1] ?? '')));

        return str_contains($first, 'key') && str_contains($second, 'question');
    }

    private function normalizeHeader(string $header): string
    {
        return Str::of($header)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]/', '')
            ->toString();
    }

    /**
     * @param  array<int,string>  $tags
     */
    private function inferAreaFromTags(array $tags): ?string
    {
        foreach ($tags as $tag) {
            $normalizedTag = $this->normalizedText($tag);

            foreach (AreaConfig::AREA_MAPPINGS as $areaKey => $aliases) {
                foreach ($aliases as $alias) {
                    $normalizedAlias = $this->normalizedText($alias);

                    if ($normalizedAlias === $normalizedTag) {
                        return $areaKey;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array{questions:array<int,array{question_number:int,correct_answer:?string,tags:array<int,string>,inferred_area:?string}>}  $blueprint
     */
    private function ensureEachQuestionHasExplicitAreaTag(array $blueprint): void
    {
        $missingAreaQuestions = [];

        foreach ($blueprint['questions'] as $questionNumber => $questionData) {
            $hasAreaTag = false;
            foreach ($questionData['tags'] as $tagName) {
                if (AreaConfig::normalizeAreaName($tagName) !== null) {
                    $hasAreaTag = true;
                    break;
                }
            }

            if (! $hasAreaTag) {
                $missingAreaQuestions[] = (int) $questionNumber;
            }
        }

        if ($missingAreaQuestions !== []) {
            sort($missingAreaQuestions);
            $preview = implode(', ', array_slice($missingAreaQuestions, 0, 15));
            if (count($missingAreaQuestions) > 15) {
                $preview .= ', ...';
            }

            throw ValidationException::withMessages([
                'blueprint' => [
                    'Cada pregunta debe incluir explícitamente el tag de área (Lectura, Matemáticas, Sociales, Naturales o Inglés). '
                    .'Preguntas sin tag de área: '.$preview,
                ],
            ]);
        }
    }

    private function normalizedText(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }

    /**
     * @return array<string,array{id:int}>
     */
    private function loadEnrollmentMap(Exam $exam): array
    {
        $enrollments = Enrollment::query()
            ->where('academic_year_id', $exam->academic_year_id)
            ->where('status', 'ACTIVE')
            ->with('student:id,zipgrade_id')
            ->get();

        $map = [];
        foreach ($enrollments as $enrollment) {
            $zipgradeId = trim((string) ($enrollment->student?->zipgrade_id ?? ''));
            if ($zipgradeId === '' || isset($map[$zipgradeId])) {
                continue;
            }

            $map[$zipgradeId] = ['id' => $enrollment->id];
        }

        return $map;
    }

    /**
     * @param  array{questions:array<int,array{question_number:int,correct_answer:?string,tags:array<int,string>,inferred_area:?string}>}  $blueprint
     * @param  array{question_numbers:array<int,int>}  $responses
     * @return array<int,int>
     */
    private function collectQuestionNumbers(array $blueprint, array $responses): array
    {
        $numbers = array_unique(array_merge(
            array_keys($blueprint['questions']),
            $responses['question_numbers']
        ));

        sort($numbers);

        return array_values(array_map(fn ($n) => (int) $n, $numbers));
    }

    /**
     * @param  array{students:array<int,array{answers:array<int,array{is_correct:bool}>}>,question_numbers:array<int,int>}  $responses
     * @return array<int,string>
     */
    private function fallbackCorrectAnswersFromResponses(array $responses): array
    {
        // El pipeline principal usa blueprint para respuestas correctas.
        // Si una pregunta no está en blueprint, se deja null para no inferir incorrectamente.
        return [];
    }

    /**
     * @param  array{questions:array<int,array{question_number:int,correct_answer:?string,tags:array<int,string>,inferred_area:?string}>,tags:array<int,string>}  $blueprint
     * @return array<int,array{tag:string,suggested_area:string,suggested_area_label:string,suggested_type:string,suggested_type_label:string,source:string}>
     */
    private function buildTagSuggestions(array $blueprint): array
    {
        $areaHints = $this->buildTagAreaHints($blueprint);
        $tagUsage = $this->buildTagUsageContext($blueprint);
        $suggestions = [];

        foreach ($blueprint['tags'] as $tagName) {
            $suggested = $this->resolveSuggestedClassification($tagName, $areaHints[$tagName] ?? null);
            $areaKey = TagClassificationConfig::normalizeAreaKey($suggested['area'] ?? null);
            $type = (string) ($suggested['type'] ?? TagClassificationConfig::defaultTypeForArea($areaKey));
            $heuristicType = $this->suggestTagType(
                $tagName,
                $areaKey === '__unclassified' ? null : $areaKey
            );

            if (! TagClassificationConfig::isValidTypeForArea($areaKey, $type)) {
                $type = TagClassificationConfig::isValidTypeForArea($areaKey, $heuristicType)
                    ? $heuristicType
                    : TagClassificationConfig::defaultTypeForArea($areaKey);
            }

            // "Sociales" puede venir como alias dimensional dentro de Ciencias Sociales.
            // Si coexiste con otro tag explícito de área, no se fuerza como tag de área.
            if ($this->isSocialesAliasTag($tagName)) {
                $usage = $tagUsage[$tagName] ?? ['with_other_area_tags' => 0, 'as_only_area_tag' => 0];
                if (($usage['with_other_area_tags'] ?? 0) > 0 && $type === 'area') {
                    $type = TagClassificationConfig::defaultTypeForArea($areaKey);
                }
            }

            $suggestions[] = [
                'tag' => $tagName,
                'suggested_area' => $areaKey,
                'suggested_area_label' => TagClassificationConfig::labelForArea($areaKey),
                'suggested_type' => $type,
                'suggested_type_label' => TagClassificationConfig::labelForType($type),
                'source' => $suggested['source'] ?? 'heuristic',
            ];
        }

        usort(
            $suggestions,
            fn (array $a, array $b): int => strcmp($a['tag'], $b['tag'])
        );

        return $suggestions;
    }

    /**
     * @param  array{questions:array<int,array{question_number:int,correct_answer:?string,tags:array<int,string>,inferred_area:?string}>}  $blueprint
     * @return array<string,string>
     */
    private function buildTagAreaHints(array $blueprint): array
    {
        $votes = [];

        foreach ($blueprint['questions'] as $question) {
            $inferredArea = $question['inferred_area'] ?? null;

            foreach ($question['tags'] as $tagName) {
                if ($inferredArea !== null && $inferredArea !== '') {
                    $votes[$tagName][$inferredArea] = ($votes[$tagName][$inferredArea] ?? 0) + 1;
                }
            }
        }

        $hints = [];
        foreach ($votes as $tagName => $areas) {
            arsort($areas);
            $hints[$tagName] = array_key_first($areas);
        }

        foreach ($blueprint['tags'] as $tagName) {
            if (! isset($hints[$tagName])) {
                $hints[$tagName] = $this->inferAreaFromTags([$tagName]);
            }
        }

        return $hints;
    }

    /**
     * @param  array{questions:array<int,array{question_number:int,correct_answer:?string,tags:array<int,string>,inferred_area:?string}>}  $blueprint
     * @return array<string,array{with_other_area_tags:int,as_only_area_tag:int}>
     */
    private function buildTagUsageContext(array $blueprint): array
    {
        $context = [];

        foreach ($blueprint['questions'] as $questionData) {
            $tags = $questionData['tags'] ?? [];
            $areaTags = [];

            foreach ($tags as $tagName) {
                if (AreaConfig::normalizeAreaName($tagName) !== null) {
                    $areaTags[] = $tagName;
                }
            }

            $areaCount = count($areaTags);
            if ($areaCount === 0) {
                continue;
            }

            foreach ($areaTags as $tagName) {
                if (! isset($context[$tagName])) {
                    $context[$tagName] = [
                        'with_other_area_tags' => 0,
                        'as_only_area_tag' => 0,
                    ];
                }

                if ($areaCount > 1) {
                    $context[$tagName]['with_other_area_tags']++;
                } else {
                    $context[$tagName]['as_only_area_tag']++;
                }
            }
        }

        return $context;
    }

    /**
     * @param  array<int,string>  $tagNames
     * @param  array<string,string>  $areaHints
     * @param  array<string,array{area?:string,type?:string}>  $tagClassifications
     * @return array<string,int>
     */
    private function ensureTagsExist(array $tagNames, array $areaHints, array $tagClassifications = []): array
    {
        if ($tagNames === []) {
            return [];
        }

        $existing = TagHierarchy::query()
            ->whereIn('tag_name', $tagNames)
            ->get()
            ->keyBy('tag_name');

        $tagIds = [];

        foreach ($tagNames as $tagName) {
            $classification = $this->sanitizeClassification(
                $tagName,
                $tagClassifications[$tagName] ?? null,
                $areaHints[$tagName] ?? null
            );

            if (isset($existing[$tagName])) {
                $tag = $existing[$tagName];

                if ($classification !== null) {
                    $newType = $classification['type'];
                    $newParentArea = $classification['parent_area'];

                    if ($tag->tag_type !== $newType || $tag->parent_area !== $newParentArea) {
                        $tag->update([
                            'tag_type' => $newType,
                            'parent_area' => $newParentArea,
                        ]);
                    }
                }

                $tagIds[$tagName] = $tag->id;
                continue;
            }

            if ($classification === null) {
                $suggested = $this->resolveSuggestedClassification($tagName, $areaHints[$tagName] ?? null);
                $classification = $this->sanitizeClassification(
                    $tagName,
                    [
                        'area' => (string) ($suggested['area'] ?? ''),
                        'type' => (string) ($suggested['type'] ?? ''),
                    ],
                    $areaHints[$tagName] ?? null
                );
            }

            if ($classification === null) {
                $classification = [
                    'area' => '__unclassified',
                    'type' => 'componente',
                    'parent_area' => null,
                ];
            }

            $created = TagHierarchy::create([
                'tag_name' => $tagName,
                'tag_type' => $classification['type'],
                'parent_area' => $classification['parent_area'],
            ]);

            $tagIds[$tagName] = $created->id;
        }

        return $tagIds;
    }

    /**
     * @param  array{area?:string,type?:string}|null  $classification
     * @return array{area:string,type:string,parent_area:?string}|null
     */
    private function sanitizeClassification(string $tagName, ?array $classification, ?string $fallbackArea): ?array
    {
        if (! is_array($classification)) {
            return null;
        }

        $rawArea = trim((string) ($classification['area'] ?? ''));
        $rawType = trim((string) ($classification['type'] ?? ''));

        $fallbackKey = TagClassificationConfig::normalizeAreaKey($fallbackArea);
        $areaKey = $rawArea !== '' ? TagClassificationConfig::normalizeAreaKey($rawArea) : $fallbackKey;

        if ($areaKey === '__unclassified' && $fallbackKey !== '__unclassified') {
            $areaKey = $fallbackKey;
        }

        $type = $rawType !== '' ? $rawType : TagClassificationConfig::defaultTypeForArea($areaKey);
        if (! TagClassificationConfig::isValidTypeForArea($areaKey, $type)) {
            $type = TagClassificationConfig::defaultTypeForArea($areaKey);
        }

        if ($this->isStrictAreaTagName($tagName)) {
            $type = 'area';
            if ($areaKey === '__unclassified') {
                $areaKey = TagClassificationConfig::normalizeAreaKey($this->inferAreaFromTags([$tagName]));
            }
        }

        return [
            'area' => $areaKey,
            'type' => $type,
            'parent_area' => $type === 'area' || $areaKey === '__unclassified'
                ? null
                : AreaConfig::getLabel($areaKey),
        ];
    }

    /**
     * @return array{area:?string,type:string,source:string}
     */
    private function resolveSuggestedClassification(string $tagName, ?string $inferredAreaHint): array
    {
        $normalization = TagNormalization::findByCsvName($tagName);
        if ($normalization) {
            $areaFromNormalization = $this->resolveAreaFromHierarchyData(
                $normalization->tag_type,
                $normalization->parent_area,
                $normalization->tag_system_name
            );

            $effectiveArea = $inferredAreaHint ?? $areaFromNormalization;
            $heuristicType = $this->suggestTagType($tagName, $effectiveArea);

            if ($effectiveArea !== null && $inferredAreaHint !== null && $areaFromNormalization !== null && $effectiveArea !== $areaFromNormalization) {
                return [
                    'area' => $effectiveArea,
                    'type' => $heuristicType,
                    'source' => 'normalization_conflict_resolved_by_hint',
                ];
            }

            if ($this->shouldPreferHeuristicType($tagName, $effectiveArea, $normalization->tag_type, $heuristicType)) {
                return [
                    'area' => $effectiveArea,
                    'type' => $heuristicType,
                    'source' => 'normalization_type_adjusted_by_heuristic',
                ];
            }

            return [
                'area' => $effectiveArea,
                'type' => $normalization->tag_type,
                'source' => 'normalization',
            ];
        }

        $existingTag = TagHierarchy::query()->where('tag_name', $tagName)->first();
        if ($existingTag) {
            $areaFromExisting = $this->resolveAreaFromHierarchyData(
                $existingTag->tag_type,
                $existingTag->parent_area,
                $existingTag->tag_name
            );

            $effectiveArea = $inferredAreaHint ?? $areaFromExisting;
            $heuristicType = $this->suggestTagType($tagName, $effectiveArea);

            if ($effectiveArea !== null && $inferredAreaHint !== null && $areaFromExisting !== null && $effectiveArea !== $areaFromExisting) {
                return [
                    'area' => $effectiveArea,
                    'type' => $heuristicType,
                    'source' => 'existing_conflict_resolved_by_hint',
                ];
            }

            if ($this->shouldPreferHeuristicType($tagName, $effectiveArea, $existingTag->tag_type, $heuristicType)) {
                return [
                    'area' => $effectiveArea,
                    'type' => $heuristicType,
                    'source' => 'existing_type_adjusted_by_heuristic',
                ];
            }

            return [
                'area' => $effectiveArea,
                'type' => $existingTag->tag_type,
                'source' => 'existing',
            ];
        }

        $area = $inferredAreaHint ?? $this->inferAreaFromTags([$tagName]);

        return [
            'area' => $area,
            'type' => $this->suggestTagType($tagName, $area),
            'source' => 'heuristic',
        ];
    }

    private function shouldPreferHeuristicType(string $tagName, ?string $area, string $storedType, string $heuristicType): bool
    {
        if ($area === null || $heuristicType === $storedType) {
            return false;
        }

        $areaKey = TagClassificationConfig::normalizeAreaKey($area);
        if (! TagClassificationConfig::isValidTypeForArea($areaKey, $storedType)) {
            return true;
        }

        if ($storedType === 'area' && ! $this->isStrictAreaTagName($tagName)) {
            return TagClassificationConfig::isValidTypeForArea($areaKey, $heuristicType);
        }

        // En ingles, tags como Lexical/Comunicativa/Pragmatica/Gramatical deben ir a Competencia.
        if ($areaKey === 'ingles' && $storedType === 'parte' && $heuristicType === 'competencia') {
            return ! $this->hasAnyKeyword($this->normalizedText($tagName), ['parte']);
        }

        return false;
    }

    private function resolveAreaFromHierarchyData(string $tagType, ?string $parentArea, ?string $tagName): ?string
    {
        if ($tagType === 'area') {
            return $tagName !== null ? AreaConfig::normalizeAreaName($tagName) : null;
        }

        if ($parentArea !== null && $parentArea !== '') {
            return AreaConfig::normalizeAreaName($parentArea);
        }

        return null;
    }

    private function suggestTagType(string $tagName, ?string $areaKey): string
    {
        if ($this->isStrictAreaTagName($tagName)) {
            return 'area';
        }

        $normalizedTag = $this->normalizedText($tagName);

        if ($areaKey === 'ingles') {
            if ($this->hasAnyKeyword($normalizedTag, ['parte'])) {
                return 'parte';
            }

            if ($this->hasAnyKeyword($normalizedTag, [
                'lexical',
                'pragmatica',
                'comunicativa',
                'gramatical',
                'gramatica lexical',
                'comprension de texto',
                'literal',
                'inferencial',
                'lectura',
            ])) {
                return 'competencia';
            }

            return 'parte';
        }

        if ($areaKey === 'lectura') {
            if ($this->hasAnyKeyword($normalizedTag, ['literal', 'inferencial', 'critico', 'critica'])) {
                return 'nivel_lectura';
            }

            if ($this->hasAnyKeyword($normalizedTag, ['texto', 'continuo', 'discontinuo', 'narrativo', 'argumentativo', 'expositivo', 'literario'])) {
                return 'tipo_texto';
            }

            if ($this->isCompetencyTagForArea($normalizedTag, 'lectura')) {
                return 'competencia';
            }

            return 'componente';
        }

        if ($areaKey !== null && $this->isCompetencyTagForArea($normalizedTag, $areaKey)) {
            return 'competencia';
        }

        return $this->isCompetencyTag($normalizedTag) ? 'competencia' : 'componente';
    }

    private function isStrictAreaTagName(string $tagName): bool
    {
        $normalizedTag = $this->normalizedText($tagName);
        return in_array($normalizedTag, [
            $this->normalizedText('Lectura'),
            $this->normalizedText('Lectura Critica'),
            $this->normalizedText('Lectura Crítica'),
            $this->normalizedText('Matematicas'),
            $this->normalizedText('Matemáticas'),
            $this->normalizedText('Ciencias Sociales'),
            $this->normalizedText('Ciencias Naturales'),
            $this->normalizedText('Ingles'),
            $this->normalizedText('Inglés'),
            $this->normalizedText('English'),
        ], true);
    }

    private function isAreaTagName(string $tagName): bool
    {
        if ($this->isStrictAreaTagName($tagName)) {
            return true;
        }

        $normalizedTag = $this->normalizedText($tagName);

        // Alias cortos: útiles para inferencia/validación, pero no se fuerzan como "area".
        return in_array($normalizedTag, [
            $this->normalizedText('Sociales'),
            $this->normalizedText('Naturales'),
        ], true);
    }

    private function isSocialesAliasTag(string $tagName): bool
    {
        return $this->normalizedText($tagName) === $this->normalizedText('Sociales');
    }

    private function isCompetencyTag(string $normalizedTag): bool
    {
        return $this->hasAnyKeyword($normalizedTag, [
            'competencia',
            'interpretacion',
            'analisis',
            'formulacion',
            'argumentacion',
            'indagacion',
            'explicacion',
            'pensamiento',
            'uso comprensivo',
        ]);
    }

    private function isCompetencyTagForArea(string $normalizedTag, string $areaKey): bool
    {
        $keywords = match ($areaKey) {
            'matematicas' => [
                'formulacion',
                'interpretacion',
                'argumentacion',
                'competencia',
            ],
            'sociales' => [
                'pensamiento social',
                'pensamiento sistemico',
                'interpretacion y analisis de perspectivas',
                'competencia',
            ],
            'naturales' => [
                'indagacion',
                'explicacion',
                'uso comprensivo',
                'competencia',
            ],
            'lectura' => [
                'competencia',
                'interpretacion',
                'analisis',
            ],
            default => [],
        };

        if ($keywords !== [] && $this->hasAnyKeyword($normalizedTag, $keywords)) {
            return true;
        }

        return $this->isCompetencyTag($normalizedTag);
    }

    /**
     * @param  array<int,string>  $keywords
     */
    private function hasAnyKeyword(string $value, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($value, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,array{area?:string,type?:string}>  $tagClassifications
     */
    private function storeTagNormalizations(array $tagClassifications, bool $saveNormalizations): void
    {
        if (! $saveNormalizations || $tagClassifications === []) {
            return;
        }

        foreach ($tagClassifications as $tagName => $classification) {
            if (! is_string($tagName) || trim($tagName) === '' || ! is_array($classification)) {
                continue;
            }

            $normalizedArea = TagClassificationConfig::normalizeAreaKey((string) ($classification['area'] ?? ''));
            $type = (string) ($classification['type'] ?? TagClassificationConfig::defaultTypeForArea($normalizedArea));

            if (! TagClassificationConfig::isValidTypeForArea($normalizedArea, $type)) {
                $type = TagClassificationConfig::defaultTypeForArea($normalizedArea);
            }

            $parentArea = $type === 'area' || $normalizedArea === '__unclassified'
                ? null
                : AreaConfig::getLabel($normalizedArea);

            TagNormalization::storeNormalization([
                'tag_csv_name' => $tagName,
                'tag_system_name' => $tagName,
                'tag_type' => $type,
                'parent_area' => $parentArea,
                'is_active' => true,
            ]);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int,\App\Models\ExamQuestion>  $questionsByNumber
     * @param  array<int,array<string,int>>  $responseCounters
     */
    private function applyQuestionResponseStats($questionsByNumber, array $responseCounters): void
    {
        foreach ($questionsByNumber as $question) {
            $stats = $this->buildQuestionResponseStats($responseCounters[$question->id] ?? []);
            ExamQuestion::query()
                ->whereKey($question->id)
                ->update(array_merge($stats, ['updated_at' => now()]));
        }
    }

    private function clearSessionData(ExamSession $session): void
    {
        $questionIds = ExamQuestion::query()
            ->where('exam_session_id', $session->id)
            ->pluck('id');

        if ($questionIds->isNotEmpty()) {
            StudentAnswer::query()->whereIn('exam_question_id', $questionIds)->delete();
            QuestionTag::query()->whereIn('exam_question_id', $questionIds)->delete();
        }

        ExamQuestion::query()
            ->where('exam_session_id', $session->id)
            ->delete();
    }

    /**
     * @param  array<string,int>  $countsByOption
     * @return array{
     *   response_1:?string,response_1_pct:float,
     *   response_2:?string,response_2_pct:float,
     *   response_3:?string,response_3_pct:float,
     *   response_4:?string,response_4_pct:float
     * }
     */
    private function buildQuestionResponseStats(array $countsByOption): array
    {
        $clean = [];
        foreach ($countsByOption as $option => $count) {
            $normalizedOption = strtoupper((string) $option);
            if (preg_match('/^[A-Z]$/', $normalizedOption) !== 1) {
                continue;
            }

            $clean[$normalizedOption] = (int) $count;
        }

        if ($clean !== []) {
            uksort($clean, function (string $left, string $right) use ($clean): int {
                if ($clean[$left] !== $clean[$right]) {
                    return $clean[$right] <=> $clean[$left];
                }

                return strcmp($left, $right);
            });
        }

        $total = array_sum($clean);
        $entries = array_slice($clean, 0, 4, true);

        $result = [
            'response_1' => null,
            'response_1_pct' => 0.0,
            'response_2' => null,
            'response_2_pct' => 0.0,
            'response_3' => null,
            'response_3_pct' => 0.0,
            'response_4' => null,
            'response_4_pct' => 0.0,
        ];

        $index = 1;
        foreach ($entries as $option => $count) {
            $result["response_{$index}"] = $option;
            $result["response_{$index}_pct"] = $total > 0 ? round(($count / $total) * 100, 2) : 0.0;
            $index++;
        }

        return $result;
    }

    private function resolveCorrectness(string $mark, string $pointsRaw, string $selected, string $primary): bool
    {
        if ($mark === 'C') {
            return true;
        }

        if ($mark === 'X') {
            return false;
        }

        $points = is_numeric(str_replace(',', '.', $pointsRaw))
            ? (float) str_replace(',', '.', $pointsRaw)
            : 0.0;

        if ($points > 0) {
            return true;
        }

        if ($selected !== '' && $primary !== '') {
            return $selected === $primary;
        }

        return false;
    }
}
