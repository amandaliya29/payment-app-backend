<?php

namespace App\Http\Controllers;

use App\Models\UserBankAccounts;
use App\Models\UserBankCreditUpi;
use App\Services\UpiService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Kreait\Firebase\Auth as FirebaseAuth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Illuminate\Support\Arr;
use Illuminate\Http\JsonResponse;

/**
 * Class BankCreditUpiController
 *
 * Handles activation of Bank Credit UPI for users.
 */
class BankCreditUpiController extends Controller
{

    /**
     * Predefined credit amounts for random selection.
     *
     * @var array<int>
     */
    private array $creditAmounts = [20000, 35000, 50000, 75000, 90000, 100000];

    /**
     * Activate a bank credit UPI account for the authenticated user.
     *
     * @param Request      $request The HTTP request instance.
     * @param FirebaseAuth $auth    The Firebase authentication service.
     *
     * @return JsonResponse Returns JSON response indicating success or failure.
     */
    public function activate(Request $request, FirebaseAuth $auth, UpiService $upiService)
    {
        try {
            // validation
            $validation = Validator::make($request->all(), [
                'token' => 'required|string',
                'bank_account' => [
                    'required',
                    'integer',
                    Rule::exists('user_bank_accounts', 'id')->where(function ($query) {
                        $query->where('user_id', auth()->id());
                    }),
                ],
            ]);

            // validation error
            if ($validation->fails()) {
                return $this->errorResponse($validation->errors()->first(), 403);
            }

            $verifiedIdToken = $auth->verifyIdToken($request->token);
            $uid = $verifiedIdToken->claims()->get('sub');

            if (auth()->user()->firebase_uid == $uid) {
                throw new Exception("User not recognized");
            }

            $userBankAccount = UserBankAccounts::find($request->bank_account);

            $userBankCreditUpi = new UserBankCreditUpi();
            $userBankCreditUpi->user_id = auth()->id();
            $userBankCreditUpi->upi_id = $upiService->generate($userBankAccount->account_holder_name);
            $userBankCreditUpi->bank_account_id = $request->bank_account;
            $randomLimit = Arr::random($this->creditAmounts);
            $userBankCreditUpi->credit_limit = $randomLimit;
            $userBankCreditUpi->available_credit = $randomLimit;
            $userBankCreditUpi->save();

            return $this->successResponse(
                $userBankAccount,
                "Activate Successful"
            );
        } catch (FailedToVerifyToken $e) {
            return $this->errorResponse("OTP not verified", 401);
        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
        }
    }

    /**
     * Save or update the PIN code for a user's linked bank credit/UPI account.
     *
     * This method validates the provided request to ensure:
     *  - The specified bank_credit_upi ID exists and belongs to the authenticated user.
     *  - The provided pin_code is between 4–6 digits and matches its confirmation field.
     *
     * Upon successful validation, the pin_code is securely hashed and saved
     * to the corresponding UserBankCreditUpi record.
     *
     * @param \Illuminate\Http\Request $request
     *     The HTTP request containing:
     *       - bank_credit_upi (int): The ID of the user's bank credit/UPI record.
     *       - pin_code (string): The new PIN code to be set.
     *       - pin_code_confirmation (string): The confirmation for the new PIN.
     *
     * @return \Illuminate\Http\JsonResponse
     *     Returns a success response on completion or an error response on validation failure
     *     or internal server error.
     *
     * @throws \Throwable
     *     Throws an exception if any unexpected error occurs during execution.
     */
    private function savePin(Request $request)
    {
        try {
            // validation
            $validation = Validator::make($request->all(), [
                'bank_credit_upi' => [
                    'required',
                    Rule::exists('user_bank_credit_upis', 'id')->where(function ($query) {
                        $query->where('user_id', auth()->id());
                    }),
                ],
                'pin_code' => [
                    'required',
                    'digits_between:4,6',
                    'confirmed', // pin_code_confirmation must match
                ],
            ]);

            // validation error
            if ($validation->fails()) {
                return $this->errorResponse($validation->errors()->first(), 403);
            }

            $userBankCreditUpi = UserBankCreditUpi::find($request->bank_credit_upi);
            $userBankCreditUpi->pin_code = $request->pin_code;
            $userBankCreditUpi->save();

            return $this->successResponse(
                [],
                "Pin set Successful"
            );
        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
        }
    }

    /**
     * Fetch the user's bank accounts with masked account numbers.
     *
     * @return \Illuminate\Http\JsonResponse
     *
     * This method retrieves the user's bank accounts along with related bank
     * and bankCreditUpi details, masks the account number to show only
     * the last 4 digits (e.g., XXXX XXXX 1234), and returns the formatted data.
     */
    public function bankList()
    {
        try {
            $userBankAccounts = UserBankAccounts::with(['bank', 'bankCreditUpi'])
                ->where('user_id', auth()->id())
                ->select(['id', 'bank_id', 'account_number'])
                ->get()
                ->map(function ($account) {
                    // Mask account number to show only last 4 digits
                    if (!empty($account->account_number)) {
                        $account->account_number = 'XXXX XXXX ' . substr($account->account_number, -4);
                    }

                    // Add status based on pin_code presence
                    if ($account->bankCreditUpi) {
                        $account->bankCreditUpi->status = $account->bankCreditUpi->pin_code ? 'active' : 'inactive';
                    }

                    return $account;
                });

            return $this->successResponse(
                $userBankAccounts,
                "Fetched Successfully"
            );
        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
        }
    }
}
