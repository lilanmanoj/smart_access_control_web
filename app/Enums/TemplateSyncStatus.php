<?php

declare(strict_types=1);

namespace App\Enums;

/** The outcome a panel reports in `POST /fingerprint-template-syncs`. */
enum TemplateSyncStatus: string
{
    /** Every entry applied and every unlisted slot deleted. */
    case Completed = 'completed';

    /** Ran to the end, but some entries or deletions failed. */
    case Partial = 'partial';

    /** Stopped early. Templates written before the stop stay; nothing was deleted. */
    case Aborted = 'aborted';

    /** Whether the report ran far enough for its `slots_present` to be trusted. */
    public function ranToEnd(): bool
    {
        return $this !== self::Aborted;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
