<?php

namespace App\Filament\Support;

use App\Support\Access;
use App\Enums\Role;
use App\Models\Branch;
use App\Services\ReportExporter;
use App\Services\ReportService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Dasar semua halaman laporan: filter (periode, cabang), tabel, ringkasan, dan Export Excel.
 * Subclass cukup menentukan label navigasi, peran yang boleh melihat, dan memanggil method ReportService.
 * Letaknya di luar app/Filament/Pages agar tidak ikut didaftarkan sebagai halaman.
 */
abstract class ReportPage extends Page
{
    protected static ?string $navigationGroup = 'Laporan';

    protected static string $view = 'filament.pages.report';

    /** @var array<int, Role> */
    protected static array $roles = [Role::Owner, Role::Manager];

    public ?array $data = [];

    /** @param array{from: Carbon, to: Carbon, branch_id: ?int, lookback: int, horizon: int} $filters */
    abstract protected function build(ReportService $reports, array $filters): array;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        $role = $user->role instanceof Role ? $user->role : Role::tryFrom((string) $user->role);

        return in_array($role, static::$roles, true);
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => today()->toDateString(),
            'branch_id' => Access::branchScope(auth()->user()),
            'lookback' => 14,
            'horizon' => 7,
        ]);
    }

    /** Field filter. Default: periode + cabang. Subclass boleh menimpa. */
    protected function filterFields(): array
    {
        return [
            Forms\Components\DatePicker::make('from')->label('Dari')->required()->live(),
            Forms\Components\DatePicker::make('to')->label('Sampai')->required()->live(),
            $this->branchField(),
        ];
    }

    protected function branchField(): Forms\Components\Select
    {
        return Forms\Components\Select::make('branch_id')->label('Cabang')
            ->options(fn () => Access::branchOptions(auth()->user()))
            ->placeholder('Semua cabang')->live()
            ->disabled(fn () => Access::branchScope(auth()->user()) !== null)->dehydrated();
    }

    public function form(Form $form): Form
    {
        $fields = $this->filterFields();

        return $form->schema($fields)->statePath('data')->columns(max(count($fields), 1));
    }

    public function hasFilters(): bool
    {
        return count($this->filterFields()) > 0;
    }

    /** Hasil laporan terbaru sesuai filter; dipanggil dari view. */
    public function result(): array
    {
        try {
            $filters = $this->filters();
            if ($filters['from']->gt($filters['to'])) {
                throw new \InvalidArgumentException('Tanggal "Dari" tidak boleh setelah tanggal "Sampai".');
            }

            return $this->build(app(ReportService::class), $filters) + ['error' => null];
        } catch (\Throwable $e) {
            return ['title' => static::getNavigationLabel(), 'meta' => [], 'columns' => [], 'rows' => [], 'summary' => [], 'notes' => [], 'error' => $e->getMessage()];
        }
    }

    /** @return array{from: Carbon, to: Carbon, branch_id: ?int, lookback: int, horizon: int} */
    protected function filters(): array
    {
        $d = $this->data ?? [];

        return [
            'from' => Carbon::parse($d['from'] ?? now()->startOfMonth())->startOfDay(),
            'to' => Carbon::parse($d['to'] ?? today())->endOfDay(),
            // akun cabang selalu terkunci ke cabangnya, apa pun isi form (state Livewire bisa diubah klien)
            'branch_id' => Access::branchScope(auth()->user()) ?? (filled($d['branch_id'] ?? null) ? (int) $d['branch_id'] : null),
            'lookback' => (int) ($d['lookback'] ?? 14),
            'horizon' => (int) ($d['horizon'] ?? 7),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label('Export Excel')->icon('heroicon-o-arrow-down-tray')->color('primary')
                ->action(fn () => $this->export()),
        ];
    }

    public function export()
    {
        $report = $this->result();
        if ($report['error']) {
            return null;
        }

        $file = app(ReportExporter::class)->toFile($report);
        $name = Str::slug($report['title']).'-'.now()->format('Ymd-His').'.xlsx';

        return response()->streamDownload(function () use ($file) {
            readfile($file);
            @unlink($file);
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
