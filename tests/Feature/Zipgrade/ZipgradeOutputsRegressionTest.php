<?php

namespace Tests\Feature\Zipgrade;

use App\Exports\ZipgradeResultsExport;
use App\Services\ZipgradePdfService;
use App\Services\ZipgradeReportGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Support\CreatesZipgradeFixture;
use Tests\TestCase;

class ZipgradeOutputsRegressionTest extends TestCase
{
    use CreatesZipgradeFixture;
    use RefreshDatabase;

    public function test_html_pdf_and_excel_outputs_are_generated_for_populated_exam(): void
    {
        $fixture = $this->createTwoSessionExamFixture([
            'with_stats' => true,
            'with_imports' => false,
        ]);

        $exam = $fixture['exam'];

        $html = app(ZipgradeReportGenerator::class)->generateHtmlReport($exam, null);
        $pdf = app(ZipgradePdfService::class)->generate($exam, null, null);
        $xlsx = Excel::raw(new ZipgradeResultsExport($exam, null, null), ExcelWriter::XLSX);

        $this->assertStringContainsString('Simulacro Fixture', $html);
        $this->assertStringContainsString('Ana Prueba', $html);
        $this->assertGreaterThan(1000, strlen($pdf));
        $this->assertGreaterThan(1000, strlen($xlsx));
        $this->assertStringStartsWith('PK', $xlsx);
    }
}

