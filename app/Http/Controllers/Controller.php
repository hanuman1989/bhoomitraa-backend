<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use PDOException;
use RuntimeException;

abstract class Controller
{
    protected function databaseFailure(QueryException|PDOException $exception, string $message): JsonResponse
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            return response()->json([
                'status' => false,
                'data' => null,
                'pagination' => null,
                'message' => 'A record with these details already exists.',
            ], 409);
        }

        report($exception);

        return response()->json([
            'status' => false,
            'data' => null,
            'pagination' => null,
            'message' => $message,
        ], 500);
    }

    /**
     * Reject invalid or incompatible stored password hashes as failed credentials.
     */
    protected function passwordMatches(#[\SensitiveParameter] string $password, string $hashedPassword): bool
    {
        try {
            return Hash::check($password, $hashedPassword);
        } catch (RuntimeException) {
            return false;
        }
    }
}
