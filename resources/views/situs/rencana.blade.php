
@extends('layouts.situs')
@php
    use App\Support\Katalog;

    $pref ??= null;
    $dibagikan ??= null;
    $rencanaEdit ??= null;
    $kategoriTempat = array_values(array_unique(array_filter(array_column($tempat, 'kategori'))));
    $minat = $pref['minat'] ?? $kategoriTempat;
    $durasi = (int) ($pref['durasi'] ?? 4);
    $moda = $pref['moda'] ?? ['jalan', 'motor', 'mobil'];
    $tujuan = $pref['tujuan'] ?? [];
    // bawaan: mulai dari posisi pengguna sekarang (dibaca browser saat rute disusun)
    $mulai = $pref['mulai'] ?? 'lokasi';

    $fotoRute = collect(Katalog::entitas())->first(fn ($entity) => $entity['jenis'] === 'transportasi' && ! empty($entity['foto']))
        ?? collect($tempat)->first(fn ($entity) => ! empty($entity['foto']))
        ?? [];

    // mulai dari posisi pengguna: titik "Posisimu" menjadi awal garis rute di peta
    $dariLokasi = ! empty($hasil['awal']);
    $tujuanSimpan = $hasil && $tujuan
        ? array_column(array_column($hasil['singgah'], 'tempat'), 'slug')
        : [];
    $awalTempat = $hasil && ! $dariLokasi && $mulai !== 'lokasi'
        ? Katalog::get($mulai)
        : null;
    $titikAwal = $dariLokasi
        ? [['nama' => 'Posisimu', 'lat' => $hasil['awal']['lat'], 'lng' => $hasil['awal']['lng'], 'awal' => true]]
        : ($awalTempat && ($hasil['singgah'][0]['tempat']['slug'] ?? null) !== $mulai
            ? [['nama' => $awalTempat['nama'], 'lat' => $awalTempat['lat'], 'lng' => $awalTempat['lng'], 'awal' => true]]
            : []);
    $rute = $hasil ? collect($titikAwal)
        ->concat(collect($hasil['singgah'])->map(fn ($s) => [
            'nama' => $s['tempat']['nama'],
            'lat' => $s['tempat']['lat'],
            'lng' => $s['tempat']['lng'],
            'url' => route('tempat', $s['tempat']['slug']),
            'harga' => Katalog::labelHarga($s['tempat']),
            'tiket' => ! empty($s['tempat']['tiket']) ? route('tiket.pilih', $s['tempat']['slug']) : null,
            'moda' => $s['pindah']['moda'] ?? null,
        ]))->values() : null;

    $judulRute = $dibagikan['judul'] ?? (($durasi <= 4 ? 'Setengah hari' : 'Sehari').' dari '.($mulai === 'lokasi' ? 'lokasimu' : (Katalog::get($mulai)['nama'] ?? 'tempat pilihan')));
    // lokasi pengguna tidak disimpan: rencana yang disimpan dimulai dari persinggahan pertama
    $mulaiSimpan = $dariLokasi ? $hasil['singgah'][0]['tempat']['slug'] : $mulai;

    $bertiket = $hasil ? collect($hasil['singgah'])->pluck('tempat')->filter(fn ($t) => ! empty($t['tiket'])) : collect();
@endphp

@section('judul', $dibagikan ? $judulRute : 'Rencana')
@section('foto-kaki', $fotoRute['foto'] ?? 'kesawan.jpg')

