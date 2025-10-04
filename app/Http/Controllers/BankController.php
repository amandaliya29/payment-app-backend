<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\UserBankAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
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
    public function saveBankDetails(Request $request)
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

            $handles = ['@oksbi', '@okaxis', '@okicici', '@okhdfcbank', '@okyesbank'];

            $baseUpi = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $request->account_holder_name));
            if (empty($baseUpi)) {
                $baseUpi = 'user';
            }

            $handle = $handles[array_rand($handles)];
            do {
                $randomDigits = rand(1, 9999);
                $upiCandidate = $baseUpi . $randomDigits . $handle;
            } while (UserBankAccounts::where('upi_id', $upiCandidate)->exists());

            $userBankAccount->upi_id = $upiCandidate;
            $userBankAccount->save();

            return $this->successResponse($userBankAccount, "Save successfully");
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
}
