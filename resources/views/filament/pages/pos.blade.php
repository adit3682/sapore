@php
    $items = $this->catalog();
    $sum = $this->summary();
    $rp = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $methods = ['cash' => 'Tunai', 'qris' => 'QRIS', 'card' => 'Kartu', 'transfer' => 'Transfer'];
    $accent = ['Paket' => '#c26944', 'Minuman' => '#78907e'];
@endphp

<x-filament-panels::page>
    <style>
        .sp-pos { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 16px; align-items: start; }
        @media (max-width: 1024px) { .sp-pos { grid-template-columns: 1fr; } }
        .sp-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
        .sp-tab { border: 1px solid #d9d2c7; background: #fffdfa; border-radius: 999px; padding: 6px 14px; font-size: 13px; cursor: pointer; color: #232d37; }
        .sp-tab.active { background: #c26944; border-color: #c26944; color: #fff; font-weight: 600; }
        .sp-search { border: 1px solid #d9d2c7; background: #fffdfa; border-radius: 10px; padding: 7px 12px; font-size: 13px; width: 220px; color: #232d37; }
        .sp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px; }
        .sp-card { background: #fffdfa; border: 1px solid #e6dfd3; border-top: 4px solid #1a365d; border-radius: 12px; padding: 12px; text-align: left; cursor: pointer; color: #232d37; }
        .sp-card:hover { box-shadow: 0 2px 10px rgba(15,35,65,.12); }
        .sp-card .name { font-weight: 700; font-size: 14px; }
        .sp-card .price { color: #c26944; font-size: 13px; margin-top: 2px; }
        .sp-card .tag { display: inline-block; margin-top: 8px; background: #efeae3; border-radius: 6px; padding: 1px 8px; font-size: 11px; color: #64707d; }
        .sp-panel { background: #fffdfa; border: 1px solid #e6dfd3; border-radius: 14px; padding: 16px; color: #232d37; }
        .sp-row { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 8px 0; border-bottom: 1px solid #efeae3; font-size: 14px; }
        .sp-qty button { width: 24px; height: 24px; border-radius: 6px; border: 1px solid #d9d2c7; background: #fff; cursor: pointer; line-height: 1; }
        .sp-totals { background: #efeae3; border-radius: 10px; padding: 12px; margin-top: 12px; font-size: 13px; }
        .sp-totals div { display: flex; justify-content: space-between; padding: 2px 0; color: #64707d; }
        .sp-totals .total { color: #c26944; font-weight: 800; font-size: 20px; border-top: 1px solid #d9d2c7; margin-top: 6px; padding-top: 8px; }
        .sp-input { border: 1px solid #d9d2c7; background: #fff; border-radius: 8px; padding: 6px 10px; font-size: 13px; width: 100%; color: #232d37; }
        select.sp-input { background-repeat: no-repeat; background-position: right 10px center; background-size: 16px 16px; padding-right: 30px; }
        .sp-btn { width: 100%; border: 0; border-radius: 10px; padding: 12px; font-weight: 700; cursor: pointer; font-size: 14px; }
        .sp-btn.pay { background: #c26944; color: #fff; }
        .sp-btn.save { background: #1a365d; color: #fff; }
        .sp-btn.clear { background: #fff; color: #232d37; border: 1px solid #d9d2c7; }
        .sp-btn:disabled { opacity: .45; cursor: not-allowed; }
        .sp-muted { color: #64707d; font-size: 12px; }
    </style>

    <div class="sp-pos">
        <div>
            <div class="sp-tabs">
                @foreach ($this->categories() as $tab)
                    <button type="button" wire:click="setCategory('{{ $tab['key'] }}')"
                            class="sp-tab {{ $this->category === $tab['key'] ? 'active' : '' }}">{{ $tab['label'] }}</button>
                @endforeach
                <input type="search" class="sp-search" placeholder="Cari menu..." wire:model.live.debounce.300ms="search">
            </div>

            @if (empty($items))
                <p class="sp-muted">Tidak ada menu yang cocok.</p>
            @endif

            <div class="sp-grid">
                @foreach ($items as $item)
                    <button type="button" class="sp-card" wire:click="add('{{ $item['type'] }}', {{ $item['id'] }})"
                            style="border-top-color: {{ $accent[$item['tag']] ?? '#1a365d' }}">
                        <div class="name">{{ $item['name'] }}</div>
                        <div class="price">{{ $rp($item['price']) }}</div>
                        <span class="tag">{{ $item['tag'] }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        <div class="sp-panel">
            <div style="display:flex;justify-content:space-between;align-items:baseline">
                <strong style="font-size:16px">Pesanan Saat Ini</strong>
                @if ($this->orderId)
                    <span class="sp-muted">melanjutkan order tersimpan</span>
                @endif
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:10px 0">
                <input type="text" class="sp-input" placeholder="Meja (mis. 07)" wire:model="tableLabel">
                <select class="sp-input" wire:model="orderType">
                    <option value="dine_in">Dine In</option>
                    <option value="take_away">Take Away</option>
                </select>
            </div>

            @if (! empty($this->branchChoices()))
                <select class="sp-input" wire:model="branchId" style="margin-bottom:8px">
                    <option value="">Pilih cabang…</option>
                    @foreach ($this->branchChoices() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            @endif

            @forelse ($sum['lines'] as $line)
                @php $key = $line['type'] . ':' . $line['id']; @endphp
                <div class="sp-row">
                    <div style="flex:1">
                        <div>{{ $line['name'] }}</div>
                        <div class="sp-muted">{{ $rp($line['unit_price']) }}</div>
                    </div>
                    <div class="sp-qty" style="display:flex;align-items:center;gap:6px">
                        <button type="button" wire:click="decrement('{{ $key }}')">−</button>
                        <span style="min-width:20px;text-align:center">{{ (int) $line['qty'] }}</span>
                        <button type="button" wire:click="increment('{{ $key }}')">+</button>
                    </div>
                    <div style="min-width:86px;text-align:right;font-weight:700">{{ $rp($line['line_total']) }}</div>
                    <button type="button" wire:click="remove('{{ $key }}')" title="Hapus"
                            style="border:1px solid #e0b4ae;color:#b94a48;background:#fff;border-radius:999px;width:22px;height:22px;cursor:pointer">×</button>
                </div>
            @empty
                <p class="sp-muted" style="padding:16px 0">Belum ada item. Pilih menu di sebelah kiri.</p>
            @endforelse

            <div class="sp-totals">
                <div><span>Subtotal</span><span>{{ $rp($sum['subtotal']) }}</span></div>
                <div><span>Pajak ({{ (float) $sum['tax_rate'] }}%)</span><span>{{ $rp($sum['tax']) }}</span></div>
                <div><span>Service ({{ (float) $sum['service_rate'] }}%)</span><span>{{ $rp($sum['service']) }}</span></div>
                <div class="total"><span>Total</span><span>{{ $rp($sum['total']) }}</span></div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:12px 0 4px">
                <select class="sp-input" wire:model.live="paymentMethod">
                    @foreach ($methods as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @if ($this->paymentMethod === 'cash')
                    <input type="number" min="0" step="1" class="sp-input" placeholder="Uang diterima" wire:model.live.debounce.300ms="amountPaid">
                @endif
            </div>
            @if ($this->paymentMethod === 'cash' && is_numeric($this->amountPaid))
                <div class="sp-muted" style="margin-bottom:8px">Kembalian: <strong>{{ $rp($this->change()) }}</strong></div>
            @endif

            <div style="display:grid;gap:8px;margin-top:8px">
                <button type="button" class="sp-btn pay" wire:click="pay" wire:loading.attr="disabled" @disabled(empty($sum['lines']))>Bayar Sekarang</button>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                    <button type="button" class="sp-btn save" wire:click="saveOrder" @disabled(empty($sum['lines']))>Simpan Order</button>
                    <button type="button" class="sp-btn clear" wire:click="clearCart">Clear</button>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>