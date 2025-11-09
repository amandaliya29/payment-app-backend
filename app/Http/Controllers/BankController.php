<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\IfscDetail;
use App\Models\UserBankAccounts;
use App\Services\UpiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
            $user = Auth::user();

            // validation
            $validation = Validator::make($request->all(), [
                'bank_id' => 'required|integer|exists:banks,id',
                'aadhaar_number' => [
                    $user->aadhar_number ? 'nullable' : 'required',
                    'digits:12',
                ],
                'pan_number' => [
                    $user->pan_number ? 'nullable' : 'required',
                    'regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/',
                ],
                'account_holder_name' => 'nullable|string|max:255',
                'account_number' => [
                    'required',
                    'digits_between:9,18',
                    'unique:user_bank_accounts,account_number'
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

            $updated = false;
            if (!$user->aadhar_number) {
                $user->aadhar_number = $request->aadhaar_number;
                $updated = true;
            }
            if (!$user->pan_number) {
                $user->pan_number = $request->pan_number;
                $updated = true;
            }
            if (!$user->name && $request->account_holder_name) {
                $user->name = $request->account_holder_name;
                $updated = true;
            }
            if ($updated) {
                $user->save();
            }

            $ifscDetail = IfscDetail::where('bank_id', $request->bank_id)
                ->inRandomOrder()
                ->first();

            $userBankAccount = new UserBankAccounts();

            $userBankAccount->fill([
                'user_id' => Auth::id(),
                'bank_id' => $request->bank_id,
                'account_holder_name' => $user->name,
                'account_number' => $request->account_number,
                'ifsc_detail_id' => $ifscDetail->id,
                'account_type' => $request->account_type,
                'pin_code' => $request->pin_code,
                'pin_code_length' => strlen((string) $request->pin_code),
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
     * This method verifies the user's identity and pin code before returning
     * the balance of the requested bank account. It ensures only the owner
     * of the account can access this information.
     *
     * @param \Illuminate\Http\Request $request
     *     The incoming request containing:
     *     - `account_id` (int): The ID of the user's bank account.
     *     - `pin_code` (int): The 4–6 digit PIN code for verification.
     *
     * @return \Illuminate\Http\JsonResponse
     *     A JSON response containing:
     *     - On success: The account balance.
     *     - On failure: An appropriate error message and HTTP status code.
     */
    public function checkBalance(Request $request)
    {
        try {
            // validation
            $validation = Validator::make($request->all(), [
                'account_id' => 'required|integer|exists:user_bank_accounts,id',
                'pin_code' => 'required|digits_between:4,6',
            ]);

            // validation error
            if ($validation->fails()) {
                return $this->errorResponse(
                    $validation->errors()->first(),
                    422
                );
            }

            $account = UserBankAccounts::where('id', $request->account_id)
                ->where('user_id', auth()->id())
                ->first();

            if (!$account) {
                return $this->errorResponse("Not Found", 404);
            }

            if (!Hash::check($request->pin_code, $account->pin_code)) {
                return $this->errorResponse("Invalid PIN code", 403);
            }

            $account->makeVisible('amount');
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
                ->get()
                ->map(function ($account) {
                    // Mask account number to show only last 4 digits
                    if (!empty($account->account_number)) {
                        $account->account_number = 'XXXX XXXX ' . substr($account->account_number, -4);
                    }
                    return $account;
                });

            return $this->successResponse($account, "Fetch successfully");
        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
        }
    }

    /**
     * Retrieve all bank accounts associated with the authenticated user.
     *
     * @param \Illuminate\Http\Request $request The current HTTP request instance.
     * @param int $id The ID parameter (not currently used in the method).
     *
     * @return \Illuminate\Http\JsonResponse Returns a JSON response containing the user's bank accounts
     *                                       with related bank and IFSC details if successful,
     *                                       or an error message in case of an exception.
     */
    public function account(Request $request)
    {
        try {
            $validation = Validator::make($request->all(), [
                'account_id' => 'required|integer|exists:user_bank_accounts,id',
                'pin_code' => 'required|digits_between:4,6',
            ]);

            // validation error
            if ($validation->fails()) {
                return $this->errorResponse(
                    $validation->errors()->first(),
                    422
                );
            }

            $account = UserBankAccounts::with(['bank', 'ifscDetail'])
                ->where('id', $request->account_id)
                ->where('user_id', auth()->id())
                ->first();

            if (!$account) {
                return $this->errorResponse("Not found", 404);
            }

            if (!Hash::check($request->pin_code, $account->pin_code)) {
                return $this->errorResponse("Invalid PIN code", 403);
            }

            return $this->successResponse($account, "Fetch successfully");
        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
        }
    }

    /**
     * Retrieve all IFSC details with their associated bank information.
     *
     * This method fetches a list of IFSC details along with the related
     * bank data. It handles any exceptions that occur during the process
     * and returns a standardized API response.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function searchIfscDetails()
    {
        try {
            $ifscDetails = IfscDetail::with('bank')->get();
            return $this->successResponse($ifscDetails, "Fetch successfully");
        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
        }
    }
}
