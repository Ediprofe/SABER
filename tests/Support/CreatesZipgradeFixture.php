<?php

namespace Tests\Support;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSession;
use App\Models\QuestionTag;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\TagHierarchy;
use App\Models\ZipgradeImport;

trait CreatesZipgradeFixture
{
    /**
     * @param  array{with_stats?:bool,with_imports?:bool,with_answers?:bool}  $options
     * @return array{
     *   year:AcademicYear,
     *   exam:Exam,
     *   student:Student,
     *   enrollment:Enrollment,
     *   sessions:array<int,ExamSession>
     * }
     */
    protected function createTwoSessionExamFixture(array $options = []): array
    {
        $withStats = $options['with_stats'] ?? true;
        $withImports = $options['with_imports'] ?? false;
        $withAnswers = $options['with_answers'] ?? true;

        $year = AcademicYear::create(['year' => 2026]);

        $exam = Exam::create([
            'academic_year_id' => $year->id,
            'name' => 'Simulacro Fixture',
            'type' => 'SIMULACRO',
            'date' => '2026-02-20',
        ]);

        $student = Student::create([
            'code' => 'STU-0001',
            'document_id' => '1001',
            'zipgrade_id' => 'ZG-1001',
            'email' => 'ana@example.com',
            'first_name' => 'Ana',
            'last_name' => 'Prueba',
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'grade' => 11,
            'group' => '11-1',
            'is_piar' => false,
            'status' => 'ACTIVE',
        ]);

        $sessions = [
            1 => ExamSession::create([
                'exam_id' => $exam->id,
                'session_number' => 1,
                'name' => 'Sesión 1',
                'zipgrade_quiz_name' => 'Fixture S1',
                'total_questions' => 0,
            ]),
            2 => ExamSession::create([
                'exam_id' => $exam->id,
                'session_number' => 2,
                'name' => 'Sesión 2',
                'zipgrade_quiz_name' => 'Fixture S2',
                'total_questions' => 0,
            ]),
        ];

        $areas = [
            'lectura' => 'Lectura',
            'matematicas' => 'Matemáticas',
            'sociales' => 'Sociales',
            'naturales' => 'Ciencias',
            'ingles' => 'Inglés',
        ];

        $tags = [];
        foreach ($areas as $areaKey => $areaName) {
            $tags[$areaKey] = TagHierarchy::create([
                'tag_name' => $areaName,
                'tag_type' => 'area',
                'parent_area' => null,
            ]);
        }

        $answersBySession = [
            1 => [
                'lectura' => true,
                'matematicas' => true,
                'sociales' => false,
                'naturales' => false,
                'ingles' => true,
            ],
            2 => [
                'lectura' => true,
                'matematicas' => false,
                'sociales' => true,
                'naturales' => false,
                'ingles' => true,
            ],
        ];

        foreach ($sessions as $sessionNumber => $session) {
            $questionNumber = 1;

            foreach ($areas as $areaKey => $areaName) {
                $question = ExamQuestion::create([
                    'exam_session_id' => $session->id,
                    'question_number' => $questionNumber,
                    'correct_answer' => $withStats ? 'A' : null,
                ]);

                QuestionTag::create([
                    'exam_question_id' => $question->id,
                    'tag_hierarchy_id' => $tags[$areaKey]->id,
                    'inferred_area' => $areaKey,
                ]);

                if ($withAnswers) {
                    StudentAnswer::create([
                        'exam_question_id' => $question->id,
                        'enrollment_id' => $enrollment->id,
                        'is_correct' => $answersBySession[$sessionNumber][$areaKey],
                    ]);
                }

                $questionNumber++;
            }

            $session->update(['total_questions' => count($areas)]);

            if ($withImports) {
                ZipgradeImport::create([
                    'exam_session_id' => $session->id,
                    'filename' => "fixture_session_{$sessionNumber}.csv",
                    'status' => 'completed',
                    'total_rows' => count($areas),
                ]);
            }
        }

        return [
            'year' => $year,
            'exam' => $exam,
            'student' => $student,
            'enrollment' => $enrollment,
            'sessions' => $sessions,
        ];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    protected function createTempCsvFile(array $headers, array $rows): string
    {
        $directory = storage_path('framework/testing/zipgrade');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $filePath = $directory.'/'.uniqid('zipgrade_', true).'.csv';
        $handle = fopen($filePath, 'wb');
        if (! $handle) {
            throw new \RuntimeException('No se pudo crear archivo temporal CSV para pruebas.');
        }

        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $filePath;
    }
}

