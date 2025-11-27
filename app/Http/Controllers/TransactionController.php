<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserBankAccounts;
use App\Models\UserNpciCreditUpi;
use App\Services\ApplicationNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Models\UserBankCreditUpi;

class TransactionController extends Controller
{
    protected $notificationService;

    /**
     * TransactionController constructor.
     *
     * @param ApplicationNotificationService $notificationService
     */
    public function __construct(ApplicationNotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Store a transaction record.
     *
     * @param object $payload Transaction payload object with keys:
     *                        - type (string: 'bank'|'credit_upi')
     *                        - mode (string: 'debit'|'credit')
     *                        - amount (float|int)
     *                        - description (string|null)
     *                        - from_account_id (int|null)
     *                        - from_upi_id (string|null)
     *                        - to_account_id (int|null)
     *                        - to_upi_id (string|null)
     * @param string $status Transaction status ('pending'|'completed'|'failed')
     *
     * @return void
     */
    public function transaction(object $payload, string $status = 'completed')
    {
        try {
            $transaction = new Transaction();
            $transaction->transaction_id = $payload->transaction_id ?? $this->generateTransactionId();
            $transaction->type = $payload->type ?? 'bank';
            $transaction->amount = $payload->amount ?? 0;
            $transaction->status = $status;
            $transaction->description = $payload->description ?? null;

            if (!empty($payload->from_account_id)) {
                $transaction->from_account_id = $payload->from_account_id;
            } elseif (!empty($payload->from_upi_id)) {
                $transaction->from_upi_id = $payload->from_upi_id;
            } else {
                throw new \Exception("Invalid sender details");
            }

            if (!empty($payload->to_account_id)) {
                $transaction->to_account_id = $payload->to_account_id;
            } elseif (!empty($payload->to_upi_id)) {
                $transaction->to_upi_id = $payload->to_upi_id;
            } elseif (!empty($payload->to_bank_id)) {
                $transaction->to_bank_id = $payload->to_bank_id;
            } else {
                throw new \Exception("Invalid receiver details");
            }

            $transaction->save();
        } catch (\Throwable $th) {
            Log::error("Transaction failed: " . $th->getMessage());
        }
    }

    /**
     * Generate a unique transaction ID.
     *
     * @return string
     */
    public function generateTransactionId(): string
    {
        return 'TXN' . now()->format('YmdHis') . mt_rand(1000, 9999);
    }

    /**
     * Transfer money between bank accounts or via UPI.
     *
     * Validates the request, ensures the sender account belongs to the authenticated user,
     * checks balance availability, and processes the transfer atomically using a DB transaction.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     *
     * @throws \Throwable
     */
    public function sendMoney(Request $request)
    {
        $transactionId = $this->generateTransactionId();

        // validation
        $validation = Validator::make($request->all(), [
            'amount' => 'required|numeric|between:0,999999999999.99',
            'from_bank_account' => [
                'nullable',
                Rule::exists('user_bank_accounts', 'id')->where(function ($query) {
                    $query->where('user_id', auth()->id());
                }),
            ],
            'credit_upi' => [
                'nullable',
                function ($attribute, $value, $fail) {
                    $existsInBank = DB::table('user_bank_credit_upis')
                        ->where('upi_id', $value)
                        ->exists();

                    $existsInNpci = DB::table('user_npci_credit_upis')
                        ->where('upi_id', $value)
                        ->exists();

                    if (!$existsInBank && !$existsInNpci) {
                        $fail('The selected credit UPI is invalid.');
                    }
                },
            ],
            'to_bank_account' => 'nullable|exists:user_bank_accounts,id',
            'upi_id' => 'nullable|exists:user_bank_accounts,upi_id',
            'mobile_no' => 'nullable|exists:users,phone',
            'description' => 'nullable|string|max:255',
            'pin_code' => 'required|digits_between:4,6',
        ]);

        if ($validation->fails()) {
            return $this->errorResponse($validation->errors()->first(), 422);
        }

        // Must have at least one source: bank or credit UPI
        if (empty($request->from_bank_account) && empty($request->credit_upi)) {
            return $this->errorResponse("Sender account or Credit UPI is required", 422);
        }

        // Must have at least one receiver
        if (empty($request->to_bank_account) && empty($request->upi_id) && empty($request->mobile_no)) {
            return $this->errorResponse("Receiver account or UPI ID is required", 422);
        }

        try {
            // Determine receiver
            if ($request->mobile_no) {
                $receiver = User::where('phone', $request->mobile_no)->first();
                $receiverBankAccount = UserBankAccounts::where('user_id', $receiver->id)
                    ->where('is_primary', true)
                    ->first();
            } else {
                $receiverBankAccount = UserBankAccounts::where('id', $request->to_bank_account)
                    ->orWhere('upi_id', $request->upi_id)
                    ->first();
            }

            if (!$receiverBankAccount) {
                return $this->errorResponse("Receiver bank account not found", 404);
            }

            $transactionType = '';
            $senderName = auth()->user()->name;

            // 🏦 CASE 1: Sending from normal bank account
            if ($request->from_bank_account) {
                $senderBankAccount = UserBankAccounts::find($request->from_bank_account);

                if (!$senderBankAccount) {
                    return $this->errorResponse('Sender bank account not found', 404);
                }

                if ($receiverBankAccount->id == $senderBankAccount->id) {
                    return $this->errorResponse("Invalid receiver account", 400);
                }

                if (!Hash::check($request->pin_code, $senderBankAccount->pin_code)) {
                    return $this->errorResponse("Invalid Pin", 400);
                }

                if ($senderBankAccount->amount < $request->amount) {
                    return $this->errorResponse("Insufficient balance", 400);
                }

                DB::transaction(function () use ($senderBankAccount, $receiverBankAccount, $transactionId, $request) {
                    $receiverBankAccount->increment('amount', $request->amount);
                    $senderBankAccount->decrement('amount', $request->amount);

                    $this->transaction((object) [
                        'transaction_id' => $transactionId,
                        'type' => 'bank',
                        'amount' => $request->amount,
                        'description' => $request->description,
                        'from_account_id' => $request->from_bank_account,
                        'to_account_id' => $request->to_bank_account ?? null,
                        'to_upi_id' => $request->upi_id ?? null,
                    ]);
                });

                $transactionType = 'bank';
            }

            // 💳 CASE 2: Sending via Credit UPI
            elseif ($request->credit_upi) {
                $senderCreditUpi = UserBankCreditUpi::where('upi_id', $request->credit_upi)->first();

                if (!$senderCreditUpi) {
                    $senderCreditUpi = UserNpciCreditUpi::where('upi_id', $request->credit_upi)->first();
                }

                if (!$senderCreditUpi) {
                    return $this->errorResponse("Credit UPI not found", 404);
                }

                if ($senderCreditUpi->user_id !== auth()->id()) {
                    return $this->errorResponse('Unauthorized', 403);
                }

                if (!Hash::check($request->pin_code, $senderCreditUpi->pin_code)) {
                    return $this->errorResponse("Invalid Pin", 400);
                }

                if ($senderCreditUpi->available_credit < $request->amount) {
                    return $this->errorResponse("Insufficient balance", 400);
                }

                DB::transaction(function () use ($senderCreditUpi, $receiverBankAccount, $transactionId, $request) {
                    $receiverBankAccount->increment('amount', $request->amount);
                    $senderCreditUpi->decrement('available_credit', $request->amount);

                    $this->transaction((object) [
                        'transaction_id' => $transactionId,
                        'type' => 'credit_upi',
                        'amount' => $request->amount,
                        'description' => $request->description,
                        'from_upi_id' => $request->credit_upi,
                        'to_account_id' => $request->to_bank_account ?? null,
                        'to_upi_id' => $request->upi_id ?? null,
                    ]);
                });

                $transactionType = 'credit_upi';
            }

            // ✅ Send Notification to Receiver
            $receiver = User::find($receiverBankAccount->user_id);
            $title = "Money Received";
            $message = "You received ₹{$request->amount} from " . $senderName;

            $notificationData = [
                'screen' => 'TransactionSuccessScreen',
                'transaction_id' => $transactionId,
            ];

            $this->notificationService->sendNotificationToCurrentToken(
                $receiver->id,
                $title,
                $message,
                $notificationData
            );

            return $this->successResponse([
                'transaction_id' => $transactionId,
                'type' => $transactionType,
                'amount' => $request->amount,
                'timestamp' => now(),
                'receiver' => [
                    'name' => $receiver->name,
                    'account_holder_name' => $receiverBankAccount->account_holder_name,
                    'bank_account_number' => substr($receiverBankAccount->account_number, -4),
                ]
            ], "Pay successfully");

        } catch (\Throwable $th) {
            // Fallback transaction record in case of failure
            $type = $request->from_bank_account ? 'bank' : 'credit_upi';
            $this->transaction((object) [
                'transaction_id' => $transactionId,
                'type' => $type,
                'amount' => $request->amount,
                'description' => $request->description,
                'from_account_id' => $request->from_bank_account ?? null,
                'from_upi_id' => $request->credit_upi ?? null,
                'to_account_id' => $request->to_bank_account ?? null,
                'to_upi_id' => $request->upi_id ?? null,
            ], 'failed');

            return $this->errorResponse("Internal Server Error", 500);
        }
    }

    /**
     * Handle payment transaction from Bank Account or Credit UPI to Bank.
     *
     * Validates request, ensures correct sender source (bank / credit UPI),
     * ensures PIN verification, balance/credit availability checks,
     * and logs a transaction record.
     *
     * @param \Illuminate\Http\Request $request
     *      - amount: float, required, transaction amount  
     *      - from_bank_account: int|null, sender bank account ID  
     *      - credit_upi: string|null, sender credit UPI  
     *      - to_bank: int, receiver bank ID  
     *      - description: string|null, transaction note  
     *      - pin_code: int, required, 4–6 digit PIN  
     *
     * @return \Illuminate\Http\JsonResponse
     *
     * @throws \Throwable
     */
    public function payToBank(Request $request)
    {
        $transactionId = $this->generateTransactionId();

        // validation
        $validation = Validator::make($request->all(), [
            'amount' => 'required|numeric|between:0,999999999999.99',
            'from_bank_account' => [
                'nullable',
                Rule::exists('user_bank_accounts', 'id')->where(function ($query) {
                    $query->where('user_id', auth()->id());
                }),
            ],
            'credit_upi' => [
                'nullable',
                function ($attribute, $value, $fail) {
                    $existsInBank = DB::table('user_bank_credit_upis')
                        ->where('upi_id', $value)
                        ->exists();

                    $existsInNpci = DB::table('user_npci_credit_upis')
                        ->where('upi_id', $value)
                        ->exists();

                    if (!$existsInBank && !$existsInNpci) {
                        $fail('The selected credit UPI is invalid.');
                    }
                },
            ],
            'to_bank_credit_upi' => 'required|exists:user_bank_credit_upis,id',
            'description' => 'nullable|string|max:255',
            'pin_code' => 'required|digits_between:4,6',
        ]);

        if ($validation->fails()) {
            return $this->errorResponse($validation->errors()->first(), 422);
        }

        // Must have at least one source: bank or credit UPI
        if (empty($request->from_bank_account) && empty($request->credit_upi)) {
            return $this->errorResponse("Sender account or Credit UPI is required", 422);
        }

        // Must have at least one receiver
        if (empty($request->to_bank_credit_upi)) {
            return $this->errorResponse("Bank selection is required.", 422);
        }

        try {
            // Determine receiver
            $receiverBank = UserBankCreditUpi::with('bank')->find($request->to_bank_credit_upi);

            if (!$receiverBank) {
                return $this->errorResponse("Bank Not found", 404);
            }

            if ($receiverBank->available_credit + $request->amount > $receiverBank->credit_limit) {
                return $this->errorResponse('Credit limit exceeded. Unable to process payment.', 422);
            }

            $transactionType = '';

            // 🏦 CASE 1: Sending from normal bank account
            if ($request->from_bank_account) {
                $senderBankAccount = UserBankAccounts::find($request->from_bank_account);

                if (!$senderBankAccount) {
                    return $this->errorResponse('Sender bank account not found', 404);
                }

                if (!Hash::check($request->pin_code, $senderBankAccount->pin_code)) {
                    return $this->errorResponse("Invalid Pin", 400);
                }

                if ($senderBankAccount->amount < $request->amount) {
                    return $this->errorResponse("Insufficient balance", 400);
                }

                DB::transaction(function () use ($senderBankAccount, $receiverBank, $transactionId, $request) {
                    $senderBankAccount->decrement('amount', $request->amount);
                    $receiverBank->increment('available_credit', $request->amount);

                    $this->transaction((object) [
                        'transaction_id' => $transactionId,
                        'type' => 'bank',
                        'amount' => $request->amount,
                        'description' => $request->description,
                        'from_account_id' => $request->from_bank_account,
                        'to_bank_id' => $receiverBank->bank->bank_id ?? null,
                    ]);
                });

                $transactionType = 'bank';
            }

            // 💳 CASE 2: Sending via Credit UPI
            elseif ($request->credit_upi) {
                $senderCreditUpi = UserBankCreditUpi::with('bank')->where('upi_id', $request->credit_upi)->first();

                if (!$senderCreditUpi) {
                    $senderCreditUpi = UserNpciCreditUpi::where('upi_id', $request->credit_upi)->first();
                }

                if (!$senderCreditUpi) {
                    return $this->errorResponse("Credit UPI not found", 404);
                }

                if ($senderCreditUpi->bank_account_id != $receiverBank->bank_account_id) {
                    return $this->errorResponse('Transaction not allowed for this bank', 403);
                }

                if ($senderCreditUpi->user_id !== auth()->id()) {
                    return $this->errorResponse('Unauthorized', 403);
                }

                if (!Hash::check($request->pin_code, $senderCreditUpi->pin_code)) {
                    return $this->errorResponse("Invalid Pin", 400);
                }

                if ($senderCreditUpi->available_credit < $request->amount) {
                    return $this->errorResponse("Insufficient balance", 400);
                }

                DB::transaction(function () use ($senderCreditUpi, $receiverBank, $transactionId, $request) {
                    $senderCreditUpi->decrement('available_credit', $request->amount);
                    $receiverBank->increment('available_credit', $request->amount);

                    $this->transaction((object) [
                        'transaction_id' => $transactionId,
                        'type' => 'credit_upi',
                        'amount' => $request->amount,
                        'description' => $request->description,
                        'from_upi_id' => $request->credit_upi,
                        'to_bank_id' => $receiverBank->bank->bank_id ?? null,
                    ]);
                });

                $transactionType = 'credit_upi';
            }

            return $this->successResponse([
                'transaction_id' => $transactionId,
                'type' => $transactionType,
                'amount' => $request->amount,
                'timestamp' => now(),
                'receiver_bank' => $receiverBank
            ], "Pay successfully");

        } catch (\Throwable $th) {
            // Fallback transaction record in case of failure
            $type = $request->from_bank_account ? 'bank' : 'credit_upi';
            $this->transaction((object) [
                'transaction_id' => $transactionId,
                'type' => $type,
                'amount' => $request->amount,
                'description' => $request->description,
                'from_account_id' => $request->from_bank_account ?? null,
                'from_upi_id' => $request->credit_upi ?? null,
                'to_bank_id' => $receiverBank->bank->user_id ?? null,
            ], 'failed');

            return $this->errorResponse("Internal Server Error", 500);
        }
    }

    /**
     * Handle payment via Credit/UPI.
     *
     * Validates the request, checks user authentication and balance,
     * then transfers the specified amount from the sender's credit/UPI
     * to the receiver's bank account or UPI ID.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function payWithCreditUpi(Request $request)
    {
        $transactionId = $this->generateTransactionId();

        // validation
        $validation = Validator::make($request->all(), [
            'amount' => 'required|numeric|between:0,999999999999.99',
            'credit_upi' => 'required|exists:user_bank_credit_upis,upi_id',
            'to_bank_account' => 'nullable|exists:user_bank_accounts,id',
            'upi_id' => 'nullable|exists:user_bank_accounts,upi_id',
            'description' => 'nullable|string|max:255',
            'pin_code' => 'required|digits_between:4,6',
            'mobile_no' => 'nullable|exists:users,phone',
        ]);

        // validation error
        if ($validation->fails()) {
            return $this->errorResponse($validation->errors()->first(), 422);
        }

        try {

            $senderCreditUpi = UserBankCreditUpi::where('upi_id', $request->credit_upi)->first();

            if ($senderCreditUpi->user_id !== auth()->id()) {
                return $this->errorResponse('Unauthorized', 403);
            }

            if (empty($request->to_bank_account) && empty($request->upi_id) && empty($request->mobile_no)) {
                return $this->errorResponse("Receiver account or UPI ID is required", 422);
            }

            if ($request->mobile_no) {
                $receiver = User::where('phone', $request->mobile_no)->first();
                $receiverBankAccount = UserBankAccounts::where('user_id', $receiver->id)
                    ->where('is_primary', true)
                    ->first();
            } else {
                $receiverBankAccount = UserBankAccounts::where('id', $request->to_bank_account)
                    ->orWhere('upi_id', $request->upi_id)
                    ->first();
            }

            if (!$receiverBankAccount) {
                return $this->errorResponse("Receiver bank account not found", 404);
            }

            if (!Hash::check($request->pin_code, $senderCreditUpi->pin_code)) {
                return $this->errorResponse("Invalid Pin", 400);
            }

            if ($senderCreditUpi->available_credit < $request->amount) {
                return $this->errorResponse("Insufficient balance", 400);
            }

            DB::transaction(function () use ($senderCreditUpi, $receiverBankAccount, $transactionId, $request) {
                $receiverBankAccount->increment('amount', $request->amount);
                $senderCreditUpi->decrement('available_credit', $request->amount);

                $this->transaction((object) [
                    'transaction_id' => $transactionId,
                    'type' => 'credit_upi',
                    'amount' => $request->amount,
                    'description' => $request->description,
                    'from_upi_id' => $request->credit_upi,
                    'to_account_id' => $request->to_bank_account ?? null,
                    'to_upi_id' => $request->upi_id ?? null,
                ]);
            });

            $receiver = User::find($receiverBankAccount->user_id);

            $title = "Money Received";
            $message = "You received ₹{$request->amount} from " . auth()->user()->name;

            $notificationData = [
                'screen' => 'TransactionSuccessScreen',
                'transaction_id' => $transactionId,
            ];

            $this->notificationService->sendNotificationToCurrentToken(
                $receiver->id,
                $title,
                $message,
                $notificationData
            );

            return $this->successResponse([
                'transaction_id' => $transactionId,
                'amount' => $request->amount,
                'timestamp' => now(),
                'receiver' => [
                    'name' => $receiver->name,
                    'account_holder_name' => $receiverBankAccount->account_holder_name,
                    'bank_account_number' => substr($receiverBankAccount->account_number, -4)
                ]
            ], "Pay successfully");
        } catch (\Throwable $th) {
            $this->transaction((object) [
                'transaction_id' => $transactionId,
                'type' => 'credit_upi',
                'amount' => $request->amount,
                'description' => $request->description,
                'from_upi_id' => $request->credit_upi,
                'to_account_id' => $request->to_bank_account ?? null,
                'to_upi_id' => $request->upi_id ?? null,
            ], 'failed');
            return $this->errorResponse("Internal Server Error", 500);
        }
    }

    /**
     * Get authenticated user's transaction history with filters and pagination.
     *
     * Filters:
     *  - status: pending, completed, failed
     *  - payment_method: array of bank account IDs
     *  - date_range: 24h, 7d, 14d, 1m, 3m
     *  - amount_range: upto_1000, 1000_10000, 10000_15000, 15000_25000, 25000_50000, 50000_75000, 75000_100000
     *  - payment_type: send_money, receive_money, self_transfer
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTransactions(Request $request)
    {
        try {
            $authUserId = auth()->id();

            $query = Transaction::with([
                'senderBank.user',
                'receiverBank.user',
                'senderCreditUpi.user',
                'receiverUpi.user',
            ])->where(function ($q) use ($authUserId) {
                $q->whereHas('senderBank', fn($sub) => $sub->where('user_id', $authUserId))
                    ->orWhereHas('receiverBank', fn($sub) => $sub->where('user_id', $authUserId))
                    ->orWhereHas('senderCreditUpi', fn($sub) => $sub->where('user_id', $authUserId))
                    ->orWhereHas('receiverUpi', fn($sub) => $sub->where('user_id', $authUserId));
            });

            // 1. Status filter
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // 2. Payment method filter
            if ($request->filled('payment_method')) {
                $query->where(function ($q) use ($request) {
                    $q->whereIn('from_account_id', (array) $request->payment_method)
                        ->orWhereIn('to_account_id', (array) $request->payment_method);
                });
            }

            // 3. Date range filter
            if ($request->filled('date_range')) {
                $now = now();
                switch ($request->date_range) {
                    case '24h':
                        $from = $now->subHours(24);
                        break;
                    case '7d':
                        $from = $now->subDays(7);
                        break;
                    case '14d':
                        $from = $now->subDays(14);
                        break;
                    case '1m':
                        $from = $now->subMonth();
                        break;
                    case '3m':
                        $from = $now->subMonths(3);
                        break;
                    default:
                        $from = null;
                }
                if ($from) {
                    $query->where('created_at', '>=', $from);
                }
            }

            // 4. Amount range filter
            if ($request->filled('amount_range')) {
                $range = $request->amount_range;
                switch ($range) {
                    case 'upto_1000':
                        $query->where('amount', '<=', 1000);
                        break;
                    case '1000_10000':
                        $query->whereBetween('amount', [1000, 10000]);
                        break;
                    case '10000_15000':
                        $query->whereBetween('amount', [10000, 15000]);
                        break;
                    case '15000_25000':
                        $query->whereBetween('amount', [15000, 25000]);
                        break;
                    case '25000_50000':
                        $query->whereBetween('amount', [25000, 50000]);
                        break;
                    case '50000_75000':
                        $query->whereBetween('amount', [50000, 75000]);
                        break;
                    case '75000_100000':
                        $query->whereBetween('amount', [75000, 100000]);
                        break;
                }
            }

            // 5. Payment type filter
            if ($request->filled('payment_type')) {
                $type = $request->payment_type;
                $query->where(function ($q) use ($type, $authUserId) {
                    if ($type === 'send_money') {
                        // transactions where auth user is the sender BUT NOT where receiver also belongs to auth user (exclude self transfers)
                        $q->where(function ($sub) use ($authUserId) {
                            $sub->whereHas('senderBank', fn($s) => $s->where('user_id', $authUserId))
                                ->orWhereHas('senderCreditUpi', fn($s) => $s->where('user_id', $authUserId));
                        })
                            // Exclude receiver owned by auth user (bank or upi) => avoids counting self-transfers as send_money
                            ->whereDoesntHave('receiverBank', fn($s) => $s->where('user_id', $authUserId))
                            ->whereDoesntHave('receiverUpi', fn($s) => $s->where('user_id', $authUserId));
                    } elseif ($type === 'receive_money') {
                        // transactions where auth user is the receiver BUT NOT where sender also belongs to auth user (exclude self transfers)
                        $q->where(function ($sub) use ($authUserId) {
                            $sub->whereHas('receiverBank', fn($s) => $s->where('user_id', $authUserId))
                                ->orWhereHas('receiverUpi', fn($s) => $s->where('user_id', $authUserId));
                        })
                            // Exclude sender owned by auth user (bank or upi)
                            ->whereDoesntHave('senderBank', fn($s) => $s->where('user_id', $authUserId))
                            ->whereDoesntHave('senderCreditUpi', fn($s) => $s->where('user_id', $authUserId))
                            // also check senderUpi if you have that relation (you referenced it elsewhere)
                            ->whereDoesntHave('senderUpi', fn($s) => $s->where('user_id', $authUserId));
                    } elseif ($type === 'self_transfer') {
                        $q->where(function ($q2) use ($authUserId) {
                            $q2->where(function ($sub) use ($authUserId) {
                                $sub->whereHas('senderBank', fn($sub2) => $sub2->where('user_id', $authUserId))
                                    ->whereHas('receiverBank', fn($sub2) => $sub2->where('user_id', $authUserId));
                            })
                                ->orWhere(function ($sub) use ($authUserId) {
                                    $sub->whereHas('senderCreditUpi', fn($sub2) => $sub2->where('user_id', $authUserId))
                                        ->whereHas('receiverUpi', fn($sub2) => $sub2->where('user_id', $authUserId));
                                })
                                ->orWhere(function ($sub) use ($authUserId) {
                                    // bank -> upi
                                    $sub->whereHas('senderBank', fn($sub2) => $sub2->where('user_id', $authUserId))
                                        ->whereHas('receiverUpi', fn($sub2) => $sub2->where('user_id', $authUserId));
                                })
                                ->orWhere(function ($sub) use ($authUserId) {
                                    // upi -> bank
                                    $sub->whereHas('senderCreditUpi', fn($sub2) => $sub2->where('user_id', $authUserId))
                                        ->whereHas('receiverBank', fn($sub2) => $sub2->where('user_id', $authUserId));
                                });
                        });
                    }
                });
            }

            // Pagination (20 per page)
            $transactions = $query->latest()->paginate(20);

            // Format & group by month
            $grouped = $transactions->getCollection()
                ->map(function ($tx) use ($authUserId) {
                    $authIsSender = false;
                    if (
                        (($tx->senderBank && $tx->senderBank->user_id == $authUserId) ||
                            ($tx->senderCreditUpi && $tx->senderCreditUpi->user_id == $authUserId))
                    ) {
                        $authIsSender = true;
                    }

                    // Determine counterparty
                    $counterparty = null;
                    if ($authIsSender) {
                        if ($tx->receiverBank) {
                            $counterparty = [
                                'id' => $tx->receiverBank->user->id ?? null,
                                'name' => $tx->receiverBank->user->name ?? null,
                                'account' => $tx->receiverBank->account_number ?? null,
                            ];
                        } elseif ($tx->receiverUpi) {
                            $counterparty = [
                                'id' => $tx->receiverUpi->user->id ?? null,
                                'name' => $tx->receiverUpi->user->name ?? null,
                                'upi' => $tx->receiverUpi->upi_id ?? null,
                            ];
                        }
                    } else {
                        if ($tx->senderBank) {
                            $counterparty = [
                                'id' => $tx->senderBank->user->id ?? null,
                                'name' => $tx->senderBank->user->name ?? null,
                                'account' => $tx->senderBank->account_number ?? null,
                            ];
                        } elseif ($tx->senderCreditUpi) {
                            $counterparty = [
                                'id' => $tx->senderCreditUpi->user->id ?? null,
                                'name' => $tx->senderCreditUpi->user->name ?? null,
                                'upi' => $tx->senderCreditUpi->upi_id ?? null,
                            ];
                        } elseif ($tx->senderUpi) {
                            $counterparty = [
                                'id' => $tx->senderUpi->user->id ?? null,
                                'name' => $tx->senderUpi->user->name ?? null,
                                'upi' => $tx->senderUpi->upi_id ?? null,
                            ];
                        }
                    }

                    // Determine mode: credit or debit
                    $mode = $authIsSender ? 'debit' : 'credit';

                    return [
                        'transaction_id' => $tx->transaction_id,
                        'amount' => $tx->amount,
                        'status' => $tx->status,
                        'type' => $tx->type,
                        'mode' => $mode,
                        'created_at' => $tx->created_at,
                        'counterparty' => $counterparty,
                        'month' => $tx->created_at->format('F Y'),
                    ];
                });

            $transactions->setCollection($grouped);

            return $this->successResponse($transactions, "Fetch successfully");
        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
        }
    }

    /**
     * Retrieve a specific transaction by its transaction ID.
     *
     * This method fetches a transaction record along with its related sender and receiver
     * bank or UPI details based on the provided transaction ID.
     *
     * @param  string|int  $id  The unique transaction identifier.
     * @return \Illuminate\Http\JsonResponse  JSON response containing transaction data or an error message.
     *
     * @throws \Throwable
     */
    public function getTransaction($id)
    {
        try {
            $transaction = Transaction::with([
                'senderBank.user',
                'senderBank.bank',
                'receiverBank.user',
                'receiverBank.bank',
                'senderCreditUpi.user',
                'senderCreditUpi.bank',
                'receiverUpi.user',
                'receiverUpi.bank',
            ])->where('transaction_id', $id)->first();

            if (!$transaction) {
                return $this->errorResponse("Not Found", 404);
            }

            $relations = [
                'senderBank',
                'receiverBank',
                'senderCreditUpi',
                'receiverUpi',
            ];

            foreach ($relations as $relation) {
                if ($transaction->$relation) {
                    $accountNumber = $transaction->$relation->account_number;
                    if ($accountNumber && strlen($accountNumber) > 4) {
                        $transaction->$relation->account_number = substr($accountNumber, -4);
                    }

                    if ($transaction->$relation->user) {
                        $transaction->$relation->user->makeHidden([
                            'firebase_uid',
                            'aadhar_number',
                            'pan_number',
                            'email',
                            'updated_at',
                            'created_at'
                        ]);
                    }

                    if ($relation === 'senderCreditUpi') {
                        $transaction->$relation->makeHidden([
                            'credit_limit',
                            'available_credit'
                        ]);
                    }
                }
            }

            $authUserId = auth()->id();

            $roles = [
                'sender' => [$transaction->senderBank, $transaction->senderCreditUpi],
                'receiver' => [$transaction->receiverBank, $transaction->receiverUpi],
            ];

            $authRole = null;

            foreach ($roles as $role => $sources) {
                foreach ($sources as $source) {
                    if ($source && $source->user_id === $authUserId) {
                        $authRole = $role;
                        break 2;
                    }
                }
            }

            if (!$authRole) {
                return $this->errorResponse("Invalid transaction", 400);
            }

            $response = $transaction->toArray();
            $response['auth_role'] = $authRole;

            return $this->successResponse($response, "Fetch successfully");
        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
        }
    }

    /**
     * Get latest 20 unique users the authenticated user has sent money to (receiver only).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRecentTransactionUsers()
    {
        try {
            $authUserId = auth()->id();

            $transactions = Transaction::with([
                'receiverBank.user',
                'receiverUpi.user',
            ])
                ->where(function ($q) use ($authUserId) {
                    $q->whereHas('senderBank', fn($s) => $s->where('user_id', $authUserId))
                        ->orWhereHas('senderCreditUpi', fn($s) => $s->where('user_id', $authUserId))
                        ->orWhereHas('senderUpi', fn($s) => $s->where('user_id', $authUserId));
                })
                ->where(function ($q) use ($authUserId) {
                    $q->whereDoesntHave('receiverBank', fn($r) => $r->where('user_id', $authUserId))
                        ->whereDoesntHave('receiverUpi', fn($r) => $r->where('user_id', $authUserId));
                })
                ->latest()
                ->get();

            $uniqueReceivers = $transactions
                ->map(function ($tx) {
                    if ($tx->receiverBank && $tx->receiverBank->user) {
                        return $tx->receiverBank->user->only(['id', 'name', 'email', 'phone']);
                    }
                    if ($tx->receiverUpi && $tx->receiverUpi->user) {
                        return $tx->receiverUpi->user->only(['id', 'name', 'email', 'phone']);
                    }
                    return null;
                })
                ->filter()
                ->unique('id')
                ->take(20)
                ->values();

            return $this->successResponse($uniqueReceivers, "Fetch successfully");
        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
        }
    }
}
