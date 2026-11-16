<?php

namespace App\Http\Controllers;

use App\Support\Katalog;
use App\Support\Database3CatalogWriter;
use App\Support\Kunjungan;
use App\Support\PesanLayanan;
use App\Support\Penyimpanan;
use App\Support\SupabaseApi;
use Illuminate\Http\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function pesan(Request $request)
    {
        $pengguna = $request->session()->get('pengguna', []);

        return view('admin.pesan', ['pesan' => PesanLayanan::daftar($pengguna)]);
    }

    public function detailPesan(Request $request, int $id)
    {
        $pengguna = $request->session()->get('pengguna', []);

        return view('admin.pesan-detail', ['pesan' => PesanLayanan::detail($id, $pengguna)]);
    }

    public function balasPesan(Request $request, int $id)
    {
        $data = $request->validate([
            'balasan' => ['required', 'string', 'min:2', 'max:3000'],
        ], ['balasan.min' => 'Balasan minimal 2 karakter.']);

        try {
            PesanLayanan::balas($id, $request->session()->get('pengguna', []), $data['balasan']);
        } catch (TransportExceptionInterface $exception) {
            return redirect()->route('admin.pesan.detail', $id)
                ->with('galat', 'Balasan sudah tersimpan, tetapi email belum berhasil dikirim. Coba kirim balasan lagi.');
        }

        return redirect()->route('admin.pesan.detail', $id)->with('pesan', 'Balasan berhasil dikirim ke email pengguna.');
    }

    private function usulanRelasi(): array
    {
        return Cache::get('medanvisit.usulan_relasi', []);
    }

    public function ringkasan(Request $request)
    {
        $periode = (int) $request->query('periode', 30);
        $periode = in_array($periode, [7, 30, 90], true) ? $periode : 30;
        $sejak = now('Asia/Jakarta')->subDays($periode)->toIso8601String();
        $ulasanTerbaru = collect(Penyimpanan::ulasan(null, 'tayang', aman: true));
        if ($milik = $this->slugsKelolaan($request)) {
            $ulasanTerbaru = $ulasanTerbaru->filter(fn ($ulasan) => in_array($ulasan['tempat'], $milik, true));
        }

        $lunas = collect(Penyimpanan::pesanan())->filter(fn ($p) => $p['status'] === 'lunas' && ($p['dibayar'] ?? '') >= $sejak);
        $tanpaSumber = collect(Katalog::relasi())->filter(fn ($r) => str_contains($r['sumber'], 'perlu verifikasi'));
        $tanpaFoto = collect(Katalog::tempat())->filter(fn ($t) => empty($t['foto']));

        return view('admin.ringkasan', [
            'periode' => $periode,
            'jumlahTempat' => count(Katalog::tempat()),
            'tiket' => $lunas->sum(fn ($p) => array_sum(array_column($p['item'], 'jumlah'))),
            'pendapatan' => $lunas->sum('total'),
            'ulasanTerbaru' => $ulasanTerbaru->values()->all(),
            'celah' => Penyimpanan::celahKonten(),
            'jumlahCari' => Penyimpanan::jumlahCari(),
            'tanpaSumber' => $tanpaSumber,
            'tanpaFoto' => $tanpaFoto,
            'usulan' => $this->usulanRelasi(),
        ]);
    }

    public function insight(Request $request)
    {
        $periode = (int) $request->query('periode', 30);
        $periode = in_array($periode, [7, 30, 90], true) ? $periode : 30;
        $kunjungan = Kunjungan::ringkasan($periode);
        $sejak = now('Asia/Jakarta')->subDays($periode)->toIso8601String();

        $admin = $request->session()->get('pengguna.peran') === 'admin';
        $tempatMilik = $this->slugsKelolaan($request) ?? [];
        $pilihan = $request->query('tempat');
        $fokus = $admin
            ? (in_array($pilihan, $tempatMilik, true) ? $pilihan : ($tempatMilik[0] ?? null))
            : (is_string($pilihan) && (Katalog::get($pilihan)['jenis'] ?? null) === 'tempat' ? $pilihan : null);

        $pesanan = collect(Penyimpanan::pesanan(aman: true))->filter(
            fn ($pesanan) => ($pesanan['waktu'] ?? '') >= $sejak
                && ($admin ? in_array($pesanan['tempat'], $tempatMilik, true) : ($fokus === null || $pesanan['tempat'] === $fokus))
        );
        $transaksi = $pesanan->groupBy('tempat')->map(fn ($baris) => [
            'pesanan' => $baris->count(),
            'lunas' => $baris->where('status', 'lunas')->count(),
            'tiket' => $baris->where('status', 'lunas')->sum(fn ($pesanan) => array_sum(array_column($pesanan['item'], 'jumlah'))),
            'pendapatan' => $baris->where('status', 'lunas')->sum('total'),
        ])->sortByDesc('pendapatan');

        // semua tempat wisata dengan jumlah kunjungan halamannya (termasuk yang belum dikunjungi)
        $kunjunganTempat = collect(Katalog::tempat())
            ->when($admin, fn ($tempat) => $tempat->filter(fn ($t) => in_array($t['slug'], $tempatMilik, true)))
            ->map(fn ($t) => ['slug' => $t['slug'], 'nama' => $t['nama'], 'jumlah' => (int) ($kunjungan['tempat'][$t['slug']] ?? 0)])
            ->sortBy([['jumlah', 'desc'], ['nama', 'asc']])->values();

        if ($fokus !== null) {
            $harian = Kunjungan::harianTempat($fokus, $periode);
            $kunjungan = ['harian' => $harian, 'rute' => [], 'tempat' => [$fokus => array_sum($harian)], 'total' => array_sum($harian)];
        }

        return view('admin.insight', [
            'periode' => $periode,
            'kunjungan' => $kunjungan,
            'fokus' => $fokus,
            'admin' => $admin,
            'akun' => $admin ? [] : collect((new SupabaseApi)->users())->countBy('peran')->all(),
            'kunjunganTempat' => $kunjunganTempat,
            'transaksi' => $transaksi,
            'pesanan' => $pesanan,
        ]);
    }

    public function akunAdmin(Request $request)
    {
        $api = new SupabaseApi;
        $semuaAkun = collect($api->users());
        $tempat = collect(Katalog::tempat())->keyBy('slug');
        $semuaRelasi = $api->adminTempat();
        $relasiTempat = collect($semuaRelasi)->groupBy('id_admin');
        $peran = $request->query('peran', 'admin');
        $peran = in_array($peran, ['admin', 'wisatawan'], true) ? $peran : 'admin';
        $akun = $semuaAkun->where('peran', $peran)->map(function (array $user) use ($relasiTempat, $tempat) {
            $user['tempat'] = ($relasiTempat->get($user['id'], collect()))
                ->map(fn ($relasi) => $tempat->get($relasi['tempat_slug']) ?? [
                    'slug' => $relasi['tempat_slug'],
                    'nama' => $relasi['tempat_slug'],
                ])->values();

            return $user;
        })->values();
        $tempatTerhubung = collect($semuaRelasi)->pluck('tempat_slug')->all();
        $tersedia = $tempat->reject(fn ($t) => in_array($t['slug'], $tempatTerhubung, true))->values();

        return view('admin.akun', [
            'akun' => $akun,
            'peran' => $peran,
            'tempatTersedia' => $tersedia,
            'jumlah' => $semuaAkun->countBy('peran')->all(),
        ]);
    }

    public function buatAkun(Request $request)
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email:rfc', 'max:120'],
            'tempat_slug' => ['required', 'string', 'max:120'],
            'password' => ['required', 'confirmed', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,}$/'],
        ], [
            'password.regex' => 'Password minimal 8 karakter dan harus memiliki huruf kecil, huruf kapital, angka, dan simbol.',
            'tempat_slug.required' => 'Setiap akun admin harus terhubung ke tempat wisata.',
        ]);
        $api = new SupabaseApi;
        $tempat = Katalog::get($data['tempat_slug']);
        if (! $tempat || $tempat['jenis'] !== 'tempat') {
            throw ValidationException::withMessages(['tempat_slug' => 'Pilih tempat wisata yang valid.']);
        }
        $relasiTempat = collect($api->adminTempat());
        if ($relasiTempat->contains('tempat_slug', $data['tempat_slug'])) {
            throw ValidationException::withMessages(['tempat_slug' => 'Tempat wisata tersebut sudah dikelola akun admin lain.']);
        }
        if ($api->findUserByEmail($data['email'])) {
            throw ValidationException::withMessages(['email' => 'Email sudah digunakan.']);
        }
        $waktu = now('Asia/Jakarta')->toDateTimeString();
        $user = $api->createUser([
            'name' => $data['nama'],
            'email' => strtolower($data['email']),
            'peran' => 'admin',
            'tempat_slug' => $data['tempat_slug'],
            'password' => Hash::make($data['password']),
            'email_verified_at' => $waktu,
            'created_at' => $waktu,
            'updated_at' => $waktu,
        ]);
        $api->assignTempatAdmin($user['id'], $data['tempat_slug']);

        return redirect()->route('admin.akun')->with('pesan', 'Akun admin '.$data['nama'].' berhasil dibuat dan terhubung ke '.$tempat['nama'].'.');
    }

    public function ubahAkun(Request $request, string $id)
    {
        $api = new SupabaseApi;
        $user = collect($api->users())->first(fn ($user) => (string) $user['id'] === $id);
        abort_unless($user && $user['peran'] === 'admin', 404);
        $data = $request->validate([
            'password' => ['required', 'confirmed', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,}$/'],
        ], ['password.regex' => 'Password minimal 8 karakter dan harus memiliki huruf kecil, huruf kapital, angka, dan simbol.']);
        $api->updateUser($id, [
            'password' => Hash::make($data['password']),
            'updated_at' => now('Asia/Jakarta')->toDateTimeString(),
        ]);

        return redirect()->route('admin.akun')->with('pesan', 'Kata sandi admin '.$user['email'].' berhasil diperbarui.');
    }

    public function hapusAkun(Request $request, string $id)
    {
        $api = new SupabaseApi;
        $user = collect($api->users())->first(fn ($user) => (string) $user['id'] === $id);
        abort_unless($user && $user['peran'] === 'admin', 404);

        $tempatKelolaan = $api->tempatAdmin($id);
        $api->deleteUser($id);

        return redirect()->route('admin.akun')->with(
            'pesan',
            'Akun admin '.$user['email'].' berhasil dihapus.'
                .($tempatKelolaan === [] ? '' : ' Tempat yang dilepas: '.implode(', ', $tempatKelolaan).'.')
        );
    }

    public function tautkanTempat(Request $request, string $id)
    {
        $api = new SupabaseApi;
        $user = collect($api->users())->first(fn ($user) => (string) $user['id'] === $id);
        abort_unless($user && $user['peran'] === 'admin', 404);
        $data = $request->validate(['tempat_slug' => ['required', 'string', 'max:120']]);
        $tempat = Katalog::get($data['tempat_slug']);
        if (! $tempat || $tempat['jenis'] !== 'tempat') {
            throw ValidationException::withMessages(['tempat_slug' => 'Pilih tempat wisata yang valid.']);
        }
        $relasiTempat = collect($api->adminTempat());
        if ($relasiTempat->contains(fn ($relasi) => $relasi['tempat_slug'] === $data['tempat_slug'] && (string) $relasi['id_admin'] !== $id)) {
            throw ValidationException::withMessages(['tempat_slug' => 'Tempat wisata tersebut sudah dikelola akun admin lain.']);
        }
        if (in_array($data['tempat_slug'], $api->tempatAdmin($id), true)) {
            throw ValidationException::withMessages(['tempat_slug' => 'Tempat wisata ini sudah ditautkan ke akun tersebut.']);
        }
        $api->assignTempatAdmin($id, $data['tempat_slug']);
        if (empty($user['tempat_slug'])) {
            $api->updateUser($id, ['tempat_slug' => $data['tempat_slug']]);
        }

        return redirect()->route('admin.akun')->with('pesan', $tempat['nama'].' ditautkan ke akun '.$user['email'].'.');
    }

    public function lepasTempat(Request $request, string $id, string $slug)
    {
        $api = new SupabaseApi;
        $user = collect($api->users())->first(fn ($user) => (string) $user['id'] === $id);
        abort_unless($user && $user['peran'] === 'admin', 404);
        $tempatAdmin = $api->tempatAdmin($id);
        abort_unless(in_array($slug, $tempatAdmin, true), 404);
        abort_if(count($tempatAdmin) <= 1, 422, 'Setiap akun admin harus tetap memiliki minimal satu tempat wisata.');

        $pengganti = collect($tempatAdmin)->first(fn ($tempat) => $tempat !== $slug);
        if (($user['tempat_slug'] ?? null) === $slug) {
            $api->updateUser($id, ['tempat_slug' => $pengganti]);
        }
        $api->hapusTempatAdmin($id, $slug);

        return redirect()->route('admin.akun')->with('pesan', 'Tempat wisata berhasil dilepas dari akun '.$user['email'].'.');
    }

    private function slugsKelolaan(Request $request): ?array
    {
        $pengguna = $request->session()->get('pengguna', []);
        if (($pengguna['peran'] ?? null) !== 'admin') {
            return null;
        }

        return $pengguna['tempat_slugs'] ?? (new SupabaseApi)->tempatAdmin($pengguna['id']);
    }

    private function pastikanBolehKelola(Request $request, string $slug): void
    {
        $milik = $this->slugsKelolaan($request);
        abort_if($milik !== null && ! in_array($slug, $milik, true), 403, 'Akun ini hanya boleh mengelola tempat yang ditautkan kepadanya.');
    }

    public function entitas(Request $request)
    {
        $jenis = $request->query('jenis', $request->route('jenis'));
        $q = mb_strtolower((string) $request->query('q', ''));
        $milik = $this->slugsKelolaan($request);
        if ($milik !== null) {
            $jenis = 'tempat';
        }
        $data = collect(Katalog::entitas())->reject(fn ($e) => $e['jenis'] === 'wilayah')
            ->filter(fn ($e) => (! $jenis || $e['jenis'] === $jenis) && ($q === '' || str_contains(mb_strtolower($e['nama']), $q)))
            ->sortBy('nama');

        if ($milik !== null) {
            $data = $data->filter(fn ($e) => in_array($e['slug'], $milik, true));
        }

        return view('admin.entitas', [
            'data' => $data,
            'jenis' => $jenis,
            'q' => $q,
            'jumlahRelasi' => collect(Katalog::relasi())->flatMap(fn ($r) => [$r[0], $r[2]])->countBy()->all(),
            'perJenis' => collect(Katalog::entitas())->reject(fn ($e) => $e['jenis'] === 'wilayah')->countBy('jenis')->all(),
        ]);
    }

    public function ubahEntitas(Request $request, string $slug)
    {
        $this->pastikanBolehKelola($request, $slug);
        $e = Katalog::get($slug);
        abort_unless($e && $e['jenis'] !== 'wilayah', 404);
        $kecamatan = Katalog::jenis('wilayah');
        $kecamatanTerpilih = collect($kecamatan)->first(fn ($wilayah) => $wilayah['nama'] === ($e['kecamatan'] ?? null))['slug'] ?? '';

        return view('admin.entitas-ubah', [
            'e' => $e,
            'tetangga' => Katalog::tetangga($slug, false),
            'kecamatan' => $kecamatan,
            'kecamatanTerpilih' => $kecamatanTerpilih,
        ]);
    }

    public function simpanEntitas(Request $request, string $slug)
    {
        $this->pastikanBolehKelola($request, $slug);
        $e = Katalog::get($slug);
        abort_unless($e, 404);
        $aturan = ['nama' => ['required', 'string', 'max:120'], 'ringkas' => ['required', 'string', 'max:240']];
        if ($e['jenis'] === 'tempat') {
            $aturan += [
                'lat' => ['required', 'numeric', 'between:3.4,3.8'], 'lng' => ['required', 'numeric', 'between:98.5,98.8'],
                'alamat' => ['required', 'string', 'max:160'], 'jam' => ['required', 'string', 'max:80'],
                'sumber' => ['required', 'string', 'min:3', 'max:240'],
                'kategori' => ['required', 'in:'.implode(',', array_keys(Katalog::KATEGORI))],
                'kecamatan' => ['nullable', 'string', 'max:100'],
                'foto' => ['nullable', 'string', 'max:160', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
                'harga' => ['nullable', 'array'], 'harga.*' => ['nullable', 'integer', 'min:0'],
            ];
        } else {
            $aturan['status'] = ['required', 'in:terbit,draf'];
        }
        $data = $request->validate($aturan, [
            'lat.between' => 'Lintang harus di wilayah Kota Medan (3,4 sampai 3,8).',
            'lng.between' => 'Bujur harus di wilayah Kota Medan (98,5 sampai 98,8).',
        ]);
        if ($e['jenis'] === 'tempat') {
            if (! empty($data['kecamatan'])) {
                $wilayah = Katalog::get($data['kecamatan']);
                if (! $wilayah || $wilayah['jenis'] !== 'wilayah') {
                    throw ValidationException::withMessages(['kecamatan' => 'Kecamatan tidak ditemukan di Database 3.']);
                }
            }
            $data['foto'] = (string) ($data['foto'] ?? '');
            $data['kecamatan'] = (string) ($data['kecamatan'] ?? '');
            Database3CatalogWriter::update($slug, $data, $data['harga'] ?? []);

            return back()->with('pesan', 'Perubahan '.$data['nama'].' berhasil disimpan ke Database 3.');
        }

        Cache::forever('medanvisit.draf_entitas.'.$slug, $data + ['waktu' => now('Asia/Jakarta')->toIso8601String()]);

        return back()->with('pesan', 'Perubahan '.$data['nama'].' tersimpan sebagai draf.');
    }

    public function buatTempat()
    {
        return view('admin.tempat-baru', ['kecamatan' => Katalog::jenis('wilayah')]);
    }

    public function simpanTempat(Request $request)
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:120'],
            'ringkas' => ['required', 'string', 'max:240'],
            'kategori' => ['required', 'in:'.implode(',', array_keys(Katalog::KATEGORI))],
            'kecamatan' => ['nullable', 'string', 'max:100'],
            'lat' => ['required', 'numeric', 'between:3.4,3.8'],
            'lng' => ['required', 'numeric', 'between:98.5,98.8'],
            'alamat' => ['required', 'string', 'max:160'],
            'jam' => ['required', 'string', 'max:80'],
            'sumber' => ['required', 'string', 'min:3', 'max:240'],
            'foto' => ['nullable', 'string', 'max:160', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'harga' => ['nullable', 'integer', 'min:0'],
        ], [
            'lat.between' => 'Lintang harus di wilayah Kota Medan (3,4 sampai 3,8).',
            'lng.between' => 'Bujur harus di wilayah Kota Medan (98,5 sampai 98,8).',
        ]);
        if (! empty($data['kecamatan'])) {
            $wilayah = Katalog::get($data['kecamatan']);
            if (! $wilayah || $wilayah['jenis'] !== 'wilayah') {
                throw ValidationException::withMessages(['kecamatan' => 'Kecamatan tidak ditemukan di Database 3.']);
            }
        }

        $slug = Str::slug($data['nama']);
        if ($slug === '' || Katalog::get($slug)) {
            throw ValidationException::withMessages(['nama' => 'Nama tempat sudah digunakan atau tidak dapat dijadikan alamat.']);
        }
        $harga = isset($data['harga']) && $data['harga'] !== '' ? ['Reguler' => (int) $data['harga']] : [];
        $data['foto'] = (string) ($data['foto'] ?? '');
        $data['kecamatan'] = (string) ($data['kecamatan'] ?? '');
        Database3CatalogWriter::create($slug, $data, $harga);
        $pengguna = $request->session()->get('pengguna', []);
        if (($pengguna['peran'] ?? null) === 'admin') {
            $api = new SupabaseApi;
            $api->assignTempatAdmin($pengguna['id'], $slug);
            if (empty($pengguna['tempat_slug'])) {
                $api->updateUser($pengguna['id'], ['tempat_slug' => $slug]);
            }
        }

        return redirect()->route('admin.entitas.ubah', $slug)->with('pesan', 'Tempat wisata berhasil dibuat dan ditautkan ke akun pengelola.');
    }

    public function hapusTempat(Request $request, string $slug)
    {
        $tempat = Katalog::get($slug);
        abort_unless($tempat && $tempat['jenis'] === 'tempat', 404);
        $this->pastikanBolehKelola($request, $slug);
        $api = new SupabaseApi;
        $pemilik = collect($api->adminTempat())->first(fn ($relasi) => $relasi['tempat_slug'] === $slug);
        $tempatPemilik = $pemilik ? $api->tempatAdmin($pemilik['id_admin']) : [];
        abort_if(
            $pemilik && count($tempatPemilik) <= 1,
            422,
            'Akun admin harus tetap memiliki minimal satu tempat wisata. Tautkan tempat lain sebelum menghapus yang ini.'
        );

        Database3CatalogWriter::delete($slug);
        if ($pemilik) {
            $user = collect($api->users())->first(fn ($user) => (string) $user['id'] === (string) $pemilik['id_admin']);
            if (($user['tempat_slug'] ?? null) === $slug) {
                $api->updateUser($pemilik['id_admin'], [
                    'tempat_slug' => collect($tempatPemilik)->first(fn ($tempat) => $tempat !== $slug),
                ]);
            }
            $api->hapusTempatAdmin($pemilik['id_admin'], $slug);
        }

        return redirect()->route('admin.entitas', ['jenis' => 'tempat'])->with('pesan', 'Tempat wisata '.$tempat['nama'].' berhasil dihapus dari Database 3.');
    }

    public function relasi(Request $request)
    {
        $predikat = $request->query('predikat');

        return view('admin.relasi', [
            'relasi' => collect(Katalog::relasi())->reject(fn ($r) => $r[1] === 'beradaDi')->filter(fn ($r) => ! $predikat || $r[1] === $predikat)->values(),
            'predikat' => $predikat,
            'pilihan' => collect(Katalog::entitas())->reject(fn ($e) => $e['jenis'] === 'wilayah')->sortBy('nama'),
            'usulan' => $this->usulanRelasi(),
        ]);
    }

    public function tambahRelasi(Request $request)
    {
        $data = $request->validate([
            'subjek' => ['required', 'string'], 'predikat' => ['required', 'in:'.implode(',', array_keys(Katalog::LABEL_RELASI))],
            'objek' => ['required', 'string', 'different:subjek'], 'sumber' => ['required', 'string', 'min:8', 'max:240'],
        ], ['objek.different' => 'Subjek dan objek harus berbeda.', 'sumber.required' => 'Relasi tanpa sumber tidak boleh tayang. Isi tautan atau judul sumbernya.']);
        abort_unless(Katalog::get($data['subjek']) && Katalog::get($data['objek']), 422);
        $usulan = $this->usulanRelasi();
        $usulan[] = $data + ['waktu' => now('Asia/Jakarta')->toIso8601String(), 'oleh' => $request->session()->get('pengguna.nama')];
        Cache::forever('medanvisit.usulan_relasi', $usulan);

        return back()->with('pesan', 'Usulan relasi tersimpan: '.Katalog::get($data['subjek'])['nama'].' '.Katalog::LABEL_RELASI[$data['predikat']].' '.Katalog::get($data['objek'])['nama'].'.');
    }

    public function ulasan(Request $request)
    {
        $ulasan = collect(Penyimpanan::ulasan(status: 'tayang'));
        $milik = $this->slugsKelolaan($request);
        if ($milik !== null) {
            $ulasan = $ulasan->filter(fn ($baris) => in_array($baris['tempat'], $milik, true));
        }

        return view('admin.ulasan', [
            'jumlah' => $ulasan->count(),
            'ulasan' => $ulasan->values(),
        ]);
    }

    public function hapusUlasan(Request $request, string $id)
    {
        $ulasan = collect(Penyimpanan::ulasan())->first(fn ($ulasan) => $ulasan['id'] === $id);
        abort_unless($ulasan, 404);
        $this->pastikanBolehKelola($request, $ulasan['tempat']);
        Penyimpanan::hapusUlasan($id);

        return back()->with('pesan', 'Ulasan berhasil dihapus.');
    }

    public function tiket(Request $request)
    {
        return view('admin.tiket', [
            'pesanan' => collect(Penyimpanan::pesanan())->values(),
            'bertiket' => Katalog::bertiket(),
        ]);
    }

    public function validasi(Request $request)
    {
        $data = $request->validate(['kode' => ['required', 'string', 'max:20']], ['kode.required' => 'Pindai atau ketik kode pada e-tiket.']);
        $p = Penyimpanan::cariPesanan(strtoupper(trim($data['kode'])));
        $hasil = match (true) {
            ! $p => ['status' => 'tidak', 'judul' => 'Tidak ditemukan', 'teks' => 'Tidak ada pesanan dengan kode '.strtoupper($data['kode']).'.'],
            $p['status'] !== 'lunas' => ['status' => 'tidak', 'judul' => 'Belum dibayar', 'teks' => 'Pesanan '.$p['kode'].' berstatus '.$p['status'].'.'],
            ! empty($p['dipakai']) => ['status' => 'dipakai', 'judul' => 'Sudah dipakai', 'teks' => 'Tiket ini dipakai '.substr($p['dipakai'], 11, 5).'.'],
            $p['tanggal'] !== now('Asia/Jakarta')->toDateString() => ['status' => 'tidak', 'judul' => 'Bukan untuk hari ini', 'teks' => 'Tiket berlaku tanggal '.$p['tanggal'].'.'],
            default => ['status' => 'valid', 'judul' => 'Valid', 'teks' => collect($p['item'])->map(fn ($i) => $i['jumlah'].' '.$i['jenis'])->implode(', ').' atas nama '.$p['pemesan']['nama'].'.'],
        };
        if ($hasil['status'] === 'valid') {
            Penyimpanan::ubahPesanan($p['kode'], ['dipakai' => now('Asia/Jakarta')->toIso8601String()]);
        }

        return back()->with('hasil', $hasil);
    }

    public function sinkronisasi(Request $request)
    {
        $hasil = null;
        if ($request->boolean('periksa')) {
            $hasil = [];
            foreach ([
                'katalog' => ['users', 'Database 1 PostgreSQL Supabase - Akun dan Konfigurasi Website'],
                'transaksi' => ['pesanan', 'Database 2 PostgreSQL Supabase - Transaksi'],
            ] as $koneksi => [$tabel, $label]) {
                $mulai = microtime(true);
                try {
                    (new SupabaseApi($koneksi))->ping($tabel);
                    $hasil[] = ['label' => $label, 'ok' => true, 'teks' => 'Tersambung dalam '.round((microtime(true) - $mulai) * 1000).' ms.'];
                } catch (\Throwable $e) {
                    $pesan = preg_replace('/password=[^\s]+/i', 'password=***', $e->getMessage());
                    $hasil[] = ['label' => $label, 'ok' => false, 'teks' => str($pesan)->before(' (Connection:')->limit(180)->toString()];
                }
            }
            $endpoint = config('services.sparql.endpoint');
            $labelDatabase3 = 'Database 3 SPARQL Allegrograph - Tempat Wisata';
            if (! $endpoint) {
                $hasil[] = ['label' => $labelDatabase3, 'ok' => false, 'teks' => 'SPARQL_ENDPOINT belum diisi di .env.'];
            } else {
                try {
                    $http = Http::timeout((int) config('services.sparql.timeout', 30));
                    $username = config('services.sparql.username');
                    $password = config('services.sparql.password');
                    if ($username !== null && $password !== null) {
                        $http = $http->withBasicAuth($username, $password);
                    }
                    $res = $http->asForm()->post($endpoint, ['query' => 'ASK { ?s ?p ?o }']);
                    $hasil[] = ['label' => $labelDatabase3, 'ok' => $res->successful(), 'teks' => $res->successful() ? 'Endpoint menjawab.' : 'Endpoint menjawab dengan status '.$res->status().'.'];
                } catch (\Throwable $e) {
                    $hasil[] = ['label' => $labelDatabase3, 'ok' => false, 'teks' => 'Tidak bisa dihubungi: '.str($e->getMessage())->limit(120)];
                }
            }
        }

        return view('admin.sinkronisasi', ['hasil' => $hasil, 'diperiksa' => $hasil ? now('Asia/Jakarta') : null]);
    }
}
