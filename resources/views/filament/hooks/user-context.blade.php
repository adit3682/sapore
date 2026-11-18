@php
    $u = auth()->user();
    $role = $u?->role instanceof \BackedEnum ? $u->role->value : (string) ($u?->role ?? '');
    $labels = ['owner' => 'Owner', 'manager' => 'Manager', 'chef' => 'Chef', 'inventory' => 'Inventory', 'cashier' => 'Kasir'];
@endphp
@if ($u)
    <span class="sp-user-context">{{ $labels[$role] ?? ucfirst($role) }}@if ($u->branch) • {{ $u->branch->name }}@endif</span>
@endif
