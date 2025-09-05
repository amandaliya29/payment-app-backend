<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::controller(AuthController::class)->group(
    function () {
        Route::post('login', 'login');

        Route::middleware(['auth:sanctum'])->group(function () {
            Route::get('users/search', 'searchUsers');
        });
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
