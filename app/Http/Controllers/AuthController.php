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
}
