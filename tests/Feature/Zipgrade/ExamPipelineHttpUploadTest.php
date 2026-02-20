<?php

namespace Tests\Feature\Zipgrade;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\TagHierarchy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ExamPipelineHttpUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_can_be_uploaded_and_imported_through_http_pipeline_flow(): void
    {
        $user = User::factory()->create();

        $year = AcademicYear::create(['year' => 2026]);
        $exam = Exam::create([
            'academic_year_id' => $year->id,
            'name' => 'Simulacro HTTP Upload',
            'type' => 'SIMULACRO',
            'date' => '2026-02-20',
            'sessions_count' => 2,
        ]);

        TagHierarchy::create([
            'tag_name' => 'Lectura',
            'tag_type' => 'area',
            'parent_area' => null,
        ]);

        $student = Student::create([
            'code' => 'STU-HTTP-1',
            'document_id' => '3001',
            'zipgrade_id' => 'ZG-3001',
            'first_name' => 'Lucia',
            'last_name' => 'Carga',
        ]);

        Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'grade' => 11,
            'group' => '11-1',
            'is_piar' => false,
            'status' => 'ACTIVE',
        ]);

        $blueprintCsv = implode("\n", [
            'Key Letter,Question Number,Response/Mapping,Point Value,Tags',
            ',1,B,1,Lectura',
        ]);

        $responsesCsv = implode("\n", [
            'QuizName,QuizClass,FirstName,LastName,StudentID,CustomID,Earned Points,Possible Points,PercentCorrect,QuizCreated,DataExported,Key Version,Stu1,PriKey1,Points1,Mark1',
            'S1,1101,Lucia,Carga,ZG-3001,,1,1,100,2026-02-01,2026-02-20 12:00:00,,B,B,1,C',
        ]);

        $blueprintFile = UploadedFile::fake()->createWithContent('blueprint-s1.csv', $blueprintCsv);
        $responsesFile = UploadedFile::fake()->createWithContent('responses-s1.csv', $responsesCsv);

        $analyzeResponse = $this
            ->actingAs($user)
            ->post(route('admin.exams.pipeline.upload.analyze', [
                'exam' => $exam,
                'sessionNumber' => 1,
            ]), [
                'blueprint_file' => $blueprintFile,
                'responses_file' => $responsesFile,
            ]);

        $analyzeResponse->assertRedirect();

        $location = $analyzeResponse->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertStringContainsString("/admin/exams/{$exam->id}/pipeline/preview/1/", $location);

        $token = basename((string) $location);

        $importResponse = $this
            ->actingAs($user)
            ->post(route('admin.exams.pipeline.upload.import', [
                'exam' => $exam,
                'sessionNumber' => 1,
                'token' => $token,
            ]));

        $importResponse->assertRedirect("/admin/exams/{$exam->id}/pipeline");

        $this->assertSame(1, (int) $exam->sessions()->where('session_number', 1)->count());
        $this->assertSame(1, StudentAnswer::count());
    }
}
