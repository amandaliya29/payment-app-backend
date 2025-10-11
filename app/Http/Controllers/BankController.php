<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\UserBankAccounts;
use App\Services\UpiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
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
                    'digits_between:9,18',
                    'unique:user_bank_accounts,account_number'
                ],
                'ifsc_code' => [
                    'required',
                    'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'
                ],
                'account_type' => 'required|in:saving,current,salary,fixed_deposit',
                'pin_code' => [
                    'required',
                    'digits_between:4,6',
                    'confirmed', // pin_code_confirmation must match
                ],
            ]);

            // validation error
            if ($validation->fails()) {
                return $this->errorResponse($validation->errors()->first(), 422);
            }

            $user = Auth::user();

            $updated = false;
            if (!$user->aadhar_number) {
                $user->aadhar_number = $request->aadhaar_number;
                $updated = true;
            }
            if (!$user->pan_number) {
                $user->pan_number = $request->pan_number;
                $updated = true;
            }
            if ($updated) {
                $user->save();
            }

            $userBankAccount = UserBankAccounts::firstOrNew([
                'user_id' => Auth::id(),
                'bank_id' => $request->bank_id,
            ]);

            $userBankAccount->fill([
                'account_holder_name' => $request->account_holder_name,
                'account_number' => $request->account_number,
                'ifsc_code' => $request->ifsc_code,
                'account_type' => $request->account_type,
                'pin_code' => $request->pin_code,
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
                return $this->errorResponse(
                    $validation->errors()->first(),
                    422
                );
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
     * Check the balance of a specific bank account belonging to the authenticated user.
     *
     * @param int $id  The ID of the bank account to check.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkBalance($id)
    {
        try {
            $account = UserBankAccounts::where('id', $id)
                ->where('user_id', auth()->id())
                ->first();

            if (!$account) {
                return $this->errorResponse("Not Found", 404);
            }

            return $this->successResponse(['amount' => $account->amount], "Fetch successfully");
        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
        }
    }

    /**
     * Retrieve the list of bank accounts for the authenticated user.
     *
     * @return \Illuminate\Http\JsonResponse
     *
     * @throws \Throwable
     */
    public function accountList()
    {
        try {
            $account = UserBankAccounts::with('bank')
                ->where('user_id', auth()->id())
                ->get();

            if (!$account) {
                return $this->errorResponse("Please add a bank account.", 404);
            }

            return $this->successResponse($account, "Fetch successfully");
        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
        }
    }
}
