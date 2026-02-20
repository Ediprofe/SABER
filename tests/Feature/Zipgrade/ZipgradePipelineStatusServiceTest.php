<?php

namespace Tests\Feature\Zipgrade;

use App\Services\ZipgradeMetricsService;
use App\Services\ZipgradePipelineStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesZipgradeFixture;
use Tests\TestCase;

class ZipgradePipelineStatusServiceTest extends TestCase
{
    use CreatesZipgradeFixture;
    use RefreshDatabase;

    public function test_pipeline_is_ready_with_tagged_questions_and_stats_even_without_completed_imports(): void
    {
        $fixture = $this->createTwoSessionExamFixture([
            'with_stats' => true,
            'with_imports' => false,
        ]);

        $service = app(ZipgradePipelineStatusService::class);
        $session1 = $service->getSessionStatus($fixture['exam'], 1);
        $session2 = $service->getSessionStatus($fixture['exam'], 2);
        $pipeline = $service->getPipelineStatus($fixture['exam']);

        $this->assertFalse($session1['has_completed_import']);
        $this->assertFalse($session2['has_completed_import']);
        $this->assertTrue($session1['has_tagged_questions']);
        $this->assertTrue($session2['has_tagged_questions']);
        $this->assertTrue($session1['has_stats']);
        $this->assertTrue($session2['has_stats']);
        $this->assertTrue($pipeline['tags_done']);
        $this->assertTrue($pipeline['stats_done']);
        $this->assertTrue($pipeline['ready']);
    }

    public function test_pipeline_is_not_ready_when_stats_are_missing(): void
    {
        $fixture = $this->createTwoSessionExamFixture([
            'with_stats' => false,
            'with_imports' => false,
        ]);

        $service = app(ZipgradePipelineStatusService::class);
        $pipeline = $service->getPipelineStatus($fixture['exam']);

        $this->assertTrue($pipeline['tags_done']);
        $this->assertFalse($pipeline['stats_done']);
        $this->assertFalse($pipeline['ready']);
    }

    public function test_global_score_matches_weighted_formula_regression_case(): void
    {
        $fixture = $this->createTwoSessionExamFixture([
            'with_stats' => true,
            'with_imports' => false,
        ]);

        $metrics = app(ZipgradeMetricsService::class);
        $globalScore = $metrics->getStudentGlobalScore($fixture['enrollment'], $fixture['exam']);

        $this->assertSame(269, $globalScore);
    }
}

