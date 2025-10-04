<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\UserBankAccounts;
use App\Models\UserBankCreditUpi;
use App\Services\UpiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Zxing\QrReader;

class BankController extends Controller
{
    /**
     * Retrieve the list of all banks.
     *
     * This method checks if the user is authenticated before fetching
     * all bank records. If the user is not authenticated, it returns
     * a "Not Found" error response. On success, it returns the list
     * of banks. In case of any exception, it returns an internal server
     * error response.
     *
     * @return \Illuminate\Http\JsonResponse JSON response containing
     *                                       the bank list or an error message.
     */
    public function list()
    {
        try {
            $bankList = Bank::all();
            return $this->successResponse(
                $bankList->toArray(),
                "Fetched Successfully"
            );
        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
        }
    }

    /**
     * Save or update the user's bank details.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveBankDetails(Request $request, UpiService $upiService)
    {
        try {
            // validation
            $validation = Validator::make($request->all(), [
                'bank_id' => 'required|integer|exists:banks,id',
                'aadhaar_number' => 'required|digits:12',
                'pan_number' => [
                    'required',
                    'regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/'
                ],
                'account_holder_name' => 'required|string|max:255',
                'account_number' => [
                    'required',
                    'digits_between:9,18'
                ],
                'ifsc_code' => [
                    'required',
                    'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'
                ]
            ]);

            // validation error
            if ($validation->fails()) {
                return $this->errorResponse("Validation Error", 422);
            }

            $userBankAccount = UserBankAccounts::firstOrNew([
                'user_id' => Auth::id(),
                'bank_id' => $request->bank_id,
            ]);

            $userBankAccount->fill([
                'aadhaar_number' => $request->aadhaar_number,
                'pan_number' => $request->pan_number,
                'account_holder_name' => $request->account_holder_name,
                'account_number' => $request->account_number,
                'ifsc_code' => $request->ifsc_code,
            ]);

            if (!UserBankAccounts::where('user_id', Auth::id())->exists()) {
                $userBankAccount->is_primary = true;
            }

            $userBankAccount->upi_id = $upiService->generate($request->account_number);
            $userBankAccount->save();

            return $this->successResponse([], "Save successfully");
        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
        }
    }

    /**
     * Scan a QR code from an uploaded image.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function scan(Request $request)
    {
        try {
            // validation
            $validation = Validator::make($request->all(), [
                'image' => 'required|image|mimes:png,jpg,jpeg',
            ]);

            // validation error
            if ($validation->fails()) {
                return $this->errorResponse("Validation Error", 422);
            }

            $filePath = $this->upload('qr', 'image', 'private');
            $absolutePath = storage_path('app/private/' . $filePath);

            $qrReader = new QrReader($absolutePath);
            $text = $qrReader->text();

            Storage::disk('local')->delete($filePath);
            if (!$text) {
                return $this->errorResponse("No valid QR code found in the image.", 422);
            }

            return $this->successResponse(['code' => $text], "Fetch successfully");
        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
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
            ]);

            // validation error
            if ($validation->fails()) {
                return $this->errorResponse("Validation Error", 422);
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
            });

            return $this->successResponse([], "Send successfully");
        } catch (\Throwable $th) {
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
            ]);

            // validation error
            if ($validation->fails()) {
                return $this->errorResponse("Validation Error", 422);
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
            });

            return $this->successResponse([], "Send successfully");
        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
        }
    }
}
