<?php

namespace App\Http\Controllers\Admin;

use App\Exports\ZipgradeResultsExport;
use App\Filament\Resources\ExamResource;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Services\ZipgradePdfService;
use App\Services\ZipgradePipelineStatusService;
use App\Services\ZipgradeReportGenerator;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExamPipelineExportController extends Controller
{
    public function excel(Exam $exam): BinaryFileResponse|RedirectResponse
    {
        if ($redirect = $this->ensurePipelineReady($exam)) {
            return $redirect;
        }

        $export = new ZipgradeResultsExport($exam, null, null);
        $filename = 'resultados_zipgrade_'.str_replace(' ', '_', strtolower($exam->name)).'_'.now()->format('Y-m-d').'.xlsx';

        return Excel::download($export, $filename);
    }

    public function pdf(Exam $exam): StreamedResponse|RedirectResponse
    {
        if ($redirect = $this->ensurePipelineReady($exam)) {
            return $redirect;
        }

        $pdfService = app(ZipgradePdfService::class);
        $filename = $pdfService->getFilename($exam);

        return response()->streamDownload(function () use ($pdfService, $exam): void {
            echo $pdfService->generate($exam, null, null);
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function html(Exam $exam): StreamedResponse|RedirectResponse
    {
        if ($redirect = $this->ensurePipelineReady($exam)) {
            return $redirect;
        }

        $generator = app(ZipgradeReportGenerator::class);
        $filename = $generator->getReportFilename($exam, null);

        return response()->streamDownload(function () use ($generator, $exam): void {
            echo $generator->generateHtmlReport($exam, null);
        }, $filename, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }

    private function ensurePipelineReady(Exam $exam): ?RedirectResponse
    {
        $pipeline = app(ZipgradePipelineStatusService::class)->getPipelineStatus($exam);

        if ($pipeline['ready']) {
            return null;
        }

        Notification::make()
            ->title('Pipeline incompleto')
            ->body('Completa la carga de todas las sesiones antes de descargar reportes.')
            ->warning()
            ->send();

        return redirect()->to(ExamResource::getUrl('pipeline', ['record' => $exam]));
    }
}

