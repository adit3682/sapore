<x-filament-panels::page>
    @if ($this->mustChange())
        <div style="background:#fbeee6;border-left:4px solid #c26944;border-radius:8px;padding:12px 16px;color:#232d37">
            Password Anda masih sementara. Ganti dulu sebelum memakai aplikasi.
        </div>
    @endif

    <x-filament::section>
        <form wire:submit="save" style="max-width:28rem">
            {{ $this->form }}

            <div style="margin-top:16px">
                <x-filament::button type="submit">Simpan Password</x-filament::button>
            </div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
