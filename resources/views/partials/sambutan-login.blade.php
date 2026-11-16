@if (session('sambutan'))
    <div class="mv-sambutan" data-sambutan role="dialog" aria-modal="true" aria-labelledby="judul-sambutan">
        <div class="mv-sambutan__kartu">
            <button class="mv-sambutan__tutup" type="button" data-tutup-sambutan aria-label="Tutup sambutan">&times;</button>
            <img class="mv-sambutan__maskot" src="{{ asset('storage/maskotAIAgent/mascot-menyapa.png') }}" alt="" width="160" height="160">
            <p class="mv-sambutan__label">MedanVisit menyambutmu</p>
            <h2 id="judul-sambutan">Selamat datang, {{ session('sambutan') }}!</h2>
            <p class="mv-sambutan__teks">Menyiapkan perjalanan wisatamu di Medan…</p>
            <div class="mv-sambutan__pemuatan" aria-hidden="true"><span></span></div>
        </div>
    </div>
@endif
