<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An error in the shape the firmware expects:
 *
 *     { "error": { "code": "otp_expired", "message": "Human readable" } }
 *
 * The `code` is the contract; the message is for humans reading logs.
 */
class ApiException extends Exception
{
    /**
     * @param  array<string, mixed>  $context  Extra fields merged into the error object.
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function unauthorized(string $message = 'Invalid device credentials.'): self
    {
        // Deliberately one message for every credential failure: which of the
        // three header values was wrong is not something a caller gets to
        // learn by probing.
        return new self('unauthorized', $message, 401);
    }

    public static function forbidden(string $code, string $message): self
    {
        return new self($code, $message, 403);
    }

    public static function notFound(string $code, string $message): self
    {
        return new self($code, $message, 404);
    }

    public static function rateLimited(string $message = 'Too many requests.', int $retryAfter = 60): self
    {
        return new self('rate_limited', $message, 429, ['retry_after' => $retryAfter]);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => array_merge([
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
            ], $this->context),
        ], $this->status);
    }
}
