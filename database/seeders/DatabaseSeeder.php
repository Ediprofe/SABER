<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAreaConfig;
use App\Models\ExamAreaItem;
use App\Models\ExamDetailResult;
use App\Models\ExamResult;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Create admin user (if not exists)
        User::firstOrCreate(
            ['email' => 'admin@saber.edu'],
            [
                'name' => 'Administrador',
                'email' => 'admin@saber.edu',
                'password' => bcrypt('password'),
            ]
        );

        // Clear existing data to avoid duplicates
        DB::table('exam_results')->delete();
        DB::table('exams')->delete();
        DB::table('enrollments')->delete();
        DB::table('students')->delete();
        DB::table('academic_years')->delete();

        $this->seedAcademicYears();
        $this->seedStudentsAndEnrollments2025();
        $this->seedStudentsAndEnrollments2024();
        $this->seedStudentsAndEnrollments2026();
        $this->seedExam2025();
        $this->seedDetailConfiguration2025();
    }

    private function seedAcademicYears(): void
    {
        AcademicYear::create(['year' => 2024]);
        AcademicYear::create(['year' => 2025]);
        AcademicYear::create(['year' => 2026]);
    }

    private function seedStudentsAndEnrollments2025(): void
    {
        $year2025 = AcademicYear::where('year', 2025)->first();
        $groups = ['11-1', '11-2', '11-3', '10-1', '10-2', '10-3'];

        // 80 students grade 11 (≈27 per group)
        foreach (range(1, 80) as $i) {
            $groupIndex = floor(($i - 1) / 27);
            $group = $groups[$groupIndex] ?? '11-3';
            $isPiar = $i <= 12; // ~15% PIAR

            $student = $this->createStudent(2025, 11, $i);

            Enrollment::create([
                'student_id' => $student->id,
                'academic_year_id' => $year2025->id,
                'grade' => 11,
                'group' => $group,
                'is_piar' => $isPiar,
                'status' => 'ACTIVE',
            ]);
        }

        // 80 students grade 10 (≈27 per group) - Use offset 81 to avoid collision with grade 11 from 2026
        // These students graduate in 2026 but use codes STU-2026-00081 to STU-2026-00160
        foreach (range(81, 160) as $i) {
            $indexInGroup = $i - 81;
            $groupIndex = floor($indexInGroup / 27);
            $group = $groups[3 + $groupIndex] ?? '10-3';
            $isPiar = $indexInGroup <= 12; // ~15% PIAR

            $student = $this->createStudent(2026, 10, $i);

            Enrollment::create([
                'student_id' => $student->id,
                'academic_year_id' => $year2025->id,
                'grade' => 10,
                'group' => $group,
                'is_piar' => $isPiar,
                'status' => 'ACTIVE',
            ]);
        }
    }

    private function seedStudentsAndEnrollments2024(): void
    {
        $year2024 = AcademicYear::where('year', 2024)->first();
        $groups = ['11-1', '11-2'];

        // 50 graduated students grade 11 in 2024
        foreach (range(1, 50) as $i) {
            $group = $i <= 25 ? '11-1' : '11-2';
            $isPiar = $i <= 7; // ~15% PIAR

            $student = $this->createStudent(2024, 11, $i);

            Enrollment::create([
                'student_id' => $student->id,
                'academic_year_id' => $year2024->id,
                'grade' => 11,
                'group' => $group,
                'is_piar' => $isPiar,
                'status' => 'GRADUATED',
            ]);
        }
    }

    private function seedStudentsAndEnrollments2026(): void
    {
        $year2026 = AcademicYear::where('year', 2026)->first();
        $groups = ['11-1', '11-2', '11-3', '10-1', '10-2', '10-3'];

        // 80 students grade 11 (≈27 per group) - Use codes 1-80 (STU-2026-00001 to STU-2026-00080)
        // Grade 10 students from 2025 use STU-2026 codes, so grade 11 uses same year but different index range isn't needed
        // because they are different students in different academic years
        foreach (range(1, 80) as $i) {
            $groupIndex = floor(($i - 1) / 27);
            $group = $groups[$groupIndex] ?? '11-3';
            $isPiar = $i <= 12; // ~15% PIAR

            $student = $this->createStudent(2026, 11, $i);

            Enrollment::create([
                'student_id' => $student->id,
                'academic_year_id' => $year2026->id,
                'grade' => 11,
                'group' => $group,
                'is_piar' => $isPiar,
                'status' => 'ACTIVE',
            ]);
        }

        // 80 students grade 10 (≈27 per group)
        foreach (range(1, 80) as $i) {
            $groupIndex = floor(($i - 1) / 27);
            $group = $groups[3 + $groupIndex] ?? '10-3';
            $isPiar = $i <= 12; // ~15% PIAR

            $student = $this->createStudent(2027, 10, $i);

            Enrollment::create([
                'student_id' => $student->id,
                'academic_year_id' => $year2026->id,
                'grade' => 10,
                'group' => $group,
                'is_piar' => $isPiar,
                'status' => 'ACTIVE',
            ]);
        }
    }

    private function seedExam2025(): void
    {
        $year2025 = AcademicYear::where('year', 2025)->first();

        $exam = Exam::create([
            'academic_year_id' => $year2025->id,
            'name' => 'Simulacro Único 2025',
            'type' => 'SIMULACRO',
            'date' => '2025-03-15',
        ]);

        // Generate results for all active enrollments in 2025
        $enrollments = Enrollment::where('academic_year_id', $year2025->id)
            ->where('status', 'ACTIVE')
            ->get();

        foreach ($enrollments as $index => $enrollment) {
            // Normal distribution around 60, std dev 15
            $score = fn () => max(0, min(100, (int) $this->normalDistribution(60, 15)));

            // 5% of PIAR students have no English score
            $noEnglish = $enrollment->is_piar && ($index % 20 === 0);

            $lectura = $score();
            $matematicas = $score();
            $sociales = $score();
            $naturales = $score();
            $ingles = $noEnglish ? null : $score();

            // Calculate global score
            $inglesForCalc = $ingles ?? 0;
            $globalScore = (int) round((($lectura + $matematicas + $sociales + $naturales) * 3 + $inglesForCalc) / 13 * 5);

            ExamResult::create([
                'exam_id' => $exam->id,
                'enrollment_id' => $enrollment->id,
                'lectura' => $lectura,
                'matematicas' => $matematicas,
                'sociales' => $sociales,
                'naturales' => $naturales,
                'ingles' => $ingles,
                'global_score' => $globalScore,
            ]);
        }
    }

    private function createStudent(int $graduationYear, int $grade, int $index): Student
    {
        $prefix = "STU-{$graduationYear}-";
        $code = $prefix.str_pad($index, 5, '0', STR_PAD_LEFT);

        return Student::create([
            'code' => $code,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
        ]);
    }

    private function normalDistribution(float $mean, float $stdDev): float
    {
        $u1 = mt_rand() / mt_getrandmax();
        $u2 = mt_rand() / mt_getrandmax();
        $z = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);

        return $mean + ($stdDev * $z);
    }

    private function seedDetailConfiguration2025(): void
    {
        $year2025 = AcademicYear::where('year', 2025)->first();
        $exam = Exam::where('academic_year_id', $year2025->id)->where('name', 'Simulacro Único 2025')->first();

        if (! $exam) {
            return;
        }

        // Configure Naturales with competencias and componentes
        $naturalesConfig = ExamAreaConfig::create([
            'exam_id' => $exam->id,
            'area' => 'naturales',
            'dimension1_name' => 'Competencias',
            'dimension2_name' => 'Componentes',
        ]);

        $natCompetencias = ['Uso del conocimiento', 'Explicación de fenómenos', 'Indagación'];
        foreach ($natCompetencias as $index => $name) {
            ExamAreaItem::create([
                'exam_area_config_id' => $naturalesConfig->id,
                'dimension' => 1,
                'name' => $name,
                'order' => $index,
            ]);
        }

        $natComponentes = ['Vivo', 'Químico', 'Físico', 'CTS'];
        foreach ($natComponentes as $index => $name) {
            ExamAreaItem::create([
                'exam_area_config_id' => $naturalesConfig->id,
                'dimension' => 2,
                'name' => $name,
                'order' => $index,
            ]);
        }

        // Configure Matemáticas with competencias and componentes
        $matematicasConfig = ExamAreaConfig::create([
            'exam_id' => $exam->id,
            'area' => 'matematicas',
            'dimension1_name' => 'Competencias',
            'dimension2_name' => 'Componentes',
        ]);

        $matCompetencias = ['Interpretación', 'Formulación', 'Argumentación'];
        foreach ($matCompetencias as $index => $name) {
            ExamAreaItem::create([
                'exam_area_config_id' => $matematicasConfig->id,
                'dimension' => 1,
                'name' => $name,
                'order' => $index,
            ]);
        }

        $matComponentes = ['Numérico', 'Geométrico', 'Aleatorio'];
        foreach ($matComponentes as $index => $name) {
            ExamAreaItem::create([
                'exam_area_config_id' => $matematicasConfig->id,
                'dimension' => 2,
                'name' => $name,
                'order' => $index,
            ]);
        }

        // Configure Lectura with competencias and tipos de texto
        $lecturaConfig = ExamAreaConfig::create([
            'exam_id' => $exam->id,
            'area' => 'lectura',
            'dimension1_name' => 'Competencias',
            'dimension2_name' => 'Tipos de Texto',
        ]);

        $lecCompetencias = ['Identificar y entender', 'Reflexionar y evaluar', 'Comprender articulación'];
        foreach ($lecCompetencias as $index => $name) {
            ExamAreaItem::create([
                'exam_area_config_id' => $lecturaConfig->id,
                'dimension' => 1,
                'name' => $name,
                'order' => $index,
            ]);
        }

        $lecTiposTexto = ['Continuo', 'Discontinuo', 'Mixto'];
        foreach ($lecTiposTexto as $index => $name) {
            ExamAreaItem::create([
                'exam_area_config_id' => $lecturaConfig->id,
                'dimension' => 2,
                'name' => $name,
                'order' => $index,
            ]);
        }

        // Configure Sociales with competencias and componentes
        $socialesConfig = ExamAreaConfig::create([
            'exam_id' => $exam->id,
            'area' => 'sociales',
            'dimension1_name' => 'Competencias',
            'dimension2_name' => 'Componentes',
        ]);

        $socCompetencias = ['Pensamiento social', 'Interpretación de perspectivas', 'Pensamiento reflexivo'];
        foreach ($socCompetencias as $index => $name) {
            ExamAreaItem::create([
                'exam_area_config_id' => $socialesConfig->id,
                'dimension' => 1,
                'name' => $name,
                'order' => $index,
            ]);
        }

        $socComponentes = ['Historia', 'Geografía', 'Ético-político'];
        foreach ($socComponentes as $index => $name) {
            ExamAreaItem::create([
                'exam_area_config_id' => $socialesConfig->id,
                'dimension' => 2,
                'name' => $name,
                'order' => $index,
            ]);
        }

        // Configure Inglés with partes (solo dimensión 1)
        $inglesConfig = ExamAreaConfig::create([
            'exam_id' => $exam->id,
            'area' => 'ingles',
            'dimension1_name' => 'Partes',
            'dimension2_name' => null,
        ]);

        $ingPartes = ['Parte 1', 'Parte 2', 'Parte 3', 'Parte 4', 'Parte 5', 'Parte 6', 'Parte 7'];
        foreach ($ingPartes as $index => $name) {
            ExamAreaItem::create([
                'exam_area_config_id' => $inglesConfig->id,
                'dimension' => 1,
                'name' => $name,
                'order' => $index,
            ]);
        }

        // Generate detail results for all exam results
        $examResults = ExamResult::where('exam_id', $exam->id)->get();
        $areaItems = ExamAreaItem::whereHas('config', function ($q) use ($exam) {
            $q->where('exam_id', $exam->id);
        })->get();

        foreach ($examResults as $examResult) {
            foreach ($areaItems as $item) {
                // Generate random score 0-100, with 10% null (missing data)
                $score = (mt_rand(1, 10) === 1) ? null : max(0, min(100, (int) $this->normalDistribution(60, 15)));

                ExamDetailResult::create([
                    'exam_result_id' => $examResult->id,
                    'exam_area_item_id' => $item->id,
                    'score' => $score,
                ]);
            }
        }
    }
}
