<?php

declare(strict_types=1);

namespace App\Model\Crew;

use RuntimeException;


/** A refused change of the crew (Z-lístky); the message is shown to the organizer (Czech). */
final class CrewError extends RuntimeException
{
}
