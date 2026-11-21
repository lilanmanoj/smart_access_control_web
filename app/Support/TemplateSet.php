<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\TemplateSlotService;

/**
 * The complete set of templates one panel's sensor should hold, as computed
 * by {@see TemplateSlotService::desiredSet()}.
 *
 * The revision is a pure function of the entries, the capacity and the
 * format: the same set always hashes the same, across requests and restarts,
 * and any change to a slot, a member or a template's hash changes it. That is
 * what lets a panel paging through the set detect that it moved underneath it.
 */
final readonly class TemplateSet
{
    /** Bumped only if the canonical form itself ever changes. */
    private const CANONICAL_VERSION = 'tpl1';

    public string $revision;

    /**
     * @param  list<TemplateSetEntry>  $entries  Ascending by slot, all `< capacity`.
     * @param  bool  $truncated  More members qualified than the sensor holds.
     * @param  int  $deferred  Members who fit but wait for a released slot to be confirmed empty.
     */
    public function __construct(
        public string $format,
        public int $capacity,
        public array $entries,
        public bool $truncated,
        public int $deferred,
    ) {
        $this->revision = substr(hash('sha256', implode('|', [
            self::CANONICAL_VERSION,
            $format,
            (string) $capacity,
            implode(',', array_map(fn (TemplateSetEntry $entry): string => $entry->canonical(), $entries)),
        ])), 0, 20);
    }

    public function total(): int
    {
        return count($this->entries);
    }

    /** @return list<TemplateSetEntry> */
    public function page(int $offset, int $limit): array
    {
        return array_slice($this->entries, $offset, $limit);
    }

    public function withTemplates(): int
    {
        return count(array_filter($this->entries, fn (TemplateSetEntry $entry): bool => $entry->templateId !== null));
    }
}
