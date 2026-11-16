<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/** A business rule refused the action; rendered as a JSON error the UI shows as-is. */
class WorkflowException extends Exception
{
    public function __construct(string $message, private int $status = 422)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $this->getMessage()], $this->status);
    }
}
