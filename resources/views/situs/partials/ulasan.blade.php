@foreach ($ulasan as $ul)
    <article class="ulasan-kartu">
        <p><b>{{ $ul['nama'] }}</b> <x-bintang :n="$ul['bintang']" /></p>
        <p>{{ $ul['teks'] }}</p>
        <p class="kecil redup">{{ \Illuminate\Support\Carbon::parse($ul['waktu'])->locale('id')->translatedFormat('j F Y') }}</p>
    </article>
@endforeach
