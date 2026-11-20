@php
    $plan = $this->record;
    $result = $this->requirements();
    $statusValue = $plan->status->value;

    // Status colors aligned with the theme
    $statusColor = [
        'draft'     => '#64707d',
        'confirmed' => '#78907e',
        'done'      => '#1a365d',
    ][$statusValue] ?? '#64707d';

    $fmt = fn ($v, $d = 2) => rtrim(rtrim(number_format((float) $v, $d, ',', '.'), '0'), ',') ?: '0';
    $rp  = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
@endphp

<x-filament-panels::page>
    {{-- Header info --}}
    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:8px">
        <span style="font-weight:600;color:#0f2341">{{ $plan->plan_date->translatedFormat('d M Y') }}</span>
        <span style="color:#64707d">·</span>
        <span style="color:#232d37">{{ $plan->branch?->name }}</span>
        <span style="background:{{ $statusColor }};color:#fff;border-radius:999px;padding:2px 12px;font-size:12px;font-weight:600">
            {{ ucfirst($statusValue) }}
        </span>
        @if ($plan->notes)
            <span style="color:#64707d">· {{ $plan->notes }}</span>
        @endif
    </div>

    {{-- Item yang akan diproduksi --}}
    <x-filament::section heading="Item yang akan diproduksi">
        <table style="width:100%;border-collapse:collapse;font-size:14px">
            <thead>
                <tr style="background:#0f2341;color:#fff;text-align:left">
                    <th style="padding:8px 12px">Jenis</th>
                    <th style="padding:8px 12px">Nama</th>
                    <th style="padding:8px 12px;text-align:right">Jumlah</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->itemRows() as $i => $row)
                    <tr style="background:{{ $i % 2 ? '#f3eee6' : 'transparent' }}">
                        <td style="padding:8px 12px">
                            <span style="background:{{ $row['type'] === 'Paket' ? '#c26944' : '#1a365d' }};color:#fff;border-radius:6px;padding:2px 8px;font-size:12px">
                                {{ $row['type'] }}
                            </span>
                        </td>
                        <td style="padding:8px 12px;color:#232d37">{{ $row['name'] }}</td>
                        <td style="padding:8px 12px;text-align:right;color:#232d37">
                            {{ $row['qty'] }} {{ $row['type'] === 'Paket' ? 'paket' : 'porsi' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>

    {{-- Kebutuhan Bahan Baku --}}
    <x-filament::section
        heading="Kebutuhan Bahan Baku"
        description="Hasil BOM Explosion (Paket → Menu → Resep → Bahan) dibandingkan dengan stok cabang saat ini."
    >
        @if ($result['error'])
            <div style="color:#b94a48">{{ $result['error'] }}</div>
        @elseif (empty($result['rows']))
            <div style="color:#64707d">Belum ada kebutuhan bahan.</div>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:14px">
                    <thead>
                        <tr style="background:#0f2341;color:#fff;text-align:left">
                            <th style="padding:8px 12px">Bahan</th>
                            <th style="padding:8px 12px;text-align:right">Butuh</th>
                            <th style="padding:8px 12px;text-align:right">Stok tersedia</th>
                            <th style="padding:8px 12px;text-align:right">Kurang</th>
                            <th style="padding:8px 12px;text-align:right">Est. belanja</th>
                            <th style="padding:8px 12px">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($result['rows'] as $i => $row)
                            <tr style="background:{{ $i % 2 ? '#f3eee6' : 'transparent' }}">
                                <td style="padding:8px 12px;color:#232d37">{{ $row['ingredient']->name }}</td>
                                <td style="padding:8px 12px;text-align:right;color:#232d37">
                                    {{ $fmt($row['required']) }} {{ $row['unit'] }}
                                </td>
                                <td style="padding:8px 12px;text-align:right;color:#232d37">
                                    {{ $fmt($row['available']) }} {{ $row['unit'] }}
                                </td>
                                <td style="padding:8px 12px;text-align:right;{{ $row['is_short'] ? 'color:#b94a48;font-weight:600' : 'color:#232d37' }}">
                                    {{ $row['is_short'] ? $fmt($row['shortage']) . ' ' . $row['unit'] : '-' }}
                                </td>
                                <td style="padding:8px 12px;text-align:right;color:#232d37">
                                    {{ $row['is_short'] ? $rp($row['est_cost']) : '-' }}
                                </td>
                                <td style="padding:8px 12px">
                                    <span style="background:{{ $row['is_short'] ? '#b94a48' : '#78907e' }};color:#fff;border-radius:6px;padding:2px 10px;font-size:12px;font-weight:600">
                                        {{ $row['is_short'] ? 'Kurang' : 'Cukup' }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p style="margin-top:12px;color:#64707d;font-size:13px">
                @if ($result['shortage_count'] > 0)
                    {{ $result['shortage_count'] }} bahan kurang · Total estimasi belanja {{ $rp($result['shortage_cost']) }}
                @else
                    Semua bahan cukup.
                @endif
            </p>
        @endif
    </x-filament::section>
</x-filament-panels::page>