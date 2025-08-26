<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

abstract class Controller
{
    /**
     * Return a successful JSON response.
     *
     * @param array<mixed> $data     The payload data (default empty array).
     * @param string|array $messages Success message(s).
     * @param int          $code     HTTP status code (default 200).
     *
     * @return JsonResponse
     */
    public function successResponse(
        array $data = [],
        string|array $messages = 'Success',
        int $code = 200
    ): JsonResponse {
        return response()->json([
            'status' => true,
            'data' => $data,
            'messages' => $messages,
        ], $code);
    }

    /**
     * Return an error JSON response.
     *
     * @param string|array $messages Error message(s).
     * @param int          $code     HTTP status code (default 404).
     *
     * @return JsonResponse
     */
    public function errorResponse(
        string|array $messages = 'Not Found',
        int $code = 404
    ): JsonResponse {
        return response()->json([
            'status' => false,
            'messages' => $messages,
        ], $code);
    }
}
