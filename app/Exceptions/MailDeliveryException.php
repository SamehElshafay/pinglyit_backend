<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * A required email (TransactionalMailer::deliver()) didn't make it out.
 *
 * Renders as a 502 rather than the bare 500 an uncaught transport
 * exception used to produce: the request itself was fine, an upstream we
 * depend on refused it — and the client gets a sentence it can show a
 * user instead of whatever the transport happened to throw.
 */
class MailDeliveryException extends RuntimeException
{
    public function __construct(public readonly string $recipient, ?Throwable $previous = null)
    {
        parent::__construct("Couldn't deliver mail to {$recipient}.", 0, $previous);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => "We couldn't send that email just now — check the address is right, or try again in a moment.",
        ], 502);
    }
}
