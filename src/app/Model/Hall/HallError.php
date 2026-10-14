<?php

declare(strict_types=1);

namespace App\Model\Hall;

use RuntimeException;


/** A hall plan that cannot be used; the message is shown to the organizer (Czech). */
final class HallError extends RuntimeException
{
}
