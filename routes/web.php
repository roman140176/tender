<?php

use App\Http\Controllers\KeywordController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SearchProfileController;
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
});

require __DIR__.'/auth.php';
