<?php

namespace App\Modules\Employees\Support;

use App\Modules\Employees\Enums\Cycle;
use App\Modules\Employees\Models\FieldDefinition;
use Illuminate\Support\Collection;

/** The field definitions, read once per request (D-032). */
final class FieldDefinitions
{
    /** @var Collection<string, FieldDefinition>|null */
    private static ?Collection $cache = null;

    /** @return Collection<string, FieldDefinition> by key, in the guide's order */
    public static function all(): Collection
    {
        return self::$cache ??= FieldDefinition::query()->orderBy('sort_order')->get()->keyBy('key');
    }

    public static function forget(): void
    {
        self::$cache = null;
    }

    /** @return list<string> */
    public static function ofCycle(Cycle $cycle): array
    {
        return self::all()->filter(fn (FieldDefinition $d) => $d->cycle === $cycle)->keys()->values()->all();
    }

    /** @return Collection<string, FieldDefinition> the file's own fields, without the documents (D-049) */
    public static function fields(): Collection
    {
        return self::all()->reject(fn (FieldDefinition $d) => $d->type === self::DOCUMENT);
    }

    /** The type of the 13 attachments' definitions. */
    public const DOCUMENT = 'document';

    /** @return list<string> the fields that count for completeness */
    public static function counted(): array
    {
        return self::all()->filter(fn (FieldDefinition $d) => $d->counts_for_completeness)->keys()->values()->all();
    }
}
