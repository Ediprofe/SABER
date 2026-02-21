<?php

use App\Http\Controllers\Admin\ExamPipelineUploadController;
use App\Http\Controllers\Admin\ExamPipelineExportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', function () {
    return redirect('/admin');
})->name('login');

Route::post('/admin/exams/{exam}/pipeline/upload/{sessionNumber}/analyze', [ExamPipelineUploadController::class, 'analyze'])
    ->whereNumber('sessionNumber')
    ->name('admin.exams.pipeline.upload.analyze');

Route::post('/admin/exams/{exam}/pipeline/upload/{sessionNumber}/import/{token}', [ExamPipelineUploadController::class, 'import'])
    ->whereNumber('sessionNumber')
    ->name('admin.exams.pipeline.upload.import');

Route::middleware('auth')->group(function (): void {
    Route::get('/admin/exams/{exam}/pipeline/export/excel', [ExamPipelineExportController::class, 'excel'])
        ->name('admin.exams.pipeline.export.excel');

    Route::get('/admin/exams/{exam}/pipeline/export/pdf', [ExamPipelineExportController::class, 'pdf'])
        ->name('admin.exams.pipeline.export.pdf');

    Route::get('/admin/exams/{exam}/pipeline/export/html', [ExamPipelineExportController::class, 'html'])
        ->name('admin.exams.pipeline.export.html');
});
