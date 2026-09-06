<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One six-digit code. Stored hashed; `last4` exists so an operator can tell
 * codes apart in the dashboard without the plaintext being recoverable.
 *
 * Not tenant-scoped directly — it reaches its tenant through its set, and
 * every query path goes through {@see BackupCodeSet}, which is scoped.
 */
#[Fillable(['set_id', 'code_hash', 'last4'])]
#[Hidden(['code_hash'])]
class BackupCode extends Model
{
    use HasFactory;
    use HasUuid;

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BackupCodeSet, $this> */
    public function set(): BelongsTo
    {
        return $this->belongsTo(BackupCodeSet::class, 'set_id');
    }

    /** @return BelongsTo<AccessEvent, $this> */
    public function usedEvent(): BelongsTo
    {
        return $this->belongsTo(AccessEvent::class, 'used_event_id');
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
