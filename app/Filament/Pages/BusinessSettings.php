<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Services\SettingsService;
use App\Support\Access;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/** Pengaturan Usaha (Owner): pajak, service charge, target food cost, dan tampilan struk. */
class BusinessSettings extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Pengaturan Usaha';

    protected static ?string $title = 'Pengaturan Usaha';

    protected static ?string $slug = 'pengaturan-usaha';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.business-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Access::can(auth()->user(), 'settings', 'view');
    }

    public function mount(): void
    {
        $s = app(SettingsService::class);
        $receipt = $s->receipt();

        $this->form->fill([
            'tax_rate' => $s->taxRate(),
            'service_rate' => $s->serviceRate(),
            'food_cost_target' => $s->foodCostTarget(),
            'receipt_name' => $receipt['name'],
            'receipt_footer' => $receipt['footer'],
            'receipt_paper' => $receipt['paper'],
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Pajak dan service charge')
                ->description('Persen dari subtotal, berlaku untuk order baru di semua cabang kecuali cabang yang punya tarif sendiri (Pengaturan > Cabang). Order yang sudah dibuat atau dibayar tidak berubah. Isi 0 bila tidak dipungut.')
                ->schema([
                    Forms\Components\TextInput::make('tax_rate')->label('Pajak (PB1/PPN)')
                        ->numeric()->required()->minValue(0)->maxValue(100)->step(0.01)->suffix('%'),
                    Forms\Components\TextInput::make('service_rate')->label('Service charge')
                        ->numeric()->required()->minValue(0)->maxValue(100)->step(0.01)->suffix('%'),
                ])->columns(2),

            Forms\Components\Section::make('Target food cost')
                ->description('Menu dan laporan di atas angka ini ditandai merah. Food cost = HPP dibagi penjualan neto.')
                ->schema([
                    Forms\Components\TextInput::make('food_cost_target')->label('Target food cost')
                        ->numeric()->required()->minValue(1)->maxValue(100)->step(0.1)->suffix('%'),
                ]),

            Forms\Components\Section::make('Struk')
                ->description('Nama, alamat, dan telepon di struk diambil dari data Cabang.')
                ->schema([
                    Forms\Components\TextInput::make('receipt_name')->label('Nama di struk')->required()->maxLength(60),
                    Forms\Components\Select::make('receipt_paper')->label('Lebar kertas')->required()->native(false)
                        ->options([80 => '80 mm', 58 => '58 mm']),
                    Forms\Components\Textarea::make('receipt_footer')->label('Teks penutup')->rows(2)->maxLength(160)->columnSpanFull(),
                ])->columns(2),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(Access::can(auth()->user(), 'settings', 'update'), 403);

        $d = $this->form->getState();

        app(SettingsService::class)->setMany([
            'tax_rate' => $d['tax_rate'],
            'service_rate' => $d['service_rate'],
            'food_cost_target' => $d['food_cost_target'],
            'receipt_name' => trim((string) $d['receipt_name']),
            'receipt_footer' => trim((string) ($d['receipt_footer'] ?? '')),
            'receipt_paper' => $d['receipt_paper'],
        ]);

        Notification::make()->success()->title('Pengaturan disimpan')
            ->body('Berlaku untuk order baru dan laporan berikutnya.')->send();
    }

    /** @return array<int, array{name: string, tax: string, service: string}> cabang yang punya tarif sendiri */
    public function branchOverrides(): array
    {
        $s = app(SettingsService::class);

        return Branch::where(fn ($q) => $q->whereNotNull('tax_rate')->orWhereNotNull('service_rate'))
            ->orderBy('name')->get()
            ->map(fn (Branch $b) => [
                'name' => $b->name,
                'tax' => $s->taxRate($b->id).'%',
                'service' => $s->serviceRate($b->id).'%',
            ])->all();
    }
}
