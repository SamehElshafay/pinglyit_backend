<?php

namespace Tests\Support;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * A mail transport that refuses everything, standing in for the way Resend
 * behaves in production while no sending domain is verified: it accepts
 * the account's own address and rejects every other recipient outright,
 * mid-request.
 *
 * Mail::fake() can't express this — it makes sending infallible, which is
 * exactly the assumption that let a rejected send reach users as a 500.
 */
final class ThrowingTransport extends AbstractTransport
{
    protected function doSend(SentMessage $message): void
    {
        throw new TransportException('You can only send testing emails to your own email address.');
    }

    public function __toString(): string
    {
        return 'throwing://';
    }
}
