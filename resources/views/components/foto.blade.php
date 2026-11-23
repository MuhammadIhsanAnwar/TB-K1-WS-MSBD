@props(['berkas', 'alt' => '', 'utama' => false, 'kecil' => false, 'segera' => false])
@php
    // Seluruh aset tempat memakai folder foto_wisata. Parameter kecil dipertahankan
    // agar pemanggil lama tetap kompatibel; optimasi ukuran dilakukan browser/CDN.
    $jalur = $berkas;
    [$w, $h] = \App\Support\Foto::ukuran($jalur);
@endphp
<img src="{{ \App\Support\Foto::url($jalur) }}" alt="{{ $alt }}" width="{{ $w }}" height="{{ $h }}"
     @if ($utama) fetchpriority="high" @elseif (! $segera) loading="lazy" @endif decoding="async"
     {{ $attributes }}>
