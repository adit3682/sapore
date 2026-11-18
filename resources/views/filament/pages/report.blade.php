@php
    $report = $this->result();

    $text = function (string $type, $v) {
        if ($v === null || $v === '') {
            return '-';
        }
        return match ($type) {
            'money' => 'Rp ' . number_format((float) $v, 0, ',', '.'),
            'int' => number_format((float) $v, 0, ',', '.'),
            'qty' => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',') ?: '0',
            'pct' => number_format((float) $v, 1, ',', '.') . '%',
            default => (string) $v,
        };
    };

    $statusColor = function ($v) {
        $v = (string) $v;
        if (str_contains($v, 'Kritis') || str_contains($v, 'Di atas') || str_contains($v, 'Perlu')) {
            return '#b94a48';
        }
        if (str_contains($v, 'Rendah') || str_contains($v, 'Belum')) {
            return '#c98a3c';
        }
        return '#78907e';
    };
@endphp

<x-filament-panels::page>
    <style>
        .sp-rep-card { flex: 1; min-width: 190px; padding: 12px 16px; background: #efeae3; border-left: 4px solid #c26944; border-radius: 8px; color: #232d37; }
        .sp-rep-card small { display: block; font-size: 12px; color: #64707d; }
        .sp-rep-card strong { font-size: 20px; }
        .sp-rep-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .sp-rep-table th { background: #0f2341; color: #fff; text-align: left; padding: 8px 12px; white-space: nowrap; }
        .sp-rep-table td { padding: 8px 12px; }
        .sp-rep-table tr:nth-child(even) td { background: rgba(239, 234, 227, .55); }
        .sp-num { text-align: right; white-space: nowrap; }
        .sp-badge { color: #fff; border-radius: 6px; padding: 2px 10px; font-size: 12px; font-weight: 600; white-space: nowrap; }
    </style>

    @if ($this->hasFilters())
        <x-filament::section>
            {{ $this->form }}
        </x-filament::section>
    @endif

    @if ($report['error'])
        <x-filament::section>
            <div style="color:#b94a48">{{ $report['error'] }}</div>
        </x-filament::section>
    @else
        <div>
            @foreach ($report['meta'] as $line)
                <div style="font-size:13px;color:#64707d">{{ $line }}</div>
            @endforeach
        </div>

        @if (! empty($report['summary']))
            <div style="display:flex;flex-wrap:wrap;gap:12px">
                @foreach ($report['summary'] as $s)
                    <div class="sp-rep-card">
                        <small>{{ $s['label'] }}</small>
                        @if ($s['type'] === 'status')
                            <span class="sp-badge" style="background:{{ $statusColor($s['value']) }}">{{ $s['value'] }}</span>
                        @else
                            <strong>{{ $text($s['type'], $s['value']) }}</strong>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        <x-filament::section>
            @if (empty($report['rows']))
                <p style="color:#64707d">Belum ada data untuk filter ini.</p>
            @else
                <div style="overflow-x:auto">
                    <table class="sp-rep-table">
                        <thead>
                            <tr>
                                @foreach ($report['columns'] as $c)
                                    <th class="{{ in_array($c['type'], ['money', 'qty', 'pct', 'int']) ? 'sp-num' : '' }}">{{ $c['label'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report['rows'] as $row)
                                <tr>
                                    @foreach ($report['columns'] as $c)
                                        @php $v = $row[$c['key']] ?? null; @endphp
                                        @if ($c['type'] === 'status')
                                            <td>
                                                @if ($v !== null && $v !== '-')
                                                    <span class="sp-badge" style="background:{{ $statusColor($v) }}">{{ $v }}</span>
                                                @else
                                                    -
                                                @endif
                                            </td>
                                        @else
                                            <td class="{{ in_array($c['type'], ['money', 'qty', 'pct', 'int']) ? 'sp-num' : '' }}">{{ $text($c['type'], $v) }}</td>
                                        @endif
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        @foreach ($report['notes'] as $note)
            <p style="font-size:12px;color:#64707d">{{ $note }}</p>
        @endforeach
    @endif
</x-filament-panels::page>
