<?php

namespace App\Filament\Widgets;

use App\Enums\PlanStatus;
use App\Filament\Resources\ProductionPlanResource;
use App\Models\ProductionPlan;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class TodayPlansWidget extends TableWidget
{
    protected static ?string $heading = 'Rencana Produksi Hari Ini';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ProductionPlan::query()
                ->whereDate('plan_date', today())
                ->when(auth()->user()?->branch_id, fn ($query, $b) => $query->where('branch_id', $b))
                ->with('branch')->withCount('items')->latest('id')->limit(8))
            ->paginated(false)
            ->emptyStateHeading('Belum ada rencana produksi hari ini')
            ->columns([
                Tables\Columns\TextColumn::make('branch.name')->label('Cabang'),
                Tables\Columns\TextColumn::make('items_count')->label('Item')->suffix(' item'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => ucfirst($state instanceof PlanStatus ? $state->value : (string) $state))
                    ->color(fn ($state) => match ($state instanceof PlanStatus ? $state->value : $state) {
                        'confirmed' => 'success', 'done' => 'gray', default => 'warning',
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('open')->label('Buka')
                    ->url(fn (ProductionPlan $r) => ProductionPlanResource::getUrl('view', ['record' => $r])),
            ]);
    }
}
