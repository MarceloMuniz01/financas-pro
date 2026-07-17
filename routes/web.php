<?php

use App\Http\Controllers\ContactController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ImportPageController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Página pública
|--------------------------------------------------------------------------
*/

Route::inertia('/', 'welcome')
    ->name('home');

/*
|--------------------------------------------------------------------------
| Rotas autenticadas
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth',
    'verified',
])->group(function (): void {
    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    Route::inertia(
        '/dashboard',
        'dashboard'
    )->name('dashboard');

    /*
    |--------------------------------------------------------------------------
    | Importações
    |--------------------------------------------------------------------------
    |
    | GET /imports
    | Abre a página Inertia.
    |
    | GET /imports/list
    | Retorna as importações em JSON para o frontend.
    |
    | POST /imports
    | Recebe e processa o arquivo.
    |
    */

    Route::get(
        '/imports',
        [ImportPageController::class, 'index']
    )->name('imports.index');

    Route::post(
        '/imports',
        [ImportController::class, 'store']
    )->name('imports.store');

    Route::delete(
        '/imports/{import}',
        [ImportController::class, 'destroy']
    )
        ->whereNumber('import')
        ->name('imports.destroy');

    /*
    |--------------------------------------------------------------------------
    | Transações
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/transactions',
        [TransactionController::class, 'index']
    )->name('transactions.index');

    Route::patch(
        '/transactions/{transaction}/category',
        [TransactionController::class, 'updateCategory']
    )
        ->whereNumber('transaction')
        ->name('transactions.category.update');

    /*
    |--------------------------------------------------------------------------
    | Contatos
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/contacts',
        [ContactController::class, 'index']
    )->name('contacts.index');

    Route::post(
        '/contacts/merge-many',
        [ContactController::class, 'mergeMany']
    )->name('contacts.merge-many');

    Route::post(
        '/contacts/unmerge-many',
        [ContactController::class, 'unmergeMany']
    )->name('contacts.unmerge-many');

    Route::patch(
        '/contacts/{contact}',
        [ContactController::class, 'update']
    )
        ->whereNumber('contact')
        ->name('contacts.update');
});


/*
|--------------------------------------------------------------------------
| Configurações
|--------------------------------------------------------------------------
*/

require __DIR__ . '/settings.php';