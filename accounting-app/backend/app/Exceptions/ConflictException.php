<?php

namespace App\Exceptions;

use App\Support\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;

/**
 * A request that conflicts with the current state of the resource.
 *
 * This exists because a validation failure and a state conflict are genuinely
 * different things and collapsing them produces misleading responses.
 *
 * The concrete case is posting a journal twice. The controller's early check
 * catches the ordinary retry and returns 409, but two concurrent POSTs can both
 * pass that check: the second one blocks on the journal row lock inside
 * JournalPostingService, and only then discovers the journal is already posted.
 * That second caller has done nothing wrong - it asked for a valid transition
 * that someone else completed first - so it should receive the same 409 the
 * early path returns, not a 422 that says its input was invalid.
 *
 * Rendering is defined on the exception itself rather than in bootstrap/app.php
 * because the exception owns the status code and the message; keeping them
 * together means a future conflict case cannot be registered with a status that
 * contradicts the one its message describes.
 *
 * The class is generic rather than journal-specific: nothing about the HTTP
 * mapping is particular to journals, and a second caller should not need a new
 * exception class to signal a conflict.
 */
class ConflictException extends Exception
{
    /**
     * @param  array<string, array<int, string>>  $errors  optional field-level detail
     */
    public function __construct(
        string $message,
        private readonly array $errors = [],
        private readonly int $status = 409,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error(
            message: $this->getMessage(),
            errors: $this->errors === [] ? null : $this->errors,
            status: $this->status,
        );
    }
}
