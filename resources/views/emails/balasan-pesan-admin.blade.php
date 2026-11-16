<!doctype html>
<html lang="id">
<body>
    <p>Halo {{ $namaPengguna }},</p>
    <p>{{ $namaAdmin }} dari tim MedanVisit membalas pesanmu.</p>
    <p><strong>Kategori:</strong> {{ $kategori }}@if ($namaTempat) — {{ $namaTempat }}@endif</p>
    <p><strong>Pesanmu:</strong></p>
    <p>{!! nl2br(e($pesanAwal)) !!}</p>
    <p><strong>Balasan:</strong></p>
    <p>{!! nl2br(e($balasan)) !!}</p>
    <p>Salam,<br>Tim MedanVisit</p>
</body>
</html>
