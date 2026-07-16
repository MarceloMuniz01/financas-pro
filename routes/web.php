<?php

use App\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ImportPageController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\ContactController;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

Route::get('/imports', [ImportController::class, 'index']);


Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', function () {
        return inertia('dashboard');
    })->name('dashboard');

    Route::get('/imports', [ImportPageController::class, 'index'])
        ->name('imports.index');
});

Route::get('/transactions', [TransactionController::class, 'index'])
    ->name('transactions.index');

Route::patch(
    '/transactions/{transaction}/category',
    [TransactionController::class, 'updateCategory']
)->name('transactions.category.update');

/*
|--------------------------------------------------------------------------
| Contatos
|--------------------------------------------------------------------------
*/

Route::get(
    '/contacts',
    [ContactController::class, 'index']
)->name('contacts.index');

Route::patch(
    '/contacts/{contact}',
    [ContactController::class, 'update']
)->name('contacts.update');

Route::patch(
    '/contacts/{contact}/dismiss-similarity',
    [ContactController::class, 'dismissSimilarity']
)->name('contacts.similarity.dismiss');

Route::post(
    '/contacts/{contact}/merge',
    [ContactController::class, 'merge']
)->name('contacts.merge');

require __DIR__ . '/settings.php';

