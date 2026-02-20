<?php

namespace Tests\Feature\Zipgrade;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSession;
use App\Models\QuestionTag;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\TagHierarchy;
use App\Models\TagNormalization;
use App\Services\ZipgradeSessionImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ZipgradeSessionImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_preview_and_imports_session_data(): void
    {
        $year = AcademicYear::create(['year' => 2026]);

        $exam = Exam::create([
            'academic_year_id' => $year->id,
            'name' => 'Simulacro Nuevo Pipeline',
            'type' => 'SIMULACRO',
            'date' => '2026-02-20',
            'sessions_count' => 2,
        ]);

        $student = Student::create([
            'code' => 'STU-NEW-1',
            'document_id' => '9001',
            'zipgrade_id' => 'ZG-9001',
            'first_name' => 'Ana',
            'last_name' => 'Nueva',
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
            ',1,B,1,Ciencias Sociales,Ciudadanía',
            ',2,A,1,Matemáticas,Formulación',
        ]);

        $responsesCsv = implode("\n", [
            'QuizName,QuizClass,FirstName,LastName,StudentID,CustomID,Earned Points,Possible Points,PercentCorrect,QuizCreated,DataExported,Key Version,Stu1,PriKey1,Points1,Mark1,Stu2,PriKey2,Points2,Mark2',
            'S1,1101,Ana,Nueva,ZG-9001,,2,2,100,2026-02-01,2026-02-20 12:00:00,,B,B,1,C,A,A,1,C',
        ]);

        $blueprintPath = 'zipgrade_imports/test_blueprint.csv';
        $responsesPath = 'zipgrade_imports/test_responses.csv';

        Storage::disk('local')->put($blueprintPath, $blueprintCsv);
        Storage::disk('local')->put($responsesPath, $responsesCsv);

        $service = app(ZipgradeSessionImportService::class);

        $analysis = $service->analyzeSessionUpload($exam, 1, $blueprintPath, $responsesPath);

        $this->assertArrayHasKey('token', $analysis);
        $this->assertSame(2, $analysis['summary']['question_count_blueprint']);
        $this->assertSame(2, $analysis['summary']['question_count_responses']);
        $this->assertSame(1, $analysis['summary']['students_matched']);
        $this->assertSame(0, $analysis['summary']['students_unmatched']);

        $result = $service->importFromPreviewToken($exam, 1, $analysis['token']);

        $this->assertSame(2, $result['questions_imported']);
        $this->assertSame(2, $result['answers_imported']);
        $this->assertSame(1, $result['students_matched']);

        $session = ExamSession::where('exam_id', $exam->id)
            ->where('session_number', 1)
            ->firstOrFail();

        $this->assertSame(2, (int) $session->total_questions);
        $this->assertSame(2, ExamQuestion::where('exam_session_id', $session->id)->count());
        $this->assertSame(2, StudentAnswer::count());
        $this->assertGreaterThanOrEqual(2, QuestionTag::count());
    }

    public function test_it_applies_manual_tag_classification_and_stores_normalizations(): void
    {
        $year = AcademicYear::create(['year' => 2026]);

        $exam = Exam::create([
            'academic_year_id' => $year->id,
            'name' => 'Simulacro Clasificacion Tags',
            'type' => 'SIMULACRO',
            'date' => '2026-02-20',
            'sessions_count' => 1,
        ]);

        $student = Student::create([
            'code' => 'STU-NEW-2',
            'document_id' => '9002',
            'zipgrade_id' => 'ZG-9002',
            'first_name' => 'Laura',
            'last_name' => 'Clasifica',
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
            ',1,B,1,Matematicas,Formulacion',
        ]);

        $responsesCsv = implode("\n", [
            'QuizName,QuizClass,FirstName,LastName,StudentID,CustomID,Earned Points,Possible Points,PercentCorrect,QuizCreated,DataExported,Key Version,Stu1,PriKey1,Points1,Mark1',
            'S1,1101,Laura,Clasifica,ZG-9002,,1,1,100,2026-02-01,2026-02-20 12:00:00,,B,B,1,C',
        ]);

        $blueprintPath = 'zipgrade_imports/test_blueprint_manual_classification.csv';
        $responsesPath = 'zipgrade_imports/test_responses_manual_classification.csv';

        Storage::disk('local')->put($blueprintPath, $blueprintCsv);
        Storage::disk('local')->put($responsesPath, $responsesCsv);

        $service = app(ZipgradeSessionImportService::class);
        $analysis = $service->analyzeSessionUpload($exam, 1, $blueprintPath, $responsesPath);

        $service->importFromPreviewToken(
            $exam,
            1,
            $analysis['token'],
            [
                'Matematicas' => ['area' => 'matematicas', 'type' => 'area'],
                'Formulacion' => ['area' => 'matematicas', 'type' => 'competencia'],
            ],
            true
        );

        $tag = TagHierarchy::query()->where('tag_name', 'Formulacion')->firstOrFail();
        $this->assertSame('competencia', $tag->tag_type);
        $this->assertSame('Matemáticas', $tag->parent_area);

        $normalization = TagNormalization::query()->where('tag_csv_name', 'Formulacion')->firstOrFail();
        $this->assertSame('competencia', $normalization->tag_type);
        $this->assertSame('Matemáticas', $normalization->parent_area);
    }

    public function test_it_suggests_area_and_dimension_types_for_realistic_tags(): void
    {
        $year = AcademicYear::create(['year' => 2026]);

        $exam = Exam::create([
            'academic_year_id' => $year->id,
            'name' => 'Simulacro Sugerencias',
            'type' => 'SIMULACRO',
            'date' => '2026-02-20',
            'sessions_count' => 1,
        ]);

        $student = Student::create([
            'code' => 'STU-NEW-3',
            'document_id' => '9003',
            'zipgrade_id' => 'ZG-9003',
            'first_name' => 'Maria',
            'last_name' => 'Etiquetas',
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
            ',1,B,1,Ingles,Lexical,PARTE 1',
            ',2,A,1,Lectura Critica,Texto Continuo,Inferencial',
            ',3,C,1,Matematicas,Formulacion,Numerico',
        ]);

        $responsesCsv = implode("\n", [
            'QuizName,QuizClass,FirstName,LastName,StudentID,CustomID,Earned Points,Possible Points,PercentCorrect,QuizCreated,DataExported,Key Version,Stu1,PriKey1,Points1,Mark1,Stu2,PriKey2,Points2,Mark2,Stu3,PriKey3,Points3,Mark3',
            'S1,1101,Maria,Etiquetas,ZG-9003,,3,3,100,2026-02-01,2026-02-20 12:00:00,,B,B,1,C,A,A,1,C,C,C,1,C',
        ]);

        $blueprintPath = 'zipgrade_imports/test_blueprint_suggestions.csv';
        $responsesPath = 'zipgrade_imports/test_responses_suggestions.csv';

        Storage::disk('local')->put($blueprintPath, $blueprintCsv);
        Storage::disk('local')->put($responsesPath, $responsesCsv);

        $service = app(ZipgradeSessionImportService::class);
        $analysis = $service->analyzeSessionUpload($exam, 1, $blueprintPath, $responsesPath);

        $suggestions = collect($analysis['summary']['tag_suggestions'] ?? [])
            ->keyBy('tag')
            ->all();

        $this->assertSame('ingles', $suggestions['PARTE 1']['suggested_area']);
        $this->assertSame('parte', $suggestions['PARTE 1']['suggested_type']);

        $this->assertSame('ingles', $suggestions['Lexical']['suggested_area']);
        $this->assertSame('competencia', $suggestions['Lexical']['suggested_type']);

        $this->assertSame('lectura', $suggestions['Texto Continuo']['suggested_area']);
        $this->assertSame('tipo_texto', $suggestions['Texto Continuo']['suggested_type']);

        $this->assertSame('lectura', $suggestions['Inferencial']['suggested_area']);
        $this->assertSame('nivel_lectura', $suggestions['Inferencial']['suggested_type']);

        $this->assertSame('matematicas', $suggestions['Formulacion']['suggested_area']);
        $this->assertSame('competencia', $suggestions['Formulacion']['suggested_type']);

        $this->assertSame('matematicas', $suggestions['Numerico']['suggested_area']);
        $this->assertSame('componente', $suggestions['Numerico']['suggested_type']);
    }

    public function test_it_prioritizes_current_blueprint_area_hint_over_conflicting_normalization(): void
    {
        $year = AcademicYear::create(['year' => 2026]);

        $exam = Exam::create([
            'academic_year_id' => $year->id,
            'name' => 'Simulacro Conflicto Normalizacion',
            'type' => 'SIMULACRO',
            'date' => '2026-02-20',
            'sessions_count' => 1,
        ]);

        $student = Student::create([
            'code' => 'STU-NEW-4',
            'document_id' => '9004',
            'zipgrade_id' => 'ZG-9004',
            'first_name' => 'Ana',
            'last_name' => 'Conflicto',
        ]);

        Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'grade' => 11,
            'group' => '11-1',
            'is_piar' => false,
            'status' => 'ACTIVE',
        ]);

        TagNormalization::storeNormalization([
            'tag_csv_name' => 'COMUNICATIVA',
            'tag_system_name' => 'COMUNICATIVA',
            'tag_type' => 'competencia',
            'parent_area' => 'Lectura Crítica',
            'is_active' => true,
        ]);

        $blueprintCsv = implode("\n", [
            'Key Letter,Question Number,Response/Mapping,Point Value,Tags',
            ',1,C,1,Ingles,PARTE 3,COMUNICATIVA',
        ]);

        $responsesCsv = implode("\n", [
            'QuizName,QuizClass,FirstName,LastName,StudentID,CustomID,Earned Points,Possible Points,PercentCorrect,QuizCreated,DataExported,Key Version,Stu1,PriKey1,Points1,Mark1',
            'S1,1101,Ana,Conflicto,ZG-9004,,1,1,100,2026-02-01,2026-02-20 12:00:00,,C,C,1,C',
        ]);

        $blueprintPath = 'zipgrade_imports/test_blueprint_normalization_conflict.csv';
        $responsesPath = 'zipgrade_imports/test_responses_normalization_conflict.csv';

        Storage::disk('local')->put($blueprintPath, $blueprintCsv);
        Storage::disk('local')->put($responsesPath, $responsesCsv);

        $service = app(ZipgradeSessionImportService::class);
        $analysis = $service->analyzeSessionUpload($exam, 1, $blueprintPath, $responsesPath);

        $suggestions = collect($analysis['summary']['tag_suggestions'] ?? [])->keyBy('tag');

        $this->assertSame('ingles', $suggestions['COMUNICATIVA']['suggested_area']);
        $this->assertSame('competencia', $suggestions['COMUNICATIVA']['suggested_type']);
    }

    public function test_it_prioritizes_current_blueprint_area_hint_over_conflicting_existing_tag_hierarchy(): void
    {
        $year = AcademicYear::create(['year' => 2026]);

        $exam = Exam::create([
            'academic_year_id' => $year->id,
            'name' => 'Simulacro Conflicto Jerarquia',
            'type' => 'SIMULACRO',
            'date' => '2026-02-20',
            'sessions_count' => 1,
        ]);

        $student = Student::create([
            'code' => 'STU-NEW-5',
            'document_id' => '9005',
            'zipgrade_id' => 'ZG-9005',
            'first_name' => 'Sofia',
            'last_name' => 'Conflicto',
        ]);

        Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'grade' => 11,
            'group' => '11-1',
            'is_piar' => false,
            'status' => 'ACTIVE',
        ]);

        TagHierarchy::create([
            'tag_name' => 'COMUNICATIVA',
            'tag_type' => 'competencia',
            'parent_area' => 'Lectura Crítica',
        ]);

        $blueprintCsv = implode("\n", [
            'Key Letter,Question Number,Response/Mapping,Point Value,Tags',
            ',1,C,1,Ingles,PARTE 3,COMUNICATIVA',
        ]);

        $responsesCsv = implode("\n", [
            'QuizName,QuizClass,FirstName,LastName,StudentID,CustomID,Earned Points,Possible Points,PercentCorrect,QuizCreated,DataExported,Key Version,Stu1,PriKey1,Points1,Mark1',
            'S1,1101,Sofia,Conflicto,ZG-9005,,1,1,100,2026-02-01,2026-02-20 12:00:00,,C,C,1,C',
        ]);

        $blueprintPath = 'zipgrade_imports/test_blueprint_existing_conflict.csv';
        $responsesPath = 'zipgrade_imports/test_responses_existing_conflict.csv';

        Storage::disk('local')->put($blueprintPath, $blueprintCsv);
        Storage::disk('local')->put($responsesPath, $responsesCsv);

        $service = app(ZipgradeSessionImportService::class);
        $analysis = $service->analyzeSessionUpload($exam, 1, $blueprintPath, $responsesPath);

        $suggestions = collect($analysis['summary']['tag_suggestions'] ?? [])->keyBy('tag');

        $this->assertSame('ingles', $suggestions['COMUNICATIVA']['suggested_area']);
        $this->assertSame('competencia', $suggestions['COMUNICATIVA']['suggested_type']);
    }

    public function test_it_adjusts_legacy_english_part_type_to_competencia_for_lexical_tags(): void
    {
        $year = AcademicYear::create(['year' => 2026]);

        $exam = Exam::create([
            'academic_year_id' => $year->id,
            'name' => 'Simulacro Ingles Legacy Type',
            'type' => 'SIMULACRO',
            'date' => '2026-02-20',
            'sessions_count' => 1,
        ]);

        $student = Student::create([
            'code' => 'STU-NEW-6',
            'document_id' => '9006',
            'zipgrade_id' => 'ZG-9006',
            'first_name' => 'Valeria',
            'last_name' => 'Legacy',
        ]);

        Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'grade' => 11,
            'group' => '11-1',
            'is_piar' => false,
            'status' => 'ACTIVE',
        ]);

        TagNormalization::storeNormalization([
            'tag_csv_name' => 'Lexical',
            'tag_system_name' => 'Lexical',
            'tag_type' => 'parte',
            'parent_area' => 'Inglés',
            'is_active' => true,
        ]);

        $blueprintCsv = implode("\n", [
            'Key Letter,Question Number,Response/Mapping,Point Value,Tags',
            ',1,B,1,Ingles,Lexical,PARTE 1',
        ]);

        $responsesCsv = implode("\n", [
            'QuizName,QuizClass,FirstName,LastName,StudentID,CustomID,Earned Points,Possible Points,PercentCorrect,QuizCreated,DataExported,Key Version,Stu1,PriKey1,Points1,Mark1',
            'S1,1101,Valeria,Legacy,ZG-9006,,1,1,100,2026-02-01,2026-02-20 12:00:00,,B,B,1,C',
        ]);

        $blueprintPath = 'zipgrade_imports/test_blueprint_english_legacy_type.csv';
        $responsesPath = 'zipgrade_imports/test_responses_english_legacy_type.csv';

        Storage::disk('local')->put($blueprintPath, $blueprintCsv);
        Storage::disk('local')->put($responsesPath, $responsesCsv);

        $service = app(ZipgradeSessionImportService::class);
        $analysis = $service->analyzeSessionUpload($exam, 1, $blueprintPath, $responsesPath);

        $suggestions = collect($analysis['summary']['tag_suggestions'] ?? [])->keyBy('tag');

        $this->assertSame('ingles', $suggestions['Lexical']['suggested_area']);
        $this->assertSame('competencia', $suggestions['Lexical']['suggested_type']);
    }

    public function test_it_does_not_infer_area_from_dimension_tags_without_explicit_area_tag(): void
    {
        $year = AcademicYear::create(['year' => 2026]);

        $exam = Exam::create([
            'academic_year_id' => $year->id,
            'name' => 'Simulacro Area Explicita',
            'type' => 'SIMULACRO',
            'date' => '2026-02-20',
            'sessions_count' => 1,
        ]);

        $student = Student::create([
            'code' => 'STU-NEW-7',
            'document_id' => '9007',
            'zipgrade_id' => 'ZG-9007',
            'first_name' => 'Luisa',
            'last_name' => 'SinArea',
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
            ',1,B,1,COMPRENSION DE TEXTO LITERAL,PARTE 5',
            ',2,A,1,Lexical,PARTE 1',
        ]);

        $responsesCsv = implode("\n", [
            'QuizName,QuizClass,FirstName,LastName,StudentID,CustomID,Earned Points,Possible Points,PercentCorrect,QuizCreated,DataExported,Key Version,Stu1,PriKey1,Points1,Mark1,Stu2,PriKey2,Points2,Mark2',
            'S1,1101,Luisa,SinArea,ZG-9007,,2,2,100,2026-02-01,2026-02-20 12:00:00,,B,B,1,C,A,A,1,C',
        ]);

        $blueprintPath = 'zipgrade_imports/test_blueprint_dimensions_without_area.csv';
        $responsesPath = 'zipgrade_imports/test_responses_dimensions_without_area.csv';

        Storage::disk('local')->put($blueprintPath, $blueprintCsv);
        Storage::disk('local')->put($responsesPath, $responsesCsv);

        $service = app(ZipgradeSessionImportService::class);
        $analysis = $service->analyzeSessionUpload($exam, 1, $blueprintPath, $responsesPath);

        $areaCounts = $analysis['summary']['area_question_counts'] ?? [];
        $this->assertArrayNotHasKey('ingles', $areaCounts);
        $this->assertArrayNotHasKey('lectura', $areaCounts);
    }
}
