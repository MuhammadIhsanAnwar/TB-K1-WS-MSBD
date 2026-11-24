@php
    $contohTiket = array_values(\App\Support\Katalog::bertiket())[0] ?? null;
    $contohTempat = array_values(\App\Support\Katalog::tempat())[0] ?? null;
    $contohKuliner = array_values(\App\Support\Katalog::tempat('kuliner'))[0] ?? null;
@endphp
<div class="mv-asisten-widget tidak-dicetak" data-ui data-asisten-widget
    data-url="{{ route('tanya.jawab') }}"
    data-maskot-menyapa="{{ asset('storage/maskotAIAgent/mascot-menyapa.png') }}"
    data-maskot-mendengarkan="{{ asset('storage/maskotAIAgent/mascot-mendengarkan.png') }}"
    data-maskot-berpikir="{{ asset('storage/maskotAIAgent/mascot-berpikir.png') }}"
    data-maskot-membantu="{{ asset('storage/maskotAIAgent/mascot-membantu.png') }}"
    data-maskot-bingung="{{ asset('storage/maskotAIAgent/mascot-bingung.png') }}"
    data-maskot-sukses="{{ asset('storage/maskotAIAgent/mascot-sukses.png') }}"
>
    <button class="mv-asisten-widget__pemicu" type="button" data-buka-asisten aria-expanded="false" aria-controls="panel-asisten-melayang">
        <!-- Maskot Mas Nonov berada tepat di atas tombol sebelum chatbox dibuka -->
        <span class="mv-asisten-widget__maskot-atas" aria-hidden="true">
            <img src="{{ asset('storage/maskotAIAgent/mascot-menyapa.png') }}" alt="" class="mv-maskot-atas-img">
        </span>
        <span class="mv-asisten-widget__pemicu-avatar" aria-hidden="true">
            <img src="{{ asset('storage/maskotAIAgent/mascot-menyapa.png') }}" alt="Mas Nonov" width="36" height="36" data-pemicu-maskot>
        </span>
        <span>Tanya Mas Nonov</span>
    </button>

    <div class="mv-asisten-panel" id="panel-asisten-melayang" role="dialog" aria-label="Mas Nonov - Asisten Wisata MedanVisit" hidden>
        <!-- Maskot Mas Nonov muncul di samping miring chatbox -->
        <div class="mv-asisten-panel__maskot-samping" aria-hidden="true">
            <img src="{{ asset('storage/maskotAIAgent/mascot-menyapa.png') }}" alt="" class="mv-maskot-samping-img" data-maskot-samping>
        </div>

        <header class="mv-asisten-panel__kepala">
            <div class="mv-asisten-panel__identitas">
                <span class="mv-asisten-panel__avatar" aria-hidden="true">
                    <img src="{{ asset('storage/maskotAIAgent/mascot-menyapa.png') }}" alt="Mas Nonov - Maskot Asisten MedanVisit" width="46" height="46" data-panel-maskot>
                </span>
                <div>
                    <b>Mas Nonov</b>
                    <span class="mv-asisten-panel__status" data-panel-status>Asisten Wisata MedanVisit</span>
                </div>
            </div>
            <div class="mv-asisten-panel__kontrol">
                <button type="button" class="mv-asisten-panel__ikon" data-tutup-asisten aria-label="Tutup panel asisten">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
        </header>

        <div class="mv-asisten-panel__log" data-widget-log aria-live="polite">
            <div class="mv-gelembung mv-gelembung--asisten">
                <p>Halo! Aku <b>Mas Nonov</b>, maskot asisten wisatamu di Medan. Aku bisa bantu cari tempat wisata, kuliner khas, rute jalan kaki, dan info harga tiket. Mau mulai dari mana?</p>
            </div>
            <div class="mv-asisten-panel__saran" data-widget-saran>
                @if ($contohTiket)
                    <button type="button">Berapa harga tiket {{ $contohTiket['nama'] }}?</button>
                @endif
                @if ($contohKuliner)
                    <button type="button">Info kuliner {{ $contohKuliner['nama'] }}</button>
                @endif
                @if ($contohTempat)
                    <button type="button">Rute setengah hari dari {{ $contohTempat['nama'] }}</button>
                    <button type="button">Tempat wisata dekat {{ $contohTempat['nama'] }}</button>
                @endif
            </div>
        </div>

        <form class="mv-asisten-panel__form" data-widget-form>
            <label class="sr-only" for="pesan-widget-asisten">Tulis pertanyaan untuk Mas Nonov</label>
            <textarea id="pesan-widget-asisten" name="pesan" rows="1" maxlength="300" placeholder="Tulis pesan untuk Mas Nonov…" required></textarea>
            <button type="submit" aria-label="Kirim pertanyaan">Kirim</button>
        </form>
        <p class="mv-asisten-panel__catatan">Berdasarkan katalog MedanVisit. Pastikan kembali jam buka dan harga di lokasi sebelum berangkat.</p>
    </div>
</div>
