<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Menu;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Menu atau Paket sebagai satu bentuk katalog untuk kasir. */
class CatalogItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isMenu = $this->resource instanceof Menu;

        return [
            'type' => $isMenu ? 'menu' : 'package',
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'category' => $isMenu ? $this->category?->name : 'Paket',
            'price' => (string) $this->selling_price,
            'is_active' => (bool) $this->is_active,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
