@extends('layouts.situs')
@section('judul', 'Ulasan saya')
@section('tanpa-kaki', '1')

@section('isi')
<div class="alat">
    <div class="alat__judul"><div><h1>Ulasan saya</h1><p>Ulasan langsung ditayangkan setelah dikirim.</p></div></div>
    @include('partials.subnav-akun')
    @if (session('pesan'))
        <div class="pesan pesan--sukses" role="status" style="margin-bottom:20px">{{ session('pesan') }}</div>
    @endif
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
                            <td class="sekunder">{{ \Illuminate\Support\Str::limit($ul['teks'], 90) }}</td>
                            <td class="sekunder"><x-bintang :n="$ul['bintang']" /></td>
                            <td class="n"><span class="p-pill {{ ['tayang' => 'pos', 'ditolak' => 'neg', 'menunggu' => 'warn'][$ul['status']] }}">{{ ['tayang' => 'Tayang', 'ditolak' => 'Ditolak', 'menunggu' => 'Menunggu'][$ul['status']] }}</span></td>
                            <td>
                                <details @if ($errors->any() && (string) old('ulasan_id') === $ul['id']) open @endif>
                                    <summary class="tombol tombol--garis" style="cursor:pointer">Edit</summary>
                                    <form method="post" action="{{ route('ulasan.perbarui', $ul['id']) }}" style="min-width:240px;padding:12px 0">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="ulasan_id" value="{{ $ul['id'] }}">
                                        @php
                                            $nilaiLama = (string) old('ulasan_id') === $ul['id'];
                                            $bintangTerpilih = $nilaiLama ? old('bintang', $ul['bintang']) : $ul['bintang'];
                                            $teksTerpilih = $nilaiLama ? old('teks', $ul['teks']) : $ul['teks'];
                                        @endphp
                                        <label for="bintang-{{ $ul['id'] }}">Rating</label>
                                        <select id="bintang-{{ $ul['id'] }}" name="bintang" required>
                                            @if ((int) $bintangTerpilih < 1)
                                                <option value="" selected disabled>Pilih rating</option>
                                            @endif
                                            @foreach (range(1, 5) as $bintang)
                                                <option value="{{ $bintang }}" @selected((int) $bintangTerpilih === $bintang)>{{ $bintang }} bintang</option>
                                            @endforeach
                                        </select>
                                        <label for="teks-{{ $ul['id'] }}" style="display:block;margin-top:8px">Ulasan</label>
                                        <textarea id="teks-{{ $ul['id'] }}" name="teks" rows="4" minlength="10" maxlength="600" required>{{ $teksTerpilih }}</textarea>
                                        <button class="tombol" type="submit" style="margin-top:8px">Simpan perubahan</button>
                                    </form>
                                    <form method="post" action="{{ route('ulasan.hapus-saya', $ul['id']) }}" onsubmit="return confirm('Hapus ulasan dan rating ini?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="tombol tombol--garis" type="submit">Hapus</button>
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
@endsection
