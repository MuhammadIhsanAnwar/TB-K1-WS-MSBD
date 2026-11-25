@extends('layouts.situs')
@section('judul', 'Ulasan saya')
@section('tanpa-kaki', '1')

@section('isi')
<div class="alat">
    <div class="alat__judul"><div><h1>Ulasan saya</h1><p>Ulasan langsung ditayangkan setelah dikirim.</p></div></div>
    @include('partials.subnav-akun')
    @if ($errors->any())
        <div class="pesan pesan--galat" role="alert" style="margin-bottom:20px">{{ $errors->first() }}</div>
    @endif
    @if ($ulasan)
        <div class="p-panel">
            <table class="p-tabel jadi-daftar">
                <thead><tr><th>Tempat</th><th>Ulasan</th><th>Bintang</th><th class="n">Status</th><th>Aksi</th></tr></thead>
                <tbody>
                    @foreach ($ulasan as $ul)
                        @php $t = \App\Support\Katalog::get($ul['tempat']); @endphp
                        <tr>
                            <td><a class="nama" href="{{ route('tempat', $t['slug']) }}">{{ $t['nama'] }}</a><span class="sub">{{ \Illuminate\Support\Carbon::parse($ul['waktu'])->locale('id')->translatedFormat('j M Y') }}</span></td>
                            <td class="sekunder">{{ \Illuminate\Support\Str::limit($ul['teks'], 150) }}</td>
                            <td class="sekunder"><x-bintang :n="$ul['bintang']" /></td>
                            <td class="n"><span class="p-pill {{ ['tayang' => 'pos', 'ditolak' => 'neg', 'menunggu' => 'warn'][$ul['status']] }}">{{ ['tayang' => 'Tayang', 'ditolak' => 'Ditolak', 'menunggu' => 'Menunggu'][$ul['status']] }}</span></td>
                            <td>
                                <details class="ulasan-saya__editor" @if ($errors->any() && (string) old('ulasan_id') === $ul['id']) open @endif>
                                    <summary class="tombol tombol--garis">Edit ulasan</summary>
                                    <form class="ulasan-saya__form" method="post" action="{{ route('ulasan.perbarui', $ul['id']) }}">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="ulasan_id" value="{{ $ul['id'] }}">
                                        @php
                                            $nilaiLama = (string) old('ulasan_id') === $ul['id'];
                                            $bintangTerpilih = $nilaiLama ? old('bintang', $ul['bintang']) : $ul['bintang'];
                                            $teksTerpilih = $nilaiLama ? old('teks', $ul['teks']) : $ul['teks'];
                                        @endphp
                                        <fieldset class="isian">
                                            <legend>Perbarui rating</legend>
                                            <div class="ulasan-rating" role="radiogroup" aria-label="Pilih rating dari 1 sampai 5 bintang">
                                                @for ($bintang = 5; $bintang >= 1; $bintang--)
                                                    <label title="{{ $bintang }} bintang">
                                                        <input type="radio" name="bintang" value="{{ $bintang }}" @checked((int) $bintangTerpilih === $bintang) required>
                                                        <svg viewBox="0 0 20 20" aria-hidden="true">
                                                            <path d="M10 1.8l2.5 5.2 5.7.8-4.1 4 1 5.6L10 14.7l-5.1 2.7 1-5.6-4.1-4 5.7-.8z"/>
                                                        </svg>
                                                        <span class="sr-only">{{ $bintang }} bintang</span>
                                                    </label>
                                                @endfor
                                            </div>
                                            @if ($nilaiLama)
                                                @error('bintang')<p class="isian__galat">{{ $message }}</p>@enderror
                                            @endif
                                        </fieldset>
                                        <div class="isian">
                                            <label for="teks-{{ $ul['id'] }}">Tulis ulasanmu</label>
                                            <textarea id="teks-{{ $ul['id'] }}" name="teks" rows="5" minlength="10" maxlength="600" placeholder="Ceritakan pengalaman, suasana, atau tips berkunjung..." required>{{ $teksTerpilih }}</textarea>
                                            <p class="isian__bantuan">10–600 karakter. Ulasanmu membantu pengunjung lain merencanakan perjalanan.</p>
                                            @if ($nilaiLama)
                                                @error('teks')<p class="isian__galat">{{ $message }}</p>@enderror
                                            @endif
                                        </div>
                                        <button class="tombol tombol--hijau" type="submit">Simpan perubahan</button>
                                    </form>
                                    <form method="post" action="{{ route('ulasan.hapus-saya', $ul['id']) }}" data-konfirmasi="Ulasan dan rating ini akan dihapus permanen. Lanjutkan?">
                                        @csrf
                                        @method('DELETE')
                                        <button class="tombol tombol--garis ulasan-saya__hapus" type="submit">Hapus ulasan</button>
                                    </form>
                                </details>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="kosong"><p>Belum ada ulasan. Setelah berkunjung, tulis ulasan di halaman tempat itu.</p><a class="tombol" href="{{ route('peta') }}">Cari tempat</a></div>
    @endif
</div>
@include('partials.konfirmasi')
@endsection
