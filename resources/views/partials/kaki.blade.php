<footer class="mv-kaki {{ $ringkas ? 'mv-kaki--ringkas' : '' }} tidak-dicetak">
    <x-foto :berkas="$fotoKaki" alt="" class="mv-kaki__foto" />
    <div class="mv-kaki__lapisan" aria-hidden="true"></div>
    <div class="mv-kaki__isi">
        <div class="mv-kaki__atas">
            <nav class="mv-kaki__tautan" aria-label="Navigasi kaki halaman">
                <a href="{{ route('peta') }}">Tempat &amp; peta</a>
                @unless (in_array(session('pengguna.peran'), ['admin', 'superadmin'], true))
                    <a href="{{ route('rencana') }}">Perencanaan Rute</a>
                    <a href="{{ route('medanvisit-hub.index') }}">MedanVisit Hub</a>
                    <a href="{{ route('hubungi.admin') }}">Hubungi Admin</a>
                @endunless
                <a href="{{ route('ketentuan') }}">Ketentuan &amp; privasi</a>
            </nav>
            <div class="mv-kaki__catatan">
                <p>Panduan wisata Kota Medan untuk membantu menemukan tempat, menyusun rute, dan merencanakan kunjungan.</p>
                <p><a href="mailto:administrator@medanvisit.neoverse.my.id">administrator@medanvisit.neoverse.my.id</a></p>
            </div>
        </div>

        <div class="mv-kaki__merek">
            <a href="{{ route('beranda') }}" aria-label="Kembali ke beranda">
                <img src="{{ asset('storage/logo_app/svg/MedanVisit_logo_horizontal_gelap.svg') }}" alt="MedanVisit" width="736" height="256">
            </a>
        </div>

        <div class="mv-kaki__legal">
            <span class="mv-meta-garis">
                <span>© {{ date('Y') }} MedanVisit</span>
            </span>
        </div>
    </div>
</footer>
