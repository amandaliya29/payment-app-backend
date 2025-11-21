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
            Route::post('search', 'searchUsers');
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
            Route::post('account/get', 'account');
            Route::post('details', 'saveBankDetails');
            Route::post('save/pin', 'updatePin'); //update PIN
            Route::post('balance', 'checkBalance');
            Route::post('qr/scan', 'scan');
            Route::get('ifsc/search', 'searchIfscDetails');
        }
    );

Route::controller(BankCreditUpiController::class)
    ->middleware(['auth:sanctum'])
    ->prefix('credit-upi')
    ->group(
        function () {
            Route::get('bank/list', 'bankList');
            Route::post('activate', 'activate');
            Route::post('npci-activate', 'npciActivate');
            Route::post('save/pin', 'savePin');
            Route::post('update/pin', 'updatePin');
            Route::post('npci/save/pin', 'saveNpciPin');
            Route::post('npci/update/pin', 'updateNpciPin');
            Route::get('npci/details', 'detailsNpci');
        }
    );

Route::controller(TransactionController::class)
    ->middleware(['auth:sanctum'])
    ->group(
        function () {
            Route::post('pay', 'sendMoney');
            Route::post('pay-bank', 'payToBank');
            Route::get('credit-upi/pay', 'payWithCreditUpi');
            Route::get('transactions/recent-users', 'getRecentTransactionUsers');
            Route::post('transactions', 'getTransactions');
            Route::get('transaction/get/{id}', 'getTransaction');
        }
    );