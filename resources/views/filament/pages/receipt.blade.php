@php
    $r = $this->receipt();
    $width = $r['paper'] === 58 ? '48mm' : '72mm';
    $rp = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $isReturn = $r['kind'] === 'return';
    $minus = $isReturn ? '-' : '';
@endphp

<x-filament-panels::page>
    <style>
        .sp-receipt { width: {{ $width }}; margin: 0 auto; background: #fff; color: #000; padding: 4mm 3mm; font-family: 'Courier New', Courier, monospace; font-size: 12px; line-height: 1.35; box-shadow: 0 1px 6px rgba(0,0,0,.18); }
        .sp-receipt .c { text-align: center; }
        .sp-receipt .b { font-weight: 700; }
        .sp-receipt .row { display: flex; justify-content: space-between; gap: 6px; }
        .sp-receipt .row span:last-child { text-align: right; white-space: nowrap; }
        .sp-receipt hr { border: 0; border-top: 1px dashed #000; margin: 5px 0; }
        .sp-receipt .big { font-size: 15px; letter-spacing: 1px; }
        .sp-receipt .small { font-size: 10.5px; }
        @media print {
            @page { size: {{ $width }} auto; margin: 2mm; }
            body * { visibility: hidden !important; }
            .sp-receipt, .sp-receipt * { visibility: visible !important; }
            .sp-receipt { position: absolute; left: 0; top: 0; width: {{ $width }}; box-shadow: none; padding: 0; margin: 0; }
        }
    </style>

    <div style="display:flex;gap:8px;justify-content:center;margin-bottom:12px">
        <x-filament::button color="primary" icon="heroicon-o-printer" x-on:click="window.print()">Cetak</x-filament::button>
        <x-filament::button color="gray" tag="a" href="javascript:history.back()">Kembali</x-filament::button>
    </div>

    <div class="sp-receipt">
        <div class="c">
            <div class="b big">{{ $r['business'] }}</div>
            @if ($r['branch']) <div>{{ $r['branch'] }}</div> @endif
            @if ($r['address']) <div class="small">{{ $r['address'] }}</div> @endif
            @if ($r['phone']) <div class="small">Telp {{ $r['phone'] }}</div> @endif
        </div>
        <hr>

        @if ($isReturn)
            <div class="c b">STRUK RETUR</div>
            <div class="row"><span>No. Retur</span><span>{{ $r['number'] }}</span></div>
            <div class="row"><span>Order asal</span><span>{{ $r['order_number'] }}</span></div>
        @else
            <div class="row"><span>No.</span><span>{{ $r['number'] }}</span></div>
        @endif
        <div class="row"><span>Tanggal</span><span>{{ $r['date'] }}</span></div>
        @if ($r['cashier']) <div class="row"><span>{{ $isReturn ? 'Diproses' : 'Kasir' }}</span><span>{{ $r['cashier'] }}</span></div> @endif
        @if (! $isReturn)
            <div class="row"><span>Tipe</span><span>{{ $r['order_type'] }}{{ $r['table'] ? ' / Meja ' . $r['table'] : '' }}</span></div>
        @endif
        <hr>

        @foreach ($r['lines'] as $line)
            <div>{{ $line['name'] }}</div>
            <div class="row"><span>&nbsp;&nbsp;{{ $line['qty'] }} x {{ number_format((float) $line['unit_price'], 0, ',', '.') }}</span><span>{{ $minus }}{{ $rp($line['line_total']) }}</span></div>
        @endforeach
        <hr>

        <div class="row"><span>Subtotal</span><span>{{ $minus }}{{ $rp($r['subtotal']) }}</span></div>
        @if (! $isReturn)
            <div class="row"><span>Pajak ({{ $r['tax_rate'] }}%)</span><span>{{ $rp($r['tax']) }}</span></div>
            <div class="row"><span>Service ({{ $r['service_rate'] }}%)</span><span>{{ $rp($r['service']) }}</span></div>
        @else
            <div class="row"><span>Pajak</span><span>-{{ $rp($r['tax']) }}</span></div>
            <div class="row"><span>Service</span><span>-{{ $rp($r['service']) }}</span></div>
        @endif
        <div class="row b big"><span>{{ $isReturn ? 'DIKEMBALIKAN' : 'TOTAL' }}</span><span>{{ $minus }}{{ $rp($r['total']) }}</span></div>
        <hr>

        @if ($isReturn)
            <div class="row"><span>Dana via</span><span>{{ $r['refund_method'] }}</span></div>
            <div class="row"><span>Makanan</span><span>{{ $r['mode'] }}</span></div>
            <div class="small">Alasan: {{ $r['reason'] }}</div>
        @else
            <div class="row"><span>Bayar ({{ $r['payment_method'] }})</span><span>{{ $rp($r['amount_paid']) }}</span></div>
            @if ((float) $r['change'] > 0)
                <div class="row"><span>Kembali</span><span>{{ $rp($r['change']) }}</span></div>
            @endif

            @if (! empty($r['returns']))
                <hr>
                @foreach ($r['returns'] as $ret)
                    <div class="row small"><span>Retur {{ $ret['number'] }}</span><span>-{{ $rp($ret['total']) }}</span></div>
                @endforeach
                <div class="row b"><span>Total bersih</span><span>{{ $rp($r['net_total']) }}</span></div>
            @endif
        @endif

        <hr>
        <div class="c small">{{ $r['footer'] }}</div>
    </div>

    @if ($this->autoPrint())
        <script>window.addEventListener('load', () => setTimeout(() => window.print(), 400));</script>
    @endif
</x-filament-panels::page>
