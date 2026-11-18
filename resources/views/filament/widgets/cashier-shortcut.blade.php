<x-filament-widgets::widget>
    <x-filament::section>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
            <div>
                <div style="font-family:Georgia,serif;font-size:20px;font-weight:700;color:#0f2341">Selamat bekerja, {{ $this->name() }}</div>
                <div style="color:#64707d;font-size:14px">Buka Kasir untuk mulai mencatat pesanan.</div>
            </div>
            <x-filament::button tag="a" :href="$this->url()">Buka Kasir</x-filament::button>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
