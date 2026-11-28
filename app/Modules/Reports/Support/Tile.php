<?php

namespace App\Modules\Reports\Support;

/**
 * One figure of a dashboard (D-051): what it counts, its value and how it reads. A tile for a list item carries the
 * item (`{id, name_ar, archived}`) and takes its current name, so a renamed item keeps its tile.
 */
final class Tile
{
    /** A tile's shape, for the API document. */
    public const TYPE = 'array{key: string, label: string, value: int|float|string|null, format: string, item: array{id: int, name_ar: string, archived: bool}|null}';

    /** A group of tiles' shape, for the API document. */
    public const GROUP_TYPE = 'array{key: string, title: string, tiles: list<'.self::TYPE.'>}';

    /** A number of files or records. */
    public const COUNT = 'count';

    /** 0 to 100, two decimals. */
    public const PERCENT = 'percent';

    /** An amount, a string with two decimals. */
    public const MONEY = 'money';

    /** Years, one decimal. */
    public const YEARS = 'years';

    /** Days, one decimal at most. */
    public const DAYS = 'days';

    /**
     * @param  array{id: int, name_ar: string, archived: bool}|null  $item
     * @return array{key: string, label: string, value: int|float|string|null, format: string, item: array{id: int, name_ar: string, archived: bool}|null}
     */
    public static function make(string $key, string $label, int|float|string|null $value, string $format = self::COUNT, ?array $item = null): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'format' => $format, 'item' => $item];
    }

    /**
     * Tiles for every item of a list, keyed `{prefix}.{id}`.
     *
     * @param  array<int, int>  $counts
     * @return list<array{key: string, label: string, value: int|float|string|null, format: string, item: array{id: int, name_ar: string, archived: bool}|null}>
     */
    public static function items(string $prefix, string $list, array $counts): array
    {
        return array_map(fn (array $b) => self::make($prefix.'.'.$b['item']['id'], $b['item']['name_ar'], $b['count'], self::COUNT, $b['item']),
            ListItems::breakdown($list, $counts));
    }

    /**
     * @param  list<array<string, mixed>>  $tiles
     * @return array{key: string, title: string, tiles: list<array<string, mixed>>}
     */
    public static function group(string $key, string $title, array $tiles): array
    {
        return ['key' => $key, 'title' => $title, 'tiles' => $tiles];
    }

    public static function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /** a ÷ b as a percent with two decimals; 0 when b is 0. */
    public static function percent(float $part, float $whole): float
    {
        return $whole <= 0 ? 0.0 : round($part * 100 / $whole, 2);
    }
}
