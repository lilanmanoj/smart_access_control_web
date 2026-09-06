<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\WebhookEndpointResource;
use App\Models\WebhookEndpoint;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WebhookEndpointController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WebhookEndpoint::class);

        return WebhookEndpointResource::collection(
            WebhookEndpoint::query()->latest('id')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', WebhookEndpoint::class);

        $validated = $request->validate([
            // https only: these payloads name people and doors.
            'url' => ['required', 'url:https', 'max:500'],
            'description' => ['nullable', 'string', 'max:200'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(WebhookEndpoint::AVAILABLE_EVENTS)],
        ]);

        $secret = Str::random(48);

        $endpoint = WebhookEndpoint::create([
            ...$validated,
            'secret' => $secret,
            'is_active' => true,
        ]);

        $this->audit->log('webhook.created', $endpoint, after: ['url' => $endpoint->url]);

        return response()->json([
            'endpoint' => new WebhookEndpointResource($endpoint),
            // Shown once. The receiver needs it to verify the X-Signature
            // header on every delivery.
            'secret' => $secret,
        ], 201);
    }

    public function update(Request $request, WebhookEndpoint $endpoint): WebhookEndpointResource
    {
        $this->authorize('update', $endpoint);

        $before = $endpoint->getAttributes();

        $validated = $request->validate([
            'url' => ['sometimes', 'url:https', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:200'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => [Rule::in(WebhookEndpoint::AVAILABLE_EVENTS)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Re-enabling clears the failure count that disabled it, otherwise the
        // next single failure would switch it straight back off.
        if (($validated['is_active'] ?? false) === true) {
            $validated['consecutive_failures'] = 0;
        }

        $endpoint->forceFill($validated)->save();

        $this->audit->log('webhook.updated', $endpoint, $before, $endpoint->getAttributes());

        return new WebhookEndpointResource($endpoint);
    }

    public function destroy(WebhookEndpoint $endpoint): JsonResponse
    {
        $this->authorize('delete', $endpoint);

        $before = $endpoint->getAttributes();
        $endpoint->delete();

        $this->audit->log('webhook.deleted', $endpoint, $before);

        return response()->json(['ok' => true]);
    }

    /** GET /webhook-events — what a tenant may subscribe to. */
    public function availableEvents(): JsonResponse
    {
        return response()->json(['data' => WebhookEndpoint::AVAILABLE_EVENTS]);
    }
}
