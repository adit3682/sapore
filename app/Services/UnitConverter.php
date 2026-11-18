<?php

namespace App\Services;

use App\Exceptions\ConversionException;
use App\Models\Ingredient;
use App\Models\Unit;
use App\Models\UnitConversion;

/**
 * Konversi satuan berbasis graf. Memakai bcmath (presisi tetap, bukan float).
 *
 * - Konversi umum (ingredient_id NULL) berlaku untuk semua bahan, mis. kg <-> g.
 * - Konversi khusus bahan (ingredient_id terisi) menimpa konversi umum untuk pasangan yang sama,
 *   mis. 1 butir telur = 0.06 kg.
 * - Arah balik dihitung otomatis (1/multiplier), dan rantai konversi ditelusuri (BFS).
 */
class UnitConverter
{
    public const SCALE = 6;
    private const INTERNAL_SCALE = 12;

    /** @var array<int, array<int, array<int, string>>> */
    private array $graphs = [];

    public function convert(string|int|float $qty, int $fromUnitId, int $toUnitId, ?int $ingredientId = null): string
    {
        $qty = self::normalize($qty);

        if ($fromUnitId === $toUnitId) {
            return bcadd($qty, '0', self::SCALE);
        }

        return bcmul($qty, $this->factor($fromUnitId, $toUnitId, $ingredientId), self::SCALE);
    }

    /** Konversi ke satuan dasar sebuah bahan. */
    public function toBase(string|int|float $qty, int $unitId, Ingredient $ingredient): string
    {
        return $this->convert($qty, $unitId, $ingredient->base_unit_id, $ingredient->id);
    }

    /** Pengali from -> to (string bcmath). */
    public function factor(int $fromUnitId, int $toUnitId, ?int $ingredientId = null): string
    {
        if ($fromUnitId === $toUnitId) {
            return '1';
        }

        $graph = $this->graph($ingredientId);

        $queue = [[$fromUnitId, '1']];
        $visited = [$fromUnitId => true];

        while ($queue) {
            [$current, $acc] = array_shift($queue);

            foreach ($graph[$current] ?? [] as $next => $mult) {
                if (isset($visited[$next])) {
                    continue;
                }
                $value = bcmul($acc, $mult, self::INTERNAL_SCALE);
                if ($next === $toUnitId) {
                    return $value;
                }
                $visited[$next] = true;
                $queue[] = [$next, $value];
            }
        }

        $symbols = Unit::whereIn('id', [$fromUnitId, $toUnitId])->pluck('symbol', 'id');
        $scope = $ingredientId ? ' untuk bahan ini' : '';
        throw new ConversionException(sprintf(
            'Belum ada konversi satuan dari %s ke %s%s. Tambahkan di menu Konversi Satuan.',
            $symbols[$fromUnitId] ?? $fromUnitId,
            $symbols[$toUnitId] ?? $toUnitId,
            $scope
        ));
    }

    /** @return array<int, array<int, string>> adjacency: from => [to => multiplier] */
    private function graph(?int $ingredientId): array
    {
        $key = $ingredientId ?? 0;
        if (isset($this->graphs[$key])) {
            return $this->graphs[$key];
        }

        $rows = UnitConversion::query()
            ->where(function ($q) use ($ingredientId) {
                $q->whereNull('ingredient_id');
                if ($ingredientId) {
                    $q->orWhere('ingredient_id', $ingredientId);
                }
            })
            ->get();

        $graph = [];
        // umum dulu, lalu khusus bahan menimpa
        foreach ($rows->sortBy(fn ($r) => $r->ingredient_id === null ? 0 : 1) as $row) {
            $mult = self::normalize($row->multiplier);
            if (bccomp($mult, '0', self::INTERNAL_SCALE) <= 0) {
                continue;
            }
            $graph[$row->from_unit_id][$row->to_unit_id] = $mult;
            $graph[$row->to_unit_id][$row->from_unit_id] = bcdiv('1', $mult, self::INTERNAL_SCALE);
        }

        return $this->graphs[$key] = $graph;
    }

    public static function normalize(string|int|float $value): string
    {
        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') ?: '0';
        }
        $value = trim((string) $value);

        return is_numeric($value) ? $value : '0';
    }
}
