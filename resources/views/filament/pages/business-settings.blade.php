<x-filament-panels::page>
    <form wire:submit="save" style="display:grid;gap:16px;max-width:52rem">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit" icon="heroicon-o-check">Simpan Pengaturan</x-filament::button>
        </div>
    </form>

    @php $overrides = $this->branchOverrides(); @endphp
    @if (! empty($overrides))
        <x-filament::section heading="Cabang dengan tarif sendiri" description="Tarif ini menggantikan pengaturan umum di atas. Ubah lewat Pengaturan > Cabang.">
            <table style="width:100%;font-size:14px;border-collapse:collapse">
                <thead>
                    <tr style="text-align:left;color:#64707d">
                        <th style="padding:6px 8px">Cabang</th>
                        <th style="padding:6px 8px">Pajak</th>
                        <th style="padding:6px 8px">Service</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($overrides as $row)
                        <tr>
                            <td style="padding:6px 8px">{{ $row['name'] }}</td>
                            <td style="padding:6px 8px">{{ $row['tax'] }}</td>
                            <td style="padding:6px 8px">{{ $row['service'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
