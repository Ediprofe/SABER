<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamSession;
use App\Models\StudentAnswer;
use App\Models\TagHierarchy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ZipgradeMetricsService
{
    /**
     * Mapeo de áreas a posibles nombres de tags
     */
    private array $areaMappings = [
        'lectura' => ['Lectura', 'Lectura Crítica', 'Lectura critica', 'lectura', 'Lectura critica'],
        'matematicas' => ['Matemáticas', 'Matemáticas', 'matematicas', 'Matemática', 'Mat'],
        'sociales' => ['Sociales', 'Ciencias Sociales', 'ciencias sociales', 'sociales', 'Social'],
        'naturales' => ['Ciencias', 'Naturales', 'Ciencias Naturales', 'ciencias naturales', 'naturales'],
        'ingles' => ['Inglés', 'Ingles', 'ingles', 'English'],
    ];

    /**
     * Calcula puntaje por tag para un estudiante.
     */
    public function getStudentTagScore(
        Enrollment $enrollment,
        Exam $exam,
        string $tagName
    ): float {
        $tag = TagHierarchy::where('tag_name', $tagName)->first();

        if (! $tag) {
            return 0.0;
        }

        // Obtener todas las preguntas de todas las sesiones del examen que tienen este tag
        $sessionIds = ExamSession::where('exam_id', $exam->id)->pluck('id');

        $questionIds = DB::table('exam_questions')
            ->join('question_tags', 'exam_questions.id', '=', 'question_tags.exam_question_id')
            ->whereIn('exam_questions.exam_session_id', $sessionIds)
            ->where('question_tags.tag_hierarchy_id', $tag->id)
            ->pluck('exam_questions.id');

        if ($questionIds->isEmpty()) {
            return 0.0;
        }

        $totalQuestions = $questionIds->count();

        $correctAnswers = StudentAnswer::where('enrollment_id', $enrollment->id)
            ->whereIn('exam_question_id', $questionIds)
            ->where('is_correct', true)
            ->count();

        return round(($correctAnswers / $totalQuestions) * 100, 2);
    }

    /**
     * Calcula puntaje por área para un estudiante (combinando sesiones).
     */
    public function getStudentAreaScore(
        Enrollment $enrollment,
        Exam $exam,
        string $area
    ): float {
        $sessionIds = ExamSession::where('exam_id', $exam->id)->pluck('id');

        // Buscar el tag de área correspondiente
        $possibleNames = $this->areaMappings[$area] ?? [$area];
        $areaTag = TagHierarchy::whereIn('tag_name', $possibleNames)
            ->where('tag_type', 'area')
            ->first();

        if (! $areaTag) {
            return 0.0;
        }

        // Obtener todas las preguntas de esta área
        $questionIds = DB::table('exam_questions')
            ->join('question_tags', 'exam_questions.id', '=', 'question_tags.exam_question_id')
            ->whereIn('exam_questions.exam_session_id', $sessionIds)
            ->where(function ($query) use ($areaTag, $area) {
                $query->where('question_tags.tag_hierarchy_id', $areaTag->id)
                    ->orWhere('question_tags.inferred_area', $area);
            })
            ->pluck('exam_questions.id')
            ->unique();

        if ($questionIds->isEmpty()) {
            return 0.0;
        }

        $totalQuestions = $questionIds->count();

        $correctAnswers = StudentAnswer::where('enrollment_id', $enrollment->id)
            ->whereIn('exam_question_id', $questionIds)
            ->where('is_correct', true)
            ->count();

        return round(($correctAnswers / $totalQuestions) * 100, 2);
    }

    /**
     * Calcula el puntaje global de un estudiante (0-500).
     */
    public function getStudentGlobalScore(
        Enrollment $enrollment,
        Exam $exam
    ): int {
        $lectura = $this->getStudentAreaScore($enrollment, $exam, 'lectura');
        $matematicas = $this->getStudentAreaScore($enrollment, $exam, 'matematicas');
        $sociales = $this->getStudentAreaScore($enrollment, $exam, 'sociales');
        $naturales = $this->getStudentAreaScore($enrollment, $exam, 'naturales');
        $ingles = $this->getStudentAreaScore($enrollment, $exam, 'ingles');

        // Fórmula: round(((L+M+S+N)*3 + I) / 13 * 5)
        $global = round((($lectura + $matematicas + $sociales + $naturales) * 3 + $ingles) / 13 * 5);

        return (int) $global;
    }

    /**
     * Obtiene todos los puntajes por área de un estudiante.
     */
    public function getStudentAllAreaScores(
        Enrollment $enrollment,
        Exam $exam
    ): array {
        return [
            'lectura' => $this->getStudentAreaScore($enrollment, $exam, 'lectura'),
            'matematicas' => $this->getStudentAreaScore($enrollment, $exam, 'matematicas'),
            'sociales' => $this->getStudentAreaScore($enrollment, $exam, 'sociales'),
            'naturales' => $this->getStudentAreaScore($enrollment, $exam, 'naturales'),
            'ingles' => $this->getStudentAreaScore($enrollment, $exam, 'ingles'),
            'global' => $this->getStudentGlobalScore($enrollment, $exam),
        ];
    }

    /**
     * Obtiene estadísticas por tag para todo el examen.
     */
    public function getTagStatistics(
        Exam $exam,
        string $tagName,
        ?array $filters = null
    ): array {
        $tag = TagHierarchy::where('tag_name', $tagName)->first();

        if (! $tag) {
            return [];
        }

        $sessionIds = ExamSession::where('exam_id', $exam->id)->pluck('id');

        $query = StudentAnswer::query()
            ->join('exam_questions', 'student_answers.exam_question_id', '=', 'exam_questions.id')
            ->join('question_tags', 'exam_questions.id', '=', 'question_tags.exam_question_id')
            ->join('enrollments', 'student_answers.enrollment_id', '=', 'enrollments.id')
            ->whereIn('exam_questions.exam_session_id', $sessionIds)
            ->where('question_tags.tag_hierarchy_id', $tag->id);

        // Aplicar filtros
        if (! empty($filters['group'])) {
            $query->where('enrollments.group', $filters['group']);
        }

        if (! empty($filters['piar_only'])) {
            $query->where('enrollments.is_piar', true);
        }

        if (! empty($filters['exclude_piar'])) {
            $query->where('enrollments.is_piar', false);
        }

        // Calcular estadísticas por estudiante
        $studentScores = $query->select(
            'student_answers.enrollment_id',
            DB::raw('SUM(CASE WHEN student_answers.is_correct THEN 1 ELSE 0 END) as correct_count'),
            DB::raw('COUNT(*) as total_count')
        )
            ->groupBy('student_answers.enrollment_id')
            ->get();

        $scores = $studentScores->map(function ($row) {
            return round(($row->correct_count / $row->total_count) * 100, 2);
        });

        if ($scores->isEmpty()) {
            return [
                'tag' => $tagName,
                'average' => 0,
                'std_dev' => 0,
                'min' => 0,
                'max' => 0,
                'count' => 0,
            ];
        }

        $average = $scores->average();
        $stdDev = $this->calculateStdDev($scores->toArray(), $average);

        return [
            'tag' => $tagName,
            'average' => round($average, 2),
            'std_dev' => round($stdDev, 2),
            'min' => $scores->min(),
            'max' => $scores->max(),
            'count' => $scores->count(),
        ];
    }

    /**
     * Obtiene comparativo PIAR vs No-PIAR por tag.
     */
    public function getTagPiarComparison(
        Exam $exam,
        string $tagName,
        ?array $filters = null
    ): array {
        return [
            'piar' => $this->getTagStatistics($exam, $tagName, array_merge($filters ?? [], ['piar_only' => true])),
            'no_piar' => $this->getTagStatistics($exam, $tagName, array_merge($filters ?? [], ['exclude_piar' => true])),
        ];
    }

    /**
     * Obtiene desglose por grupo para un tag.
     */
    public function getTagGroupComparison(
        Exam $exam,
        string $tagName,
        ?array $filters = null
    ): array {
        $tag = TagHierarchy::where('tag_name', $tagName)->first();

        if (! $tag) {
            return [];
        }

        $sessionIds = ExamSession::where('exam_id', $exam->id)->pluck('id');

        // Obtener grupos distintos
        $groups = DB::table('enrollments')
            ->where('academic_year_id', $exam->academic_year_id)
            ->where('status', 'active')
            ->distinct()
            ->pluck('group');

        $result = [];
        foreach ($groups as $group) {
            $result[$group] = $this->getTagStatistics($exam, $tagName, array_merge($filters ?? [], ['group' => $group]));
        }

        return $result;
    }

    /**
     * Infiere el área de una pregunta basándose en sus tags.
     */
    public function inferAreaFromTags(array $tagNames): ?string
    {
        // Primero buscar si hay un tag de tipo área
        foreach ($tagNames as $tagName) {
            $tag = TagHierarchy::where('tag_name', $tagName)
                ->where('tag_type', 'area')
                ->first();

            if ($tag) {
                // Encontrar el key del área
                foreach ($this->areaMappings as $area => $names) {
                    if (in_array($tagName, $names)) {
                        return $area;
                    }
                }
            }
        }

        // Si no hay tag de área, buscar tags hijos y ver su parent_area
        foreach ($tagNames as $tagName) {
            $tag = TagHierarchy::where('tag_name', $tagName)
                ->whereNotNull('parent_area')
                ->first();

            if ($tag && $tag->parent_area) {
                foreach ($this->areaMappings as $area => $names) {
                    if (in_array($tag->parent_area, $names)) {
                        return $area;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Calcula la desviación estándar.
     */
    private function calculateStdDev(array $values, float $mean): float
    {
        $count = count($values);
        if ($count < 2) {
            return 0;
        }

        $sum = 0;
        foreach ($values as $value) {
            $sum += pow($value - $mean, 2);
        }

        return sqrt($sum / ($count - 1));
    }

    /**
     * Obtiene estadísticas globales del examen.
     */
    public function getExamStatistics(
        Exam $exam,
        ?array $filters = null
    ): array {
        $enrollments = $this->getEnrollmentsForExam($exam, $filters);

        $globalScores = [];
        $areaScores = [
            'lectura' => [],
            'matematicas' => [],
            'sociales' => [],
            'naturales' => [],
            'ingles' => [],
        ];

        foreach ($enrollments as $enrollment) {
            $scores = $this->getStudentAllAreaScores($enrollment, $exam);
            $globalScores[] = $scores['global'];

            foreach ($areaScores as $area => &$values) {
                $values[] = $scores[$area];
            }
        }

        if (empty($globalScores)) {
            return [
                'global_average' => 0,
                'global_std_dev' => 0,
                'areas' => [],
                'total_students' => 0,
            ];
        }

        $globalAvg = array_sum($globalScores) / count($globalScores);
        $globalStdDev = $this->calculateStdDev($globalScores, $globalAvg);

        $areaStats = [];
        foreach ($areaScores as $area => $values) {
            if (! empty($values)) {
                $avg = array_sum($values) / count($values);
                $areaStats[$area] = [
                    'average' => round($avg, 2),
                    'std_dev' => round($this->calculateStdDev($values, $avg), 2),
                ];
            }
        }

        return [
            'global_average' => round($globalAvg, 2),
            'global_std_dev' => round($globalStdDev, 2),
            'areas' => $areaStats,
            'total_students' => count($globalScores),
        ];
    }

    /**
     * Obtiene el comparativo PIAR vs No-PIAR global.
     */
    public function getGlobalPiarComparison(
        Exam $exam
    ): array {
        return [
            'piar' => $this->getExamStatistics($exam, ['piar_only' => true]),
            'no_piar' => $this->getExamStatistics($exam, ['exclude_piar' => true]),
        ];
    }

    /**
     * Obtiene matrículas para un examen con filtros opcionales.
     */
    private function getEnrollmentsForExam(Exam $exam, ?array $filters = null): Collection
    {
        $query = Enrollment::query()
            ->where('academic_year_id', $exam->academic_year_id)
            ->where('status', 'active');

        if (! empty($filters['group'])) {
            $query->where('group', $filters['group']);
        }

        if (! empty($filters['piar_only'])) {
            $query->where('is_piar', true);
        }

        if (! empty($filters['exclude_piar'])) {
            $query->where('is_piar', false);
        }

        return $query->get();
    }
}
