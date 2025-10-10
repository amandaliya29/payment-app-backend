<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\BankCreditUpiController;
use App\Http\Controllers\TransactionController;
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
            Route::get('profile', 'profile');
            Route::get('search', 'searchUsers');
            Route::post('fcm/update', 'updateFcmToken');
        }
    );

Route::controller(BankController::class)
    ->middleware(['auth:sanctum'])
    ->prefix('bank')
    ->group(
        function () {
            Route::get('list', 'list');
            Route::get('account/list', 'accountList');
            Route::post('details', 'saveBankDetails');
            Route::get('balance/{account_id}', 'checkBalance');
            Route::post('qr/scan', 'scan');
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

Route::controller(TransactionController::class)
    ->middleware(['auth:sanctum'])
    ->group(
        function () {
            Route::get('pay', 'sendMoney');
            Route::get('credit-upi/pay', 'payWithCreditUpi');
        }
    );