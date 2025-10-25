<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserBankAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Kreait\Firebase\Auth as FirebaseAuth;

class AuthController extends Controller
{
    /**
     * Handle user login using Firebase authentication token.
     *
     * This method validates the request, verifies the Firebase ID token,
     * retrieves or creates the corresponding user in the database,
     * and issues a new Sanctum personal access token for authentication.
     *
     * @param \Illuminate\Http\Request $request Incoming HTTP request containing the Firebase ID token.
     * @param \Kreait\Firebase\Auth $auth Firebase authentication service instance.
     *
     * @return \Illuminate\Http\JsonResponse JSON response with status, token, and user information.
     *
     * @throws \Kreait\Firebase\Exception\Auth\FailedToVerifyToken If the Firebase ID token cannot be verified.
     * @throws \Throwable If any other unexpected error occurs during the login process.
     */
    public function login(Request $request, FirebaseAuth $auth)
    {
        try {
            // validation
            $validation = Validator::make($request->all(), [
                'token' => 'required|string',
                'fcm_token' => 'required|string'
            ]);

            // validation error
            if ($validation->fails()) {
                return $this->errorResponse($validation->errors()->first(), 403);
            }

            $verifiedIdToken = $auth->verifyIdToken($request->token);
            $uid = $verifiedIdToken->claims()->get('sub');
            $phone = $verifiedIdToken->claims()->get('phone_number');

            $user = User::where('firebase_uid', $uid)->first();
            if (!$user) {
                $user = new User();
                $user->firebase_uid = $uid;
                $user->phone = $phone;
                $user->save();
            }

            $user->has_bank_accounts = (bool) UserBankAccounts::where('user_id', $user->id)->exists();
            $token = $user->createToken('user-auth')->plainTextToken;

            $accessToken = $user->tokens()->latest()->first();
            $accessToken->fcm_token = $request->fcm_token;
            $accessToken->save();

            return $this->successResponse(
                ['token' => $token, 'user' => $user],
                "Login Successful"
            );
        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
        }
    }

    /**
     * Log out the authenticated user by deleting their current access token.
     *
     * This method revokes the current access token for the authenticated user, 
     * effectively logging them out. Returns a success response if the logout 
     * is successful, or an error response if an exception occurs.
     *
     * @return \Illuminate\Http\JsonResponse JSON response indicating the success or failure of the logout process.
     */
    public function logout()
    {
        try {
            auth()->user()->currentAccessToken()->delete();
            return $this->successResponse([], "Logout Successful");
        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
        }
    }

