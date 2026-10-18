<?php

namespace App\Service;

/** A stale tab must refresh instead of ending or merging another session. */
final class IntakeActivityConflict extends \RuntimeException
{
}
