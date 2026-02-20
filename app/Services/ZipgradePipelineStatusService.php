<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamQuestion;

class ZipgradePipelineStatusService
{
    /**
     * @return array{session:int, has_data:bool, has_completed_import:bool, has_tagged_questions:bool, has_stats:bool, total_questions:int}
     */
    public function getSessionStatus(Exam $exam, int $sessionNumber): array
    {
        $session = $exam->sessions()
            ->with(['imports'])
            ->where('session_number', $sessionNumber)
            ->first();

        if (! $session) {
            return [
                'session' => $sessionNumber,
                'has_data' => false,
                'has_completed_import' => false,
                'has_tagged_questions' => false,
                'has_stats' => false,
                'total_questions' => 0,
            ];
        }

        $totalQuestions = $session->questions()->count();

        $hasCompletedImport = $session->imports()
            ->where('status', 'completed')
            ->exists();

        $hasTaggedQuestions = ExamQuestion::query()
            ->where('exam_session_id', $session->id)
            ->whereHas('questionTags')
            ->exists();

        $hasStats = ExamQuestion::query()
            ->where('exam_session_id', $session->id)
            ->whereNotNull('correct_answer')
            ->exists();

        return [
            'session' => $sessionNumber,
            'has_data' => $totalQuestions > 0 || $session->total_questions > 0,
            'has_completed_import' => $hasCompletedImport,
            'has_tagged_questions' => $hasTaggedQuestions,
            'has_stats' => $hasStats,
            'total_questions' => (int) $totalQuestions,
        ];
    }

    /**
     * @return array{ready:bool, tags_done:bool, stats_done:bool}
     */
    public function getPipelineStatus(Exam $exam): array
    {
        $s1 = $this->getSessionStatus($exam, 1);
        $s2 = $this->getSessionStatus($exam, 2);

        $s1TagsReady = $s1['has_completed_import'] || $s1['has_tagged_questions'];
        $s2TagsReady = $s2['has_completed_import'] || $s2['has_tagged_questions'];

        $tagsDone = $s1TagsReady && $s2TagsReady;
        $statsDone = $s1['has_stats'] && $s2['has_stats'];

        return [
            'ready' => $tagsDone && $statsDone,
            'tags_done' => $tagsDone,
            'stats_done' => $statsDone,
        ];
    }
}

