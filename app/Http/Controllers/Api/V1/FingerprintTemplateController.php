<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\ListFingerprintTemplatesRequest;
use App\Services\TemplateSyncService;
use Illuminate\Http\JsonResponse;

/**
 * GET /fingerprint-templates — the template set this panel should hold.
 *
 * The panel mirrors the answer: it writes what it lacks and deletes every
 * occupied slot that is not listed. Device credentials only — this route sits
 * behind the device middleware and nothing on the dashboard reaches it — and
 * it is the only response anywhere that carries template bytes.
 */
class FingerprintTemplateController extends Controller
{
    public function __construct(
        private readonly TemplateSyncService $sync,
    ) {}

    public function index(ListFingerprintTemplatesRequest $request): JsonResponse
    {
        $page = $this->sync->page($request->device(), $request->syncParameters());

        return response()
            ->json($page)
            // Biometric data must not sit in any cache between here and the panel.
            ->header('Cache-Control', 'no-store');
    }
}
