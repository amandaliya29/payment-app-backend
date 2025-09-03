<?php

namespace App\Http\Controllers;

use App\Models\User;
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
            ]);

            // validation error
            if ($validation->fails()) {
                return $this->errorResponse("Validation Error", 403);
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

            $token = $user->createToken('user-auth')->plainTextToken;
            return $this->successResponse(
                ['token' => $token, 'user' => $user],
                "Login Successful"
            );
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
                return $this->errorResponse("Validation Error", 403);
            }

            $search = $request->input('search');

            $users = User::with([
                'bankAccounts' => function ($query) {
                    $query->select('id', 'user_id', 'bank_id', 'account_holder_name', 'upi_id')
                        ->whereNotNull('aadhaar_number')
                        ->whereNotNull('pan_number')
                        ->where('is_primary', 1);
                }
            ])
                ->where(function ($query) use ($search) {
                    $query->where('phone', 'LIKE', "%{$search}%")
                        ->WhereHas('bankAccounts')
                        ->orWhereHas('bankAccounts', function ($q) use ($search) {
                            $q->whereNotNull('aadhaar_number')
                                ->whereNotNull('pan_number')
                                ->where('is_primary', 1)
                                ->where('upi_id', 'LIKE', "%{$search}%");
                        });
                })
                ->get();

            if ($users->isEmpty()) {
                return $this->errorResponse("No users found", 404);
            }

            return $this->successResponse(
                $users->toArray(),
                "Users fetched successfully"
            );

        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
        }
    }

}
