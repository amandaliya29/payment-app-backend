<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankController;
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')
    ->get('logout', [AuthController::class, 'logout']);

Route::controller(AuthController::class)
    ->middleware(['auth:sanctum'])
    ->prefix('user')
    ->group(
        function () {
            Route::get('get/{id}', 'get');
            Route::get('search', 'searchUsers');
        }
    );

Route::controller(BankController::class)
    ->middleware(['auth:sanctum'])
    ->prefix('bank')
    ->group(
        function () {
            Route::get('list', 'list');
            Route::post('details', 'saveBankDetails');
        }
    );
