<?php

namespace App\Filament\Resources;

use App\Enums\RecipeStatus;
use App\Filament\Resources\RecipeResource\Pages;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\Unit;
use App\Services\RecipeCostService;
use Filament\Forms;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class RecipeResource extends Resource
{
    protected static ?string $model = Recipe::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Resep & Menu';

    protected static ?string $modelLabel = 'Resep';

    protected static ?string $pluralModelLabel = 'Resep';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Informasi Resep')->schema([
                Forms\Components\TextInput::make('code')->label('Kode')->required()->maxLength(30)
                    ->unique(ignoreRecord: true)->placeholder('RSP-018'),
                Forms\Components\TextInput::make('name')->label('Nama resep')->required()->maxLength(255),
                Forms\Components\Select::make('status')->options(self::statusOptions())
                    ->default(RecipeStatus::Draft->value)->required()
                    ->helperText('Hanya resep Aktif yang bisa dipakai sebagai sub-resep dan menu.'),
                Forms\Components\TextInput::make('yield_qty')->label('Yield (hasil jadi)')->numeric()
                    ->required()->minValue(0.0001)->default(1)->live(onBlur: true),
                Forms\Components\Select::make('yield_unit_id')->label('Satuan yield')
                    ->relationship('yieldUnit', 'symbol')->required()->searchable()->preload()
                    ->helperText('Contoh: 10 porsi, atau 2 kg untuk sub-resep seperti bumbu dasar.'),
                Forms\Components\Placeholder::make('version_info')->label('Versi')
                    ->content(fn (?Recipe $record) => $record ? 'v'.$record->version : 'v1 (baru)'),
            ])->columns(3),

            Forms\Components\Section::make('Daftar Bahan Baku')->schema([
                Repeater::make('items')
                    ->relationship()
                    ->label('')
                    ->schema([
                        Forms\Components\Select::make('source')->label('Jenis')
                            ->options(['ingredient' => 'Bahan baku', 'recipe' => 'Sub-resep'])
                            ->default('ingredient')->native(false)->selectablePlaceholder(false)
                            ->live()->dehydrated(false)
                            ->afterStateHydrated(function (Forms\Components\Select $component, Get $get) {
                                $component->state($get('sub_recipe_id') ? 'recipe' : 'ingredient');
                            })
                            ->afterStateUpdated(function (?string $state, Set $set) {
                                $set($state === 'recipe' ? 'ingredient_id' : 'sub_recipe_id', null);
                            })
                            ->columnSpan(2),
                        Forms\Components\Select::make('ingredient_id')->label('Bahan')
                            ->options(fn () => Ingredient::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()->live()
                            ->visible(fn (Get $get) => $get('source') !== 'recipe')
                            ->afterStateUpdated(function ($state, Set $set) {
                                if ($state && ($ing = Ingredient::find($state))) {
                                    $set('unit_id', $ing->base_unit_id);
                                }
                            })
                            ->columnSpan(3),
                        Forms\Components\Select::make('sub_recipe_id')->label('Sub-resep')
                            ->options(function ($livewire) {
                                $selfId = $livewire instanceof EditRecord ? $livewire->getRecord()?->id : null;

                                return Recipe::where('status', RecipeStatus::Active->value)
                                    ->when($selfId, fn ($q) => $q->where('id', '!=', $selfId))
                                    ->orderBy('name')->pluck('name', 'id');
                            })
                            ->searchable()->live()
                            ->visible(fn (Get $get) => $get('source') === 'recipe')
                            ->afterStateUpdated(function ($state, Set $set) {
                                if ($state && ($sub = Recipe::find($state))) {
                                    $set('unit_id', $sub->yield_unit_id);
                                }
                            })
                            ->columnSpan(3),
                        Forms\Components\TextInput::make('qty')->label('Jumlah')->numeric()->required()
                            ->minValue(0.0001)->live(onBlur: true)->columnSpan(2),
                        Forms\Components\Select::make('unit_id')->label('Satuan')
                            ->options(fn () => Unit::orderBy('symbol')->pluck('symbol', 'id'))
                            ->required()->live()->columnSpan(2),
                        Forms\Components\TextInput::make('notes')->label('Catatan')->maxLength(255)->columnSpan(12),
                    ])
                    ->columns(12)
                    ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => self::normalizeItem($data))
                    ->mutateRelationshipDataBeforeSaveUsing(fn (array $data) => self::normalizeItem($data))
                    ->defaultItems(1)->minItems(1)->live()
                    ->addActionLabel('+ Tambah Bahan')
                    ->reorderable(false),
            ]),

            Forms\Components\Section::make('Ringkasan HPP')->schema([
                Forms\Components\Placeholder::make('summary')->label('')
                    ->content(fn (Get $get, ?Recipe $record) => self::summaryHtml($get, $record)),
            ]),

            Forms\Components\Section::make('Instruksi (opsional)')->schema([
                Forms\Components\Textarea::make('instructions')->label('Instruksi memasak')->rows(4),
                Forms\Components\FileUpload::make('photo_path')->label('Foto')->image()
                    ->directory('recipes')->imageEditor(),
            ])->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Kode')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('yield_qty')->label('Yield')
                    ->formatStateUsing(fn ($state, Recipe $r) => rtrim(rtrim((string) $state, '0'), '.').' '.$r->yieldUnit?->symbol),
                Tables\Columns\TextColumn::make('total_cost')->label('Total HPP')
                    ->formatStateUsing(fn ($state) => RecipeCostService::rupiah($state)),
                Tables\Columns\TextColumn::make('cost_per_portion')->label('HPP / porsi')
                    ->formatStateUsing(fn ($state) => RecipeCostService::rupiah($state))->weight('bold'),
                Tables\Columns\TextColumn::make('version')->label('Versi')->formatStateUsing(fn ($state) => 'v'.$state),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => ucfirst($state instanceof RecipeStatus ? $state->value : (string) $state))
                    ->color(fn ($state) => match ($state instanceof RecipeStatus ? $state->value : $state) {
                        'active' => 'success',
                        'archived' => 'gray',
                        default => 'warning',
                    }),
            ])
            ->modifyQueryUsing(fn ($q) => $q->with('yieldUnit'))
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(self::statusOptions()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('recalculate')->label('Hitung ulang')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (Recipe $record) {
                        app(RecipeCostService::class)->recalculate($record);
                        Notification::make()->success()->title('HPP dihitung ulang')->send();
                    }),
            ])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRecipes::route('/'),
            'create' => Pages\CreateRecipe::route('/create'),
            'edit' => Pages\EditRecipe::route('/{record}/edit'),
        ];
    }

    // ------------------------------------------------------------------

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return collect(RecipeStatus::cases())
            ->mapWithKeys(fn (RecipeStatus $s) => [$s->value => ucfirst($s->value)])->all();
    }

    /** Pastikan kolom yang tidak dipakai bernilai NULL (saat user pindah jenis baris). */
    public static function normalizeItem(array $data): array
    {
        $data['ingredient_id'] = $data['ingredient_id'] ?? null;
        $data['sub_recipe_id'] = $data['sub_recipe_id'] ?? null;

        return $data;
    }

    /** @return array<int, array<string, mixed>> */
    public static function normalizeItems(array $items): array
    {
        return collect($items)->map(fn ($i) => [
            'ingredient_id' => $i['ingredient_id'] ?? null,
            'sub_recipe_id' => $i['sub_recipe_id'] ?? null,
            'qty' => $i['qty'] ?? 0,
            'unit_id' => $i['unit_id'] ?? null,
        ])->values()->all();
    }

    /** Validasi sebelum simpan. Mengembalikan pesan error, atau null bila aman. */
    public static function validateItems(array $items, string|int|float|null $yieldQty, ?int $recipeId): ?string
    {
        $items = self::normalizeItems($items);
        if (! $items) {
            return 'Resep harus punya minimal satu bahan.';
        }

        $service = app(RecipeCostService::class);

        foreach ($items as $n => $item) {
            $row = $n + 1;
            $hasIng = ! empty($item['ingredient_id']);
            $hasSub = ! empty($item['sub_recipe_id']);
            if ($hasIng === $hasSub) {
                return "Baris {$row}: pilih salah satu, bahan baku atau sub-resep.";
            }
            if ($hasSub && $recipeId && $service->wouldCreateCycle($recipeId, (int) $item['sub_recipe_id'])) {
                return "Baris {$row}: sub-resep ini akan membuat resep saling merujuk (siklus).";
            }
        }

        try {
            $service->estimate($items, $yieldQty ?: 1, $recipeId);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    public static function notifyError(string $message): void
    {
        Notification::make()->danger()->title('Resep belum bisa disimpan')->body($message)->persistent()->send();
    }

    private static function summaryHtml(Get $get, ?Recipe $record): HtmlString
    {
        $items = collect(self::normalizeItems($get('items') ?? []))
            ->filter(fn ($i) => (! empty($i['ingredient_id']) || ! empty($i['sub_recipe_id']))
                && ! empty($i['unit_id']) && is_numeric($i['qty']))
            ->values()->all();

        $yield = $get('yield_qty');
        if (! is_numeric($yield) || (float) $yield <= 0) {
            return new HtmlString('<span style="color:#64707d">Isi yield terlebih dahulu.</span>');
        }

        try {
            $r = app(RecipeCostService::class)->estimate($items, $yield, $record?->id);
        } catch (\Throwable $e) {
            return new HtmlString('<span style="color:#b94a48">'.e($e->getMessage()).'</span>');
        }

        $card = fn (string $label, string $value, string $color) => '<div style="flex:1;min-width:180px;padding:12px 16px;'
            ."background:#efeae3;border-left:4px solid {$color};border-radius:8px\">"
            .'<div style="font-size:12px;color:#64707d">'.$label.'</div>'
            .'<div style="font-size:22px;font-weight:700;color:#232d37">'.$value.'</div></div>';

        return new HtmlString('<div style="display:flex;gap:12px;flex-wrap:wrap">'
            .$card('Total HPP Resep', e(RecipeCostService::rupiah($r['total'])), '#1a365d')
            .$card('HPP per Porsi', e(RecipeCostService::rupiah($r['per_portion'])), '#78907e')
            .'</div>');
    }
}
