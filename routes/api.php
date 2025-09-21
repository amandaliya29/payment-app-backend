<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\BankCreditUpiController;
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthController::class, 'login']);

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

Route::controller(BankCreditUpiController::class)
    ->middleware(['auth:sanctum'])
    ->prefix('credit-upi')
    ->group(
        function () {
            Route::get('bank/list', 'bankList');
            Route::get('bank/activate', 'activate');
        }
    );