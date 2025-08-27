<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use Illuminate\Http\Request;

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
            if (!auth()->check()) {
                return $this->errorResponse("Not Found", 404);
            }
            $bankList = Bank::all();
            return $this->successResponse(
                $bankList->toArray(),
                "Fetched Successfully"
            );
        } catch (\Throwable $th) {
            return $this->errorResponse("Internal Server Error", 500);
        }
    }
}
