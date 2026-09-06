<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Adds a public UUID alongside the auto-incrementing primary key.
 *
 * Rows are joined and indexed on the integer id — which matters on
 * access_events, where locality is the difference between a fast index and a
 * fragmented one — while everything exposed in a URL or an API payload uses
 * the UUID, so ids are neither guessable nor countable from outside.
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function ($model): void {
            if (empty($model->getAttribute('uuid'))) {
                $model->setAttribute('uuid', (string) Str::uuid7());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
