<?php

declare(strict_types=1);

namespace App\Model\Ticket;

use RuntimeException;


/** An error of the entrance check; the message is shown to the organizer (Czech). */
final class TicketError extends RuntimeException
{
}
