<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\KnowledgeBaseController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/{resource}', [KnowledgeBaseController::class, 'index'])->name('resource.index');
        Route::post('/{resource}', [KnowledgeBaseController::class, 'store'])->name('resource.store');
        Route::put('/{resource}/{id}', [KnowledgeBaseController::class, 'update'])->name('resource.update');
        Route::delete('/{resource}/{id}', [KnowledgeBaseController::class, 'destroy'])->name('resource.destroy');
    });
});

require __DIR__.'/auth.php';
