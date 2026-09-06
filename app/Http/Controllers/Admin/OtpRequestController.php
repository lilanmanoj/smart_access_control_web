<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OtpRequestResource;
use App\Models\AccessEvent;
use App\Models\Device;
use App\Models\OtpRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * OTP history, for answering "did the code actually go out" when someone says
 * they never got one. The codes themselves are not readable from anywhere.
 */
class OtpRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AccessEvent::class);

        $query = OtpRequest::query()->with(['device', 'member']);

        if ($request->filled('device_id')) {
            $query->where('device_id', Device::query()->where('uuid', $request->string('device_id'))->value('id') ?? 0);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return OtpRequestResource::collection(
            $query->latest('id')->paginate($request->integer('per_page', 25))
        );
    }
}
