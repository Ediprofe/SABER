<?php

namespace Tests\Feature\Zipgrade;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSession;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\TagHierarchy;
use App\Models\ZipgradeImport;
use App\Services\ZipgradeImportPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;
use Tests\Support\CreatesZipgradeFixture;
use Tests\TestCase;

class ZipgradeImportPipelineServiceTest extends TestCase
{
    use CreatesZipgradeFixture;
    use RefreshDatabase;

    public function test_tags_import_is_idempotent_and_updates_existing_answers(): void
    {
        $year = AcademicYear::create(['year' => 2026]);
        $exam = Exam::create([
            'academic_year_id' => $year->id,
            'name' => 'Simulacro Idempotencia',
            'type' => 'SIMULACRO',
            'date' => '2026-02-20',
        ]);

        TagHierarchy::create([
            'tag_name' => 'Lectura',
            'tag_type' => 'area',
            'parent_area' => null,
        ]);

        $student = Student::create([
            'code' => 'STU-IDEM-1',
            'document_id' => '2001',
            'zipgrade_id' => 'ZG-2001',
            'first_name' => 'Bruno',
            'last_name' => 'Idempotente',
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'grade' => 11,
            'group' => '11-1',
            'is_piar' => false,
            'status' => 'ACTIVE',
        ]);

        $headers = [
            'StudentID',
            'StudentFirstName',
            'StudentLastName',
            'QuestionNumber',
            'Tag',
            'EarnedPoints',
            'QuizName',
        ];

        $service = app(ZipgradeImportPipelineService::class);

        $file1 = $this->createTempCsvFile($headers, [
            ['ZG-2001', 'Bruno', 'Idempotente', 1, 'Lectura', 1, 'S1'],
            ['ZG-2001', 'Bruno', 'Idempotente', 2, 'Lectura', 0, 'S1'],
        ]);

        $file2 = $this->createTempCsvFile($headers, [
            ['ZG-2001', 'Bruno', 'Idempotente', 1, 'Lectura', 1, 'S1'],
            ['ZG-2001', 'Bruno', 'Idempotente', 2, 'Lectura', 1, 'S1'],
        ]);

        try {
            $service->processZipgradeImport($exam, 1, $file1);

            $session = ExamSession::where('exam_id', $exam->id)
                ->where('session_number', 1)
                ->firstOrFail();

            $this->assertSame(2, $session->questions()->count());
            $this->assertSame(2, $session->total_questions);
            $this->assertSame(2, $session->questions()->withCount('questionTags')->get()->sum('question_tags_count'));
            $this->assertSame(2, StudentAnswer::count());

            $question2 = ExamQuestion::where('exam_session_id', $session->id)
                ->where('question_number', 2)
                ->firstOrFail();
            $this->assertFalse(
                (bool) StudentAnswer::where('exam_question_id', $question2->id)
                    ->where('enrollment_id', $enrollment->id)
                    ->firstOrFail()
                    ->is_correct
            );

            $service->processZipgradeImport($exam, 1, $file2);

            $this->assertSame(2, $session->questions()->count());
            $this->assertSame(2, $session->questions()->withCount('questionTags')->get()->sum('question_tags_count'));
            $this->assertSame(2, StudentAnswer::count());

            $this->assertTrue(
                (bool) StudentAnswer::where('exam_question_id', $question2->id)
                    ->where('enrollment_id', $enrollment->id)
                    ->firstOrFail()
                    ->is_correct
            );

            $this->assertSame(2, ZipgradeImport::where('exam_session_id', $session->id)->where('status', 'completed')->count());
        } finally {
            @unlink($file1);
            @unlink($file2);
        }
    }

