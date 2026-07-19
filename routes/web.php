<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\KnowledgeBaseController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ConsultationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Public/Home'))->name('home');
Route::get('/cara-kerja', fn () => Inertia::render('Public/HowItWorks'))->name('how-it-works');
Route::get('/about', fn () => Inertia::render('Public/About'))->name('about');
Route::post('/contact', [ContactController::class, 'store'])->name('contact.store');
Route::get('/konsultasi', [ConsultationController::class, 'create'])->name('consultation.start');
Route::get('/riwayat', [ConsultationController::class, 'history'])->name('consultation.history');
Route::post('/riwayat', [ConsultationController::class, 'checkHistory'])->name('consultation.history.check');
Route::post('/consultations', [ConsultationController::class, 'store'])->name('consultation.store');
Route::post('/consultations/precheck', [ConsultationController::class, 'precheck'])->name('consultation.precheck');
Route::get('/consultations/{sessionCode}/result', [ConsultationController::class, 'result'])->name('consultation.result');
Route::get('/consultations/{sessionCode}/export', [ConsultationController::class, 'export'])->name('consultation.export');
Route::get('/dataset-examples/{className}/{fileName}', [ConsultationController::class, 'datasetExampleImage'])->name('dataset.example-image');

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/dataset-mappings/{datasetMapping}/images', [KnowledgeBaseController::class, 'datasetMappingImages'])->name('dataset-mappings.images');
        Route::get('/{resource}', [KnowledgeBaseController::class, 'index'])->name('resource.index');
        Route::post('/{resource}', [KnowledgeBaseController::class, 'store'])->name('resource.store');
        Route::put('/{resource}/{id}', [KnowledgeBaseController::class, 'update'])->name('resource.update');
        Route::delete('/{resource}/{id}', [KnowledgeBaseController::class, 'destroy'])->name('resource.destroy');
    });
});

require __DIR__.'/auth.php';
