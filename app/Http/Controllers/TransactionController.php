<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\User;
use App\Models\UserBankAccounts;
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
                'required',
                Rule::exists('user_bank_accounts', 'id')->where(function ($query) {
                    $query->where('user_id', auth()->id());
                }),
            ],
            'to_bank_account' => 'nullable|exists:user_bank_accounts,id',
            'mobile_no' => 'nullable|exists:users,phone',
            'upi_id' => 'nullable|exists:user_bank_accounts,upi_id',
            'description' => 'nullable|string|max:255',
            'pin_code' => 'required|digits_between:4,6',
        ]);

        // validation error
        if ($validation->fails()) {
            return $this->errorResponse($validation->errors()->first(), 422);
        }

        if (empty($request->to_bank_account) && empty($request->upi_id) && empty($request->mobile_no)) {
            return $this->errorResponse("Receiver account or UPI ID is required", 422);
        }

        try {

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
                    'phone' => $receiver->phone,
                    'bank_account_number' => substr($receiverBankAccount->account_number, -4)
                ]
            ], "Pay successfully");
        } catch (\Throwable $th) {
            $this->transaction((object) [
                'transaction_id' => $transactionId,
                'type' => 'bank',
                'amount' => $request->amount,
                'description' => $request->description,
                'from_account_id' => $request->from_bank_account,
                'to_account_id' => $request->to_bank_account ?? null,
                'to_upi_id' => $request->upi_id ?? null,
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
                    'phone' => $receiver->phone,
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
     * Get authenticated user's transactions with sender & receiver details.
     *
     * This method retrieves a paginated list of transactions for the logged-in user.
     * It supports optional filtering by status, type, mode, min_amount, and max_amount.
     * Each transaction is transformed to include:
     * - transaction details (id, amount, status, type, mode, description, created_at)
     * - sender details (from bank account or UPI/Credit UPI)
     * - receiver details (to bank account)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     *
     * @queryParam status string Filter by transaction status. Example: completed
     * @queryParam type string Filter by transaction type. Example: bank
     * @queryParam mode string Filter by mode. Example: debit
     * @queryParam min_amount float Filter by minimum amount. Example: 100
     * @queryParam max_amount float Filter by maximum amount. Example: 1000
     *
     * @response 200 {
     *   "current_page": 1,
     *   "data": [
     *     {
     *       "transaction": {
     *         "id": 15,
     *         "amount": "500.00",
     *         "status": "completed",
     *         "type": "bank",
     *         "mode": "debit",
     *         "description": "Payment for order #123",
     *         "created_at": "2025-10-05T10:30:00.000000Z"
     *       },
     *       "sender": {
     *         "id": 2,
     *         "name": "Alice",
     *         "account": "1234567890"
     *       },
     *       "receiver": {
     *         "id": 3,
     *         "name": "Bob",
     *         "account": "9876543210"
     *       }
     *     }
     *   ],
     *   "per_page": 10,
     *   "total": 1
     * }
     */
    public function getTransactions(Request $request)
    {
        $query = Transaction::with([
            'user',
            'senderBank.user',
            'receiverBank.user',
            'senderUpi.user',
            'senderCreditUpi.user'
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('mode')) {
            $query->where('mode', $request->mode);
        }

        if ($request->filled('min_amount')) {
            $query->where('amount', '>=', $request->min_amount);
        }
        if ($request->filled('max_amount')) {
            $query->where('amount', '<=', $request->max_amount);
        }

        $transactions = $query->latest()->paginate(10);

        $data = $transactions->getCollection()->map(function ($tx) {
            $sender = null;
            if ($tx->senderBank) {
                $sender = [
                    'id' => $tx->senderBank->user->id ?? null,
                    'name' => $tx->senderBank->user->name ?? null,
                    'account' => $tx->senderBank->account_number ?? null,
                ];
            } elseif ($tx->senderCreditUpi) {
                $sender = [
                    'id' => $tx->senderCreditUpi->user->id ?? null,
                    'name' => $tx->senderCreditUpi->user->name ?? null,
                    'upi' => $tx->senderCreditUpi->upi_id ?? null,
                ];
            } elseif ($tx->senderUpi) {
                $sender = [
                    'id' => $tx->senderUpi->user->id ?? null,
                    'name' => $tx->senderUpi->user->name ?? null,
                    'upi' => $tx->senderUpi->upi_id ?? null,
                ];
            }

            $receiver = null;
            if ($tx->receiverBank) {
                $receiver = [
                    'id' => $tx->receiverBank->user->id ?? null,
                    'name' => $tx->receiverBank->user->name ?? null,
                    'account' => $tx->receiverBank->account_number ?? null,
                ];
            }

            return [
                'transaction' => [
                    'id' => $tx->id,
                    'amount' => $tx->amount,
                    'status' => $tx->status,
                    'type' => $tx->type,
                    'mode' => $tx->mode,
                    'description' => $tx->description,
                    'created_at' => $tx->created_at,
                ],
                'sender' => $sender,
                'receiver' => $receiver,
            ];
        });

        $transactions->setCollection($data);
        return response()->json($transactions);
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
}
