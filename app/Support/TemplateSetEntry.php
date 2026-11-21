<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One slot of a panel's template set: who owns it and which stored template,
 * if any, it must hold. Carries the template's id and hash, never its bytes.
 */
final readonly class TemplateSetEntry
{
    public function __construct(
        public int $slot,
        public string $memberUuid,
        public ?int $templateId,
        public ?string $sha256,
    ) {}

    /** The entry's contribution to the set's revision. */
    public function canonical(): string
    {
        return $this->slot.':'.$this->memberUuid.':'.($this->sha256 ?? 'none');
    }
}
