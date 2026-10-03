<?php

declare(strict_types=1);

namespace App\Model\Payment;

use RuntimeException;


/** Payment problem explained to the organizer; the message is Czech. */
final class PaymentError extends RuntimeException
{
}
