<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

abstract class Controller
{
    /**
     * Return a successful JSON response.
     *
     * @param array<mixed>|object $data     The payload data (default empty array).
     * @param string|array        $messages Success message(s).
     * @param int                 $code     HTTP status code (default 200).
     *
     * @return JsonResponse
     */
    public function successResponse(
        array|object $data = [],
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

    /**
     * Upload a file to storage.
     *
     * This method stores an uploaded file either in the public or private storage.
     *
     * @param string $folder The folder/path where the file should be stored.
     * @param string $key The request key name of the uploaded file.
     * @param string $visibility Optional. File visibility: 'public' or 'private'. Default is 'public'.
     * @return string|null Returns the stored file path relative to the disk, or null if no file uploaded.
     */
    public function upload(string $folder, string $key, string $visibility = 'public'): ?string
    {
        if (!request()->hasFile($key)) {
            return null;
        }

        $disk = $visibility === 'private' ? 'local' : 'public';
        $file = request()->file($key);

        return Storage::disk($disk)->putFile($folder, $file, $visibility);
    }
}
