<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Serves the dashboard's single HTML document.
 *
 * Runtime configuration is assembled here rather than in the template so it can
 * be reasoned about — and so the same production image can be deployed against
 * any host. The websocket endpoint differs per deployment, and rebuilding the
 * front-end bundle to change a hostname is not a deployment story.
 */
class SpaController extends Controller
{
    public function __invoke(): View
    {
        return view('app', [
            'runtimeConfig' => [
                'appName' => config('app.name'),
                'reverb' => [
                    // access.realtime, not broadcasting.connections.reverb:
                    // that one is the address the *server* publishes to, which
                    // is a different host behind a reverse proxy.
                    //
                    // A null key disables realtime; the dashboard falls back to
                    // polling and says so in the sidebar.
                    'key' => config('access.realtime.key'),
                    'host' => config('access.realtime.host') ?: request()->getHost(),
                    'port' => (int) config('access.realtime.port'),
                    'scheme' => config('access.realtime.scheme'),
                ],
            ],
        ]);
    }
}
