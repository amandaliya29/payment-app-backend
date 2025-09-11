<?php

namespace App\Http\Controllers;

use App\Models\UserBankCreditUpi;
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
    public function activate(Request $request, FirebaseAuth $auth)
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
                return $this->errorResponse("Validation Error", 403);
            }

            $auth->verifyIdToken($request->token);

            $userBankCreditUpi = new UserBankCreditUpi();
            $userBankCreditUpi->bank_account_id = $request->bank_account;
            $randomLimit = Arr::random($this->creditAmounts);
            $userBankCreditUpi->credit_limit = $randomLimit;
            $userBankCreditUpi->available_credit = $randomLimit;
            $userBankCreditUpi->save();

            return $this->successResponse(
                [],
                "Activate Successful"
            );
        } catch (FailedToVerifyToken $e) {
            return $this->errorResponse("OTP not verified", 401);
        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
        }
    }
}
