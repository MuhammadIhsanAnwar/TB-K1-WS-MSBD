@props(['tempat', 'lengkap' => false])
@php $min = \App\Support\Katalog::hargaTermurah($tempat); @endphp
@if ($min !== null)
    <span {{ $attributes->merge(['class' => 'harga']) }}>{{ $lengkap ? 'Mulai ' : '' }}{{ \App\Support\Katalog::rupiah($min) }}</span>
@else
    <span {{ $attributes->merge(['class' => 'harga']) }}>{{ \App\Support\Katalog::labelHarga($tempat) }}</span>
@endif
