<?php

namespace App\Filament\Resources\StockResource\Pages;

use App\Support\Access;
use App\Filament\Resources\GoodsReceiptResource;
use App\Filament\Resources\StockResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStocks extends ListRecords
{
    protected static string $resource = StockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('receive')->label('+ Penerimaan')
                ->visible(fn () => Access::can(auth()->user(), 'goods_receipts', 'create'))
                ->url(GoodsReceiptResource::getUrl('create')),
        ];
    }
}
