@foreach ($ulasan as $ul)
    @php
        $penggunaSaatIni = session('pengguna', []);
        $ulasanMilikSaya = ($penggunaSaatIni['peran'] ?? null) === 'wisatawan'
            && (int) ($penggunaSaatIni['id'] ?? 0) === (int) $ul['id_pengguna'];
        $formTerpilih = (string) old('ulasan_id') === $ul['id'];
        $bintangTerpilih = $formTerpilih ? (int) old('bintang', $ul['bintang']) : (int) $ul['bintang'];
    @endphp
    <article class="ulasan-kartu">
        <div class="ulasan-kartu__kepala">
            <p><b>{{ $ul['nama'] }}</b></p>
            <x-bintang :n="$ul['bintang']" />
        </div>
        <p class="ulasan-kartu__teks">{{ $ul['teks'] }}</p>
        <div class="ulasan-kartu__kaki">
            <p class="kecil redup">{{ \Illuminate\Support\Carbon::parse($ul['waktu'])->locale('id')->translatedFormat('j F Y') }}</p>
            @if ($ulasanMilikSaya)
                <details class="ulasan-kartu__editor" @if ($errors->any() && $formTerpilih) open @endif>
                    <summary class="mv-btn mv-btn--garis">Edit ulasan</summary>
                    <form class="ulasan-kartu__form" method="post" action="{{ route('ulasan.perbarui', $ul['id']) }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="ulasan_id" value="{{ $ul['id'] }}">
                        <input type="hidden" name="kembali" value="tempat">
                        <fieldset class="isian">
                            <legend>Perbarui rating</legend>
                            <div class="ulasan-rating" role="radiogroup" aria-label="Pilih rating dari 1 sampai 5 bintang">
                                @for ($bintang = 5; $bintang >= 1; $bintang--)
                                    <label title="{{ $bintang }} bintang">
                                        <input type="radio" name="bintang" value="{{ $bintang }}" @checked($bintangTerpilih === $bintang) required>
                                        <svg viewBox="0 0 20 20" aria-hidden="true">
                                            <path d="M10 1.8l2.5 5.2 5.7.8-4.1 4 1 5.6L10 14.7l-5.1 2.7 1-5.6-4.1-4 5.7-.8z"/>
                                        </svg>
                                        <span class="sr-only">{{ $bintang }} bintang</span>
                                    </label>
                                @endfor
                            </div>
                            @if ($formTerpilih)
                                @error('bintang')<p class="isian__galat">{{ $message }}</p>@enderror
                            @endif
                        </fieldset>
                        <div class="isian">
                            <label for="teks-ulasan-{{ $ul['id'] }}">Tulis ulasanmu</label>
                            <textarea id="teks-ulasan-{{ $ul['id'] }}" name="teks" rows="5" minlength="10" maxlength="600" required>{{ $formTerpilih ? old('teks', $ul['teks']) : $ul['teks'] }}</textarea>
                            <p class="isian__bantuan">10–600 karakter. Ulasanmu membantu pengunjung lain.</p>
                            @if ($formTerpilih)
                                @error('teks')<p class="isian__galat">{{ $message }}</p>@enderror
                            @endif
                        </div>
                        <button class="mv-btn mv-btn--hijau" type="submit">Simpan perubahan</button>
                    </form>
                    <form method="post" action="{{ route('ulasan.hapus-saya', $ul['id']) }}" data-konfirmasi="Ulasan dan rating ini akan dihapus permanen. Lanjutkan?">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="kembali" value="tempat">
                        <button class="mv-btn mv-btn--garis ulasan-kartu__hapus" type="submit">Hapus ulasan</button>
                    </form>
                </details>
            @endif
        </div>
    </article>
@endforeach
