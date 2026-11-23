<?php

namespace App\Modules\Settings\Support;

use App\Modules\Settings\Models\ListItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** One of the managed lists: its key, table and labels (from ListCatalogue), and a way to its items. */
final class ManagedList
{
    /** @var Collection<int, ListItem>|null set by withItems(), for the Resource */
    public ?Collection $items = null;

    private function __construct(
        public readonly string $key,
        public readonly string $table,
        public readonly string $label,
        public readonly string $usedBy,
    ) {}

    public static function find(string $key): ?self
    {
        if (! ListCatalogue::has($key)) {
            return null;
        }

        [$table, $label, $usedBy] = ListCatalogue::LISTS[$key];

        return new self($key, $table, $label, $usedBy);
    }

    public static function findOrFail(string $key): self
    {
        return self::find($key) ?? throw new NotFoundHttpException;
    }

    /** @return list<self> in the catalogue's order */
    public static function all(): array
    {
        return array_map(fn (string $key) => self::find($key), ListCatalogue::keys());
    }

    /** @return Builder<ListItem> */
    public function query(): Builder
    {
        return ListItem::queryFor($this->key);
    }

    public function withItems(bool $includeArchived = false): self
    {
        $this->items = $this->query()
            ->when(! $includeArchived, fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this;
    }
}
