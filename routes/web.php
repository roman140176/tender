<?php

use App\Http\Controllers\Admin\SourceCollectController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\CompanySourceController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\KeywordController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\SearchProfileController;
use App\Http\Controllers\TenderController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'can:manage-company'])->group(function () {
    Route::resource('profiles', SearchProfileController::class)
        ->except(['show'])
        ->parameters(['profiles' => 'profile']);
    Route::post('profiles/{profile}/keywords', [KeywordController::class, 'store'])->name('profiles.keywords.store');
    Route::delete('keywords/{keyword}', [KeywordController::class, 'destroy'])->name('keywords.destroy');
    Route::post('profiles/{profile}/activate', [SearchProfileController::class, 'activate'])->name('profiles.activate');
    Route::post('profiles/{profile}/deactivate', [SearchProfileController::class, 'deactivate'])->name('profiles.deactivate');
    Route::get('sources', [CompanySourceController::class, 'index'])->name('sources.index');
    Route::patch('sources/{source}', [CompanySourceController::class, 'update'])->name('sources.update');
    Route::get('company', [CompanySettingsController::class, 'edit'])->name('company.edit');
    Route::patch('company', [CompanySettingsController::class, 'update'])->name('company.update');
    Route::get('imports/template', [ImportController::class, 'template'])->name('imports.template');
    Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
    Route::post('imports', [ImportController::class, 'store'])->name('imports.store');
});

Route::middleware(['auth', 'can:view-tenders'])->group(function () {
    Route::get('tenders', [TenderController::class, 'index'])->name('tenders.index');
    Route::get('tenders/{companyTender}', [TenderController::class, 'show'])->name('tenders.show');
    Route::get('runs', [RunController::class, 'index'])->name('runs.index');
});

Route::middleware(['auth', 'can:manage-tender-workflow'])->group(function () {
    Route::patch('tenders/{companyTender}/status', [TenderController::class, 'updateStatus'])->name('tenders.status');
    Route::post('tenders/{companyTender}/comments', [TenderController::class, 'storeComment'])->name('tenders.comment');
    Route::patch('tenders/{companyTender}/assignee', [TenderController::class, 'assign'])->name('tenders.assign');
});

Route::middleware(['auth', 'can:superadmin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('sources', [SourceCollectController::class, 'index'])->name('sources.index');
    Route::post('sources/{source}/collect', [SourceCollectController::class, 'trigger'])->name('sources.collect');
});

require __DIR__.'/auth.php';