@section('isi')
<div class="rencana">
    <form class="rencana__form" action="{{ route('rencana') }}" method="get" novalidate data-rencana-form>
        <h1 style="font-size:var(--s-head);margin-bottom:12px">Rencana perjalanan</h1>
        <p style="margin-bottom:32px">Pilih minat untuk rekomendasi otomatis, atau tentukan beberapa tujuan sendiri. Rute diurutkan agar perjalanan lebih praktis.</p>

        @if ($errors->any())
            <div class="pesan pesan--galat" role="alert" style="margin-bottom:24px">{{ $errors->first() }}</div>
        @endif

        @if ($rencanaEdit)
            <input type="hidden" name="edit" value="{{ $rencanaEdit['kode'] }}">
        @endif
        <input type="hidden" name="tujuan_dikirim" value="1">

        <fieldset>
            <legend>Minat</legend>
            <div class="pilih-banyak">
                @foreach (Katalog::KATEGORI as $k => $label)
                    <label><input type="checkbox" name="minat[]" value="{{ $k }}" @checked(in_array($k, $minat, true))>{{ $label }}</label>
                @endforeach
            </div>
        </fieldset>

        <fieldset>
            <legend>Lama waktu</legend>
            <div class="pilih-banyak">
                @foreach ([2 => '2 jam', 4 => '4 jam', 6 => '6 jam', 8 => 'Seharian'] as $d => $label)
                    <label><input type="radio" name="durasi" value="{{ $d }}" @checked($durasi === $d)>{{ $label }}</label>
                @endforeach
            </div>
        </fieldset>

        <fieldset>
            <legend>Transportasi</legend>
            <div class="pilih-banyak">
                <label><input type="checkbox" name="moda[]" value="jalan" @checked(in_array('jalan', $moda, true))>Jalan kaki</label>
                <label><input type="checkbox" name="moda[]" value="motor" @checked(in_array('motor', $moda, true))>Motor</label>
                <label><input type="checkbox" name="moda[]" value="mobil" @checked(in_array('mobil', $moda, true))>Mobil</label>
            </div>
        </fieldset>

        <fieldset>
            <legend>Tempat tujuan <span class="redup">(opsional)</span></legend>
            <p class="isian__bantuan">Pilih hingga 10 tempat untuk menentukan tujuan. Jika dikosongkan, tempat direkomendasikan dari minatmu.</p>
            <div class="isian" style="margin:12px 0">
                <label for="cari-tujuan">Cari tempat</label>
                <input id="cari-tujuan" type="search" autocomplete="off" placeholder="Ketik nama tempat" data-cari-tujuan>
            </div>
            <div class="rencana__tujuan-list" data-daftar-tujuan>
                @foreach ($tempat as $t)
                    <label class="rencana__tujuan-item" data-tujuan-item="{{ mb_strtolower($t['nama']) }}">
                        <input type="checkbox" name="tujuan[]" value="{{ $t['slug'] }}" @checked(in_array($t['slug'], $tujuan, true))>
                        <span>{{ $t['nama'] }}<small>{{ Katalog::KATEGORI[$t['kategori']] ?? 'Tempat wisata' }}</small></span>
                    </label>
                @endforeach
            </div>
            <button class="tombol tombol--garis" type="button" style="margin-top:10px" data-kosongkan-tujuan>Hapus pilihan tujuan</button>
        </fieldset>

        <div class="isian" style="margin-bottom:28px">
            <label for="mulai">Mulai dari</label>
            <select id="mulai" name="mulai" data-pilih-mulai aria-describedby="kabar-lokasi">
                <option value="lokasi" @selected($mulai === 'lokasi')>Lokasi saya sekarang</option>
                @foreach ($tempat as $t)
                    <option value="{{ $t['slug'] }}" @selected($t['slug'] === $mulai)>{{ $t['nama'] }}</option>
                @endforeach
            </select>
            {{-- diisi browser saat "Lokasi saya sekarang" dipilih; dibulatkan sekitar 11 m --}}
            <input type="hidden" name="lat" value="{{ $pref['lat'] ?? '' }}" data-lat>
            <input type="hidden" name="lng" value="{{ $pref['lng'] ?? '' }}" data-lng>
            <p class="isian__bantuan" id="kabar-lokasi" data-kabar-lokasi role="status" aria-live="polite" @unless ($mulai === 'lokasi') hidden @endunless>Browser akan meminta izin membaca lokasimu saat rute disusun.</p>
        </div>

        <button class="tombol tombol--lebar" type="submit">{{ $hasil ? 'Susun ulang rute' : 'Susun rute' }}</button>
    </form>

    <section class="rencana__hasil" aria-labelledby="judul-hasil">
        @if ($hasil)
            <h2 id="judul-hasil" style="font-size:2.25rem">{{ $judulRute }}</h2>

            <dl class="mv-rencana-spek nums" style="margin:14px 0 24px;max-width:560px">
                <div>
                    <dd>{{ count($hasil['singgah']) }} tempat</dd>
                    <dt>{{ collect($minat)->map(fn ($m) => Katalog::KATEGORI[$m])->implode(', ') }}</dt>
                </div>
                <div>
                    <dd>{{ $durasi }} jam</dd>
                    <dt>Durasi rencana</dt>
                </div>
                <div>
                    <dd>{{ collect($moda)->map(fn ($m) => ['jalan' => 'Jalan kaki', 'motor' => 'Motor', 'mobil' => 'Mobil'][$m] ?? $m)->implode(' & ') }}</dd>
                    <dt>Transportasi</dt>
                </div>
            </dl>

            <div class="slip" aria-label="Urutan singgah">
                @if ($dariLokasi)
                    <div class="slip__baris">
                        <span>{{ Katalog::jamTeks($hasil['singgah'][0]['mulai'] - $hasil['singgah'][0]['pindah']['menit']) }} Berangkat dari lokasimu</span>
                        <span class="titik-titik"></span>
                        <span class="kanan">titik awal</span>
                    </div>
                @endif
                @foreach ($hasil['singgah'] as $s)
                    @if ($s['pindah'])
                        <div class="slip__baris redup">
                            <span>{{ $s['pindah']['moda'] }} {{ number_format($s['pindah']['meter'], 0, ',', '.') }} m</span>
                            <span class="titik-titik"></span>
                            <span class="kanan">{{ $s['pindah']['menit'] }} mnt{{ $s['pindah']['biaya'] ? ' ('.Katalog::rupiah($s['pindah']['biaya']).')' : '' }}</span>
                        </div>
                    @endif

                    <div class="slip__baris">
                        <span>{{ Katalog::jamTeks($s['mulai']) }} <a href="{{ route('tempat', $s['tempat']['slug']) }}">{{ $s['tempat']['nama'] }}</a></span>
                        <span class="titik-titik"></span>
                        <span class="kanan">{{ $s['tempat']['singgah'] }} mnt</span>
                    </div>
                @endforeach

                <div class="slip__akhir">
                    <span>Selesai {{ Katalog::jamTeks($hasil['selesai']) }}, {{ count($hasil['singgah']) }} tempat</span>
                    <span>{{ $hasil['biaya'] === null ? 'Belum bisa dihitung: ada harga tiket yang belum tersedia.' : Katalog::rupiah($hasil['biaya']) }}</span>
                </div>

                <p class="kecil redup" style="margin-top:12px">Perkiraan tiket per orang. Biaya bensin dan parkir motor atau mobil tidak termasuk.</p>
            </div>

            @if (count($hasil['singgah']) === 1 && ! $tujuan)
                <p class="pesan pesan--galat" style="margin-top:20px">Tidak ada tempat lain yang cocok dalam waktu ini. Tambah minat atau perpanjang waktu.</p>
            @endif

            <div class="rencana__peta" data-peta data-peta-mode="rute" data-rute="{{ json_encode($rute) }}" tabindex="0" aria-label="Peta rute">
                <p class="peta-diam">Peta rute dimuat di sini.</p>
            </div>

            <div class="rencana__aksi">
                @if ($dibagikan)
                    @php $tautan = route('rencana.bagikan', $dibagikan['kode']); @endphp
                    <button class="tombol tombol--hijau" type="button" data-salin="{{ $tautan }}">Salin tautan rencana</button>
                    <a class="tombol tombol--garis" href="https://wa.me/?text={{ urlencode($judulRute.': '.$tautan) }}" rel="noopener">Bagikan ke WhatsApp</a>
                @elseif (session('pengguna'))
                    <form method="post" action="{{ $rencanaEdit ? route('rencana.perbarui', $rencanaEdit['kode']) : route('rencana.simpan') }}" style="display:flex;flex-wrap:wrap;gap:12px;align-items:end">
                        @csrf
                        @if ($rencanaEdit) @method('PUT') @endif
                        @foreach ($minat as $m)
                            <input type="hidden" name="minat[]" value="{{ $m }}">
                        @endforeach
                        @foreach ($moda as $m)
                            <input type="hidden" name="moda[]" value="{{ $m }}">
                        @endforeach
                        @foreach ($tujuanSimpan as $slug)
                            <input type="hidden" name="tujuan[]" value="{{ $slug }}">
                        @endforeach
                        <input type="hidden" name="durasi" value="{{ $durasi }}">
                        <input type="hidden" name="mulai" value="{{ $mulaiSimpan }}">

                        <div class="isian" style="min-width:260px">
                            <label for="judul-rencana">Nama rencana</label>
                            <input id="judul-rencana" type="text" name="judul" value="{{ $judulRute }}" maxlength="80" required @if ($dariLokasi) aria-describedby="catatan-simpan" @endif>
                            @if ($dariLokasi)
                                <p class="isian__bantuan" id="catatan-simpan">Lokasimu tidak disimpan. Rencana yang disimpan dimulai dari {{ $hasil['singgah'][0]['tempat']['nama'] }}.</p>
                            @endif
                        </div>

                        <button class="tombol tombol--hijau" type="submit">{{ $rencanaEdit ? 'Simpan perubahan' : 'Simpan rencana' }}</button>
                    </form>
                @else
                    <a class="tombol tombol--hijau" href="{{ route('masuk', ['kembali' => request()->getRequestUri()]) }}">Masuk untuk menyimpan</a>
                @endif

                @foreach ($bertiket as $t)
                    <a class="tombol" href="{{ route('tiket.pilih', $t['slug']) }}">Tiket {{ $t['nama'] }}</a>
                @endforeach
            </div>
        @else
            <h2 id="judul-hasil" style="font-size:2.25rem">Rutemu muncul di sini</h2>
            <p style="margin-top:12px">Pilih tempat awal, minat, durasi, dan transportasi. Tentukan beberapa tujuan sendiri atau biarkan rekomendasi menyusunnya otomatis.</p>
            <x-foto :berkas="$fotoRute['foto'] ?? 'kesawan.jpg'" :alt="$fotoRute['nama'] ?? 'Tempat wisata di Medan'" style="margin-top:28px;width:100%;aspect-ratio:16/10;object-fit:cover" />
        @endif
    </section>
</div>
@endsection