    public function test_stats_import_records_tracking_and_resets_heading_formatter(): void
    {
        $fixture = $this->createTwoSessionExamFixture([
            'with_stats' => false,
            'with_imports' => false,
        ]);

        $exam = $fixture['exam'];
        $session = $fixture['sessions'][1];

        $question = ExamQuestion::where('exam_session_id', $session->id)
            ->where('question_number', 1)
            ->firstOrFail();

        $headers = [
            'Question Number',
            'Primary Answer',
            'Response 1',
            'Response 1 %',
            'Response 2',
            'Response 2 %',
            'Response 3',
            'Response 3 %',
            'Response 4',
            'Response 4 %',
        ];

        $statsFile = $this->createTempCsvFile($headers, [
            [1, 'A', 'A', '70.0', 'B', '20.0', 'C', '10.0', 'D', '0.0'],
        ]);

        try {
            HeadingRowFormatter::default('slug');

            $processed = app(ZipgradeImportPipelineService::class)
                ->processZipgradeStatsImport($exam, 1, $statsFile);

            $this->assertSame(1, $processed);

            $question->refresh();
            $this->assertSame('A', $question->correct_answer);
            $this->assertSame('A', $question->response_1);
            $this->assertSame('B', $question->response_2);

            $import = ZipgradeImport::where('exam_session_id', $session->id)
                ->latest()
                ->firstOrFail();

            $this->assertSame('completed', $import->status);
            $this->assertStringStartsWith('stats_', $import->filename);
            $this->assertSame(1, $import->total_rows);

            $this->assertSame('correct', HeadingRowFormatter::format(['# Correct'])[0]);
            $this->assertSame($question->id, ExamQuestion::whereNotNull('correct_answer')->firstOrFail()->id);
        } finally {
            @unlink($statsFile);
            HeadingRowFormatter::reset();
        }
    }

    public function test_tags_import_handles_large_csv_without_memory_exhaustion(): void
    {
        $year = AcademicYear::create(['year' => 2026]);
        $exam = Exam::create([
            'academic_year_id' => $year->id,
            'name' => 'Simulacro Archivo Grande',
            'type' => 'SIMULACRO',
            'date' => '2026-02-20',
        ]);

        TagHierarchy::create([
            'tag_name' => 'Lectura',
            'tag_type' => 'area',
            'parent_area' => null,
        ]);

        $students = 120;
        $questions = 40;
        $rowsTotal = 22000;

        for ($i = 1; $i <= $students; $i++) {
            $student = Student::create([
                'code' => "STU-LARGE-{$i}",
                'document_id' => "90{$i}",
                'zipgrade_id' => "ZG-LARGE-{$i}",
                'first_name' => "Nombre{$i}",
                'last_name' => "Apellido{$i}",
            ]);

            Enrollment::create([
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'grade' => 11,
                'group' => '11-1',
                'is_piar' => false,
                'status' => 'ACTIVE',
            ]);
        }

        $directory = storage_path('framework/testing/zipgrade');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $filePath = $directory.'/'.uniqid('zipgrade_large_', true).'.csv';
        $handle = fopen($filePath, 'wb');
        if (! $handle) {
            $this->fail('No se pudo crear archivo temporal CSV para prueba de volumen.');
        }

        fputcsv($handle, [
            'StudentID',
            'StudentFirstName',
            'StudentLastName',
            'QuestionNumber',
            'Tag',
            'EarnedPoints',
            'QuizName',
        ]);

        for ($i = 0; $i < $rowsTotal; $i++) {
            $studentNumber = ($i % $students) + 1;
            $questionNumber = ($i % $questions) + 1;
            $earnedPoints = $i % 3 === 0 ? 0 : 1;

            fputcsv($handle, [
                "ZG-LARGE-{$studentNumber}",
                "Nombre{$studentNumber}",
                "Apellido{$studentNumber}",
                $questionNumber,
                'Lectura',
                $earnedPoints,
                'S1',
            ]);
        }
        fclose($handle);

        try {
            $result = app(ZipgradeImportPipelineService::class)
                ->processZipgradeImport($exam, 1, $filePath);

            $session = ExamSession::where('exam_id', $exam->id)
                ->where('session_number', 1)
                ->firstOrFail();

            $this->assertSame($students, $result['students_count']);
            $this->assertSame($questions, $result['questions_count']);
            $this->assertSame($questions, $session->questions()->count());

            $import = ZipgradeImport::where('exam_session_id', $session->id)
                ->latest()
                ->firstOrFail();

            $this->assertSame('completed', $import->status);
            $this->assertSame($rowsTotal, $import->total_rows);
        } finally {
            @unlink($filePath);
        }
    }
}
