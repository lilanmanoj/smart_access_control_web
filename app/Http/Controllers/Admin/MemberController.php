<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\MemberStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use App\Services\AuditLogger;
use App\Services\TemplateSyncTrigger;
use App\Support\PhoneNumber;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class MemberController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TemplateSyncTrigger $sync,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Member::class);

        $members = Member::query()
            ->withCount('enrollments')
            ->when($request->filled('search'), function ($q) use ($request): void {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('full_name', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->boolean('admins_only'), fn ($q) => $q->where('is_admin', true))
            // Meaningful only while the tenant requires approval; otherwise
            // nobody is waiting, whatever their row says.
            ->when($request->boolean('awaiting_approval'), fn ($q) => app(TenantContext::class)->tenant()?->requiresMemberApproval()
                ? $q->whereNull('approved_at')
                : $q->whereRaw('1 = 0'))
            ->orderBy('full_name')
            ->paginate($request->integer('per_page', 25));

        return MemberResource::collection($members);
    }

    public function show(Member $member): MemberResource
    {
        $this->authorize('view', $member);

        return new MemberResource($member->load([
            'enrollments.device',
            'schedules.windows',
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Member::class);

        $validated = $this->validated($request, creating: true);

        // Added by an operator, so approved by that operator: approval exists
        // for people who enrolled themselves at a panel.
        $member = Member::create([
            ...$validated,
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ]);

        $this->audit->log('member.created', $member, after: $member->getAttributes());

        // Re-read so database defaults (status) are present: the resource
        // reads them, and a create that omits them would otherwise 500.
        return (new MemberResource($member->refresh()))->response()->setStatusCode(201);
    }

    public function update(Request $request, Member $member): MemberResource
    {
        $this->authorize('update', $member);

        $before = $member->getAttributes();
        $member->update($this->validated($request, creating: false, member: $member));

        $this->audit->log('member.updated', $member, $before, $member->getAttributes());

        // Suspending or reactivating changes which fingers every panel in the
        // tenant should hold; have them sync now rather than in hours.
        if ($member->wasChanged('status')) {
            $this->sync->tenantChanged((int) $member->tenant_id, by: $request->user());
        }

        return new MemberResource($member);
    }

    /**
     * POST /members/{member}/approval — admit a member enrolled at a panel.
     *
     * Idempotent: approving an approved member changes nothing and is not
     * audited twice.
     */
    public function approve(Request $request, Member $member): MemberResource
    {
        $this->authorize('update', $member);

        if ($member->approved_at === null) {
            $member->forceFill([
                'approved_at' => now(),
                'approved_by' => $request->user()->id,
            ])->save();

            $this->audit->log('member.approved', $member, after: [
                'approved_at' => $member->approved_at?->toIso8601String(),
            ]);

            // An approved member's finger now goes to the tenant's other panels.
            $this->sync->tenantChanged((int) $member->tenant_id, by: $request->user());
        }

        return new MemberResource($member);
    }

    public function destroy(Member $member): JsonResponse
    {
        $this->authorize('delete', $member);

        $before = $member->getAttributes();

        // Soft-deleted. Their access events must keep naming them, and
        // "deleted user" in an audit trail is a gap, not a record.
        $member->delete();

        $this->audit->log('member.deleted', $member, $before);

        $this->sync->tenantChanged((int) $member->tenant_id, by: request()->user());

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating, ?Member $member = null): array
    {
        $rules = [
            'full_name' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'phone' => [
                'sometimes', 'nullable', 'string', 'max:20',
                Rule::unique('members', 'phone')
                    ->where('tenant_id', $member?->tenant_id ?? app(TenantContext::class)->id())
                    ->ignore($member?->id),
            ],
            'email' => ['sometimes', 'nullable', 'email', 'max:180'],
            'status' => ['sometimes', Rule::in(MemberStatus::values())],
            // Grants the panel's Admin Settings flow, so it is a real
            // privilege change and audited as one.
            'is_admin' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];

        $validated = $request->validate($rules);

        if (isset($validated['phone']) && $validated['phone'] !== null) {
            try {
                // Normalised here so the OTP lookup, which matches on exactly
                // this column, can never miss because of formatting.
                $validated['phone'] = (string) PhoneNumber::parse($validated['phone']);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['phone' => [$exception->getMessage()]]);
            }
        }

        return $validated;
    }
}
