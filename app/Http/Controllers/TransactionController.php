<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\UserBankAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Models\UserBankCreditUpi;

class TransactionController extends Controller
{
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
            $transaction->type = $payload->type ?? 'bank';
            $transaction->amount = $payload->amount ?? 0;
            $transaction->status = $payload->status;
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
        try {
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
                'upi_id' => 'nullable|exists:user_bank_accounts,upi_id',
                'description' => 'nullable|string|max:255',
            ]);

            // validation error
            if ($validation->fails()) {
                return $this->errorResponse($validation->errors()->first(), 422);
            }

            if (empty($request->to_bank_account) && empty($request->upi_id)) {
                return $this->errorResponse("Receiver account or UPI ID is required", 422);
            }

            $receiverBankAccount = UserBankAccounts::where('id', $request->to_bank_account)
                ->orWhere('upi_id', $request->upi_id)
                ->first();

            if (!$receiverBankAccount) {
                return $this->errorResponse("Receiver bank account not found", 404);
            }

            $senderBankAccount = UserBankAccounts::find($request->from_bank_account);
            if ($senderBankAccount->amount < $request->amount) {
                return $this->errorResponse("Insufficient balance", 400);
            }

            DB::transaction(function () use ($senderBankAccount, $receiverBankAccount, $request) {
                $receiverBankAccount->increment('amount', $request->amount);
                $senderBankAccount->decrement('amount', $request->amount);

                $this->transaction((object) [
                    'type' => 'bank',
                    'amount' => $request->amount,
                    'description' => $request->description,
                    'from_account_id' => $request->from_bank_account,
                    'to_account_id' => $request->to_bank_account ?? null,
                    'to_upi_id' => $request->upi_id ?? null,
                ]);
            });

            return $this->successResponse([], "Send successfully");
        } catch (\Throwable $th) {
            $this->transaction((object) [
                'type' => 'bank',
                'amount' => $request->amount,
                'description' => $request->description,
                'from_account_id' => $request->from_bank_account,
                'to_account_id' => $request->to_bank_account ?? null,
                'to_upi_id' => $request->upi_id ?? null,
            ]);
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
        try {
            // validation
            $validation = Validator::make($request->all(), [
                'amount' => 'required|numeric|between:0,999999999999.99',
                'credit_upi' => 'required|exists:user_bank_credit_upis,upi_id',
                'to_bank_account' => 'nullable|exists:user_bank_accounts,id',
                'upi_id' => 'nullable|exists:user_bank_accounts,upi_id',
                'description' => 'nullable|string|max:255',
            ]);

            // validation error
            if ($validation->fails()) {
                return $this->errorResponse($validation->errors()->first(), 422);
            }

            $senderCreditUpi = UserBankCreditUpi::where('upi_id', $request->credit_upi)->first();

            if ($senderCreditUpi->user_id !== auth()->id()) {
                return $this->errorResponse('Unauthorized', 403);
            }

            if (empty($request->to_bank_account) && empty($request->upi_id)) {
                return $this->errorResponse("Receiver account or UPI ID is required", 422);
            }

            $receiverBankAccount = UserBankAccounts::where('id', $request->to_bank_account)
                ->orWhere('upi_id', $request->upi_id)
                ->first();

            if (!$receiverBankAccount) {
                return $this->errorResponse("Receiver bank account not found", 404);
            }

            if ($senderCreditUpi->available_credit < $request->amount) {
                return $this->errorResponse("Insufficient balance", 400);
            }

            DB::transaction(function () use ($senderCreditUpi, $receiverBankAccount, $request) {
                $receiverBankAccount->increment('amount', $request->amount);
                $senderCreditUpi->decrement('available_credit', $request->amount);

                $this->transaction((object) [
                    'type' => 'credit_upi',
                    'amount' => $request->amount,
                    'description' => $request->description,
                    'from_upi_id' => $request->credit_upi,
                    'to_account_id' => $request->to_bank_account ?? null,
                    'to_upi_id' => $request->upi_id ?? null,
                ]);
            });

            return $this->successResponse([], "Send successfully");
        } catch (\Throwable $th) {
            $this->transaction((object) [
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
     * Get recent 20 users the authenticated user has transacted with.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRecentTransactionUsers()
    {
        $userId = auth()->id();

        $userIds = Transaction::where(function ($query) use ($userId) {
            $query->whereHas('senderBank', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
                ->orWhereHas('senderCreditUpi', function ($q) use ($userId) {
                    $q->where('user_id', $userId);
                })
                ->orWhereHas('senderUpi', function ($q) use ($userId) {
                    $q->where('user_id', $userId);
                })
                ->orWhereHas('receiverBank', function ($q) use ($userId) {
                    $q->where('user_id', $userId);
                });
        })
            ->with([
                'senderBank.user',
                'senderCreditUpi.user',
                'senderUpi.user',
                'receiverBank.user'
            ])
            ->latest()
            ->get()
            ->map(function ($tx) use ($userId) {
                if ($tx->senderBank && $tx->senderBank->user_id != $userId) {
                    return $tx->senderBank->user;
                }
                if ($tx->senderCreditUpi && $tx->senderCreditUpi->user_id != $userId) {
                    return $tx->senderCreditUpi->user;
                }
                if ($tx->senderUpi && $tx->senderUpi->user_id != $userId) {
                    return $tx->senderUpi->user;
                }
                if ($tx->receiverBank && $tx->receiverBank->user_id != $userId) {
                    return $tx->receiverBank->user;
                }
                return null;
            })
            ->filter()
            ->unique('id')
            ->take(20);

        return response()->json($userIds->values());
    }
}
