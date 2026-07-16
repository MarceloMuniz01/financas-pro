<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ImportController;

Route::get('/imports', [ImportController::class, 'index']);

Route::post('/imports', [ImportController::class, 'store']);