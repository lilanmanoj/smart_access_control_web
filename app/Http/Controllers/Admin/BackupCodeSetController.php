<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BackupCodeSetResource;
use App\Models\BackupCodeSet;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Backup code set history. Generation lives on the device controller, next to
 * the door it affects.
 */
class BackupCodeSetController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', BackupCodeSet::class);

        $query = BackupCodeSet::query()->with(['device', 'codes', 'issuer']);

        if ($request->filled('device_id')) {
            $query->where('device_id', Device::query()->where('uuid', $request->string('device_id'))->value('id') ?? 0);
        }

        return BackupCodeSetResource::collection(
            $query->latest('issued_at')->paginate($request->integer('per_page', 25))
        );
    }
}
