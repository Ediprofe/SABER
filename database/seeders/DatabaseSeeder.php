<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Exam;
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
        $this->seedExam2025();
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

        // 80 students grade 10 (≈27 per group)
        foreach (range(1, 80) as $i) {
            $groupIndex = floor(($i - 1) / 27);
            $group = $groups[3 + $groupIndex] ?? '10-3';
            $isPiar = $i <= 12; // ~15% PIAR

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
}
