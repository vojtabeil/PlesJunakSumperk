<?php

declare(strict_types=1);

namespace App\Model\Reservation;

use RuntimeException;


/** An error caused by the user's action; the message is shown to the user (Czech). */
final class ReservationError extends RuntimeException
{
}