    /**
     * Search users by phone number or UPI ID.
     *
     * This method accepts a search string which can be either a phone number
     * or a UPI ID. It returns the list of users matching the criteria
     * along with their bank account details.
     *
     * @param \Illuminate\Http\Request $request The HTTP request instance containing the search parameter.
     *
     * @return \Illuminate\Http\JsonResponse Returns a JSON response with either the user data or an error message.
     */
    public function searchUsers(Request $request)
    {
        try {
            // validation
            $validation = Validator::make($request->all(), [
                'search' => 'required|string|max:100',
            ]);

            // validation error
            if ($validation->fails()) {
                return $this->errorResponse($validation->errors()->first(), 403);
            }

            $search = $request->input('search');

            $users = User::with([
                'bankAccounts' => function ($q) {
                    $q->select('id', 'user_id', 'bank_id', 'account_holder_name', 'upi_id', 'is_primary')
                        ->with('bank:id,name');
                }
            ])
                ->where(function ($query) use ($search) {
                    $query->where('phone', 'LIKE', "%{$search}%")
                        ->orWhere('name', 'LIKE', "%{$search}%")
                        ->whereHas('bankAccounts', fn($q) => $q->where('is_primary', 1))
                        ->orWhereHas('bankAccounts', fn($q) => $q->where('upi_id', 'LIKE', "%{$search}%"));
                })
                ->get();

            if ($users->isEmpty()) {
                return $this->errorResponse("No users found", 404);
            }

            $data = $users->map(function ($user) use ($search) {
                $bankAccount = $user->bankAccounts->first(function ($acc) use ($search) {
                    return stripos($acc->upi_id, $search) !== false;
                });

                if (!$bankAccount) {
                    $bankAccount = $user->bankAccounts->firstWhere('is_primary', 1);
                }

                if (!$bankAccount) {
                    return null;
                }

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'firebase_uid' => $user->firebase_uid,
                    'account_holder_name' => $bankAccount->account_holder_name ?? null,
                    'upi_id' => $bankAccount->upi_id ?? null,
                    'bank_name' => $bankAccount->bank->name ?? null,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ];
            })->filter();

            if ($data->isEmpty()) {
                return $this->errorResponse("No users found", 404);
            }

            return $this->successResponse(
                $data->toArray(),
                "Users fetched successfully"
            );

        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
        }
    }

    /**
     * Get single user details by identifier (from route param).
     *
     * @param string $identifier The identifier of the user to fetch.
     * @return \Illuminate\Http\JsonResponse
     */
    public function get($identifier)
    {
        try {
            // Check if the identifier looks like a UPI ID (contains '@')
            if (strpos($identifier, '@') !== false) {
                // Find user by UPI ID
                $bankAccount = UserBankAccounts::where('upi_id', $identifier)
                    ->select('id', 'user_id', 'account_holder_name', 'upi_id', 'is_primary')
                    ->first();

                if (!$bankAccount) {
                    return $this->errorResponse("User not found for given UPI ID", 404);
                }

                $user = User::find($bankAccount->user_id);
                if (!$user) {
                    return $this->errorResponse("User not found", 404);
                }

                $user->makeHidden(['firebase_uid', 'aadhar_number', 'pan_number']);
                $user->bank_account = $bankAccount; // attach only that UPI’s bank account

                return $this->successResponse($user->toArray(), "User fetched successfully (via UPI ID)");
            }

            // Otherwise, treat it as a user_id
            $user = User::with([
                'bankAccounts' => function ($query) {
                    $query->where('is_primary', true)
                        ->select('id', 'user_id', 'account_holder_name', 'upi_id', 'is_primary');
                }
            ])->find($identifier);

            if (!$user) {
                return $this->errorResponse("User not found", 404);
            }

            $user->makeHidden(['firebase_uid', 'aadhar_number', 'pan_number']);
            $primaryBankAccount = $user->bankAccounts->first();
            unset($user->bankAccounts);
            $user->bank_account = $primaryBankAccount;

            return $this->successResponse($user->toArray(), "User fetched successfully (via User ID)");
        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
        }
    }


    /**
     * Fetch the authenticated user's profile along with their bank accounts.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function profile()
    {
        try {
            $bankAccounts = UserBankAccounts::with('bank')->where('user_id', auth()->id())->get();

            return $this->successResponse([
                'user' => auth()->user(),
                'bank_accounts' => $bankAccounts,
            ], "Profile fetched successfully");
        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
        }
    }

    /**
     * Update the authenticated user's FCM token.
     *
     * This method validates the incoming request for an FCM token
     * and updates it for the authenticated user. Returns a success
     * response on success or an appropriate error response on failure.
     *
     * @param \Illuminate\Http\Request $request The incoming HTTP request containing the FCM token.
     * 
     * @return \Illuminate\Http\JsonResponse A JSON response indicating the status of the operation.
     */
    public function updateFcmToken(Request $request)
    {
        try {
            // validation
            $validation = Validator::make($request->all(), [
                'fcm_token' => 'required|string'
            ]);

            // validation error
            if ($validation->fails()) {
                return $this->errorResponse($validation->errors()->first(), 403);
            }

            $token = auth()->user()->currentAccessToken();

            if (!$token) {
                return $this->errorResponse("No active token found", 404);
            }

            $token->fcm_token = $request->fcm_token;
            $token->save();

            return $this->successResponse([], "FCM token updated successfully");
        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
        }
    }
}
