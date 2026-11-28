<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;

/**
 * GET /timezones — the timezones a tenant or device may be given.
 *
 * Exactly the list the `timezone:all` validation rule accepts, so the
 * dashboard's dropdown can never offer a value the API then refuses. Browsers
 * disagree with PHP on some names (Chrome still says Asia/Calcutta), which is
 * why the list comes from here rather than from Intl.
 */
class TimezoneController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $now = new DateTimeImmutable('now');

        $zones = array_map(function (string $id) use ($now): array {
            $seconds = (new DateTimeZone($id))->getOffset($now);
            $sign = $seconds < 0 ? '-' : '+';
            $seconds = abs($seconds);

            return [
                'id' => $id,
                // The offset today; it moves with daylight saving.
                'offset' => sprintf('%s%02d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60)),
            ];
        }, DateTimeZone::listIdentifiers(DateTimeZone::ALL));

        return response()->json(['data' => $zones])
            ->header('Cache-Control', 'private, max-age=3600');
    }
}
