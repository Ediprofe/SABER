<?php

use App\Http\Controllers\Admin\ExamPipelineUploadController;
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
