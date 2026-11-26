<?php

namespace App\Http\Controllers;

use App\Support\Katalog;
use App\Support\Penyimpanan;
use App\Support\SupabaseApi;
use App\Mail\VerifikasiEmail;
use App\Mail\ResetPasswordEmail;
use App\Mail\TindakanAkunEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AkunController extends Controller
{
    private const JUMLAH_MAKS_KIRIM_ULANG = 3;
    private const JEDA_KIRIM_ULANG = 60;
    private const BLOKIR_KIRIM_ULANG = 3600;
    private const MASA_BERLAKU_OTP_TINDAKAN = 600;
    private const MAKS_PERCOBAAN_OTP_TINDAKAN = 5;

    private function googleRedirectUri(): string
    {
    return 'https://medanvisit.neoverse.my.id/masuk/google/callback';
    }

    private function pengguna(Request $r): array
    {
        return $r->session()->get('pengguna', []);
    }

    private function aman(?string $kembali): ?string
    {
        return $kembali && str_starts_with($kembali, '/') && ! str_starts_with($kembali, '//') ? $kembali : null;
    }

    public function masuk(Request $request)
    {
        return view('akun.masuk', [
            'kembali' => $this->aman($request->query('kembali')),
            'tempatSorotan' => $this->tempatSorotan(),
        ]);
    }

    public function prosesMasuk(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc'],
            'password' => ['required'],
        ], [
            'email.required' => 'Isi alamat email.',
            'email.email' => 'Alamat email belum benar.',
            'password.required' => 'Isi kata sandi.',
        ]);

        $user = (new SupabaseApi)->findUserByEmail($data['email']);
        if (! $user || ! Hash::check($data['password'], $user['password'])) {
            return back()->withErrors(['email' => 'Email atau kata sandi tidak cocok.'])->withInput($request->only('email'));
        }
        if (empty($user['email_verified_at'])) {
            $this->kirimVerifikasi($user);
            return redirect()->route('verifikasi', ['email' => $user['email']])
                ->with('pesan', 'Email belum dikonfirmasi. Kami mengirimkan link verifikasi baru.');
        }
        $user = $this->pulihkanAkunNonaktif($user);
        if ($user === false) {
            return back()->withErrors(['email' => 'Masa pemulihan akun telah berakhir dan akun sudah dihapus permanen.'])
                ->withInput($request->only('email'));
        }
        if (! empty($user['ditangguhkan_pada'])) {
            return back()->withErrors(['email' => 'Akun ini ditangguhkan. Hubungi admin melalui halaman Hubungi Admin.'])
                ->withInput($request->only('email'));
        }

        $request->session()->regenerate();
        $this->simpanSesiPengguna($request, $user);

        return redirect($this->aman($request->input('kembali')) ?? $this->tujuan($user['peran']))
            ->with('sambutan', $user['name']);
    }

    public function lupaPassword()
    {
        return view('akun.lupa-password');
    }

    public function kirimLinkPassword(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $api = new SupabaseApi;
        $user = $api->findUserByEmail($data['email']);

        if ($user && empty($user['google_id'])) {
            $token = Str::random(64);
            $api->createPasswordResetToken([
                'email' => strtolower($user['email']),
                'token' => hash('sha256', $token),
                'created_at' => now()->toDateTimeString(),
            ]);
            Mail::to($user['email'])->send(new ResetPasswordEmail(
                route('password.reset', ['token' => $token, 'email' => $user['email']])
            ));
        }

        return redirect()->route('password.request')->with(
            'pesan',
            'Jika email tersebut terdaftar sebagai akun manual, tautan reset password sudah dikirim.'
        );
    }

    public function formulirResetPassword(Request $request, string $token)
    {
        $email = $request->query('email');
        abort_unless(is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL), 404);

        $record = (new SupabaseApi)->findPasswordResetToken(hash('sha256', $token));
        abort_unless(
            $record
                && strcasecmp($record['email'], $email) === 0
                && now()->lessThan(\Carbon\Carbon::parse($record['created_at'])->addMinutes(60)),
            410,
            'Tautan reset password sudah kedaluwarsa.'
        );

        return view('akun.reset-password', ['token' => $token, 'email' => $email]);
    }

    public function ubahPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,}$/'],
        ], [
            'password.regex' => 'Password minimal 8 karakter dan harus memiliki huruf kecil, huruf kapital, angka, dan simbol.',
            'password.confirmed' => 'Ulangi kata sandi yang sama.',
        ]);

        $api = new SupabaseApi;
        $record = $api->findPasswordResetToken(hash('sha256', $data['token']));
        abort_unless(
            $record
                && strcasecmp($record['email'], $data['email']) === 0
                && now()->lessThan(\Carbon\Carbon::parse($record['created_at'])->addMinutes(60)),
            410,
            'Tautan reset password sudah kedaluwarsa.'
        );

        $user = $api->findUserByEmail($data['email']);
        abort_unless($user && empty($user['google_id']), 404, 'Akun manual tidak ditemukan.');
        $api->updateUser($user['id'], ['password' => Hash::make($data['password'])]);
        $api->deletePasswordResetToken($user['email']);

        return redirect()->route('masuk')->with('pesan', 'Password berhasil diubah. Silakan masuk.');
    }

    private function tujuan(string $peran): string
    {
        return match ($peran) {
            'admin', 'superadmin' => route('admin.insight'),
            default => route('beranda'),
        };
    }

    private function simpanSesiPengguna(Request $request, array $user): void
    {
        $request->session()->put('pengguna', [
            'id' => $user['id'] ?? null,
            'email' => $user['email'],
            'nama' => $user['name'],
            'peran' => $user['peran'],
            'no_hp' => $user['no_hp'] ?? null,
            'foto_profil' => $user['foto_profil'] ?? null,
        ]);
    }

    private function pulihkanAkunNonaktif(array $user): array|false
    {
        if (empty($user['dinonaktifkan_pada'])) {
            return $user;
        }

        $batasWaktu = ! empty($user['hapus_otomatis_pada'])
            ? \Carbon\Carbon::parse($user['hapus_otomatis_pada'])
            : \Carbon\Carbon::parse($user['dinonaktifkan_pada'])->addMonthsNoOverflow(3);

        $api = new SupabaseApi;
        if (now()->greaterThanOrEqualTo($batasWaktu)) {
            $api->deleteUser($user['id']);

            return false;
        }

        return $api->updateUser($user['id'], [
            'dinonaktifkan_pada' => null,
            'hapus_otomatis_pada' => null,
        ]);
    }

    public function daftar(Request $request)
    {
        return view('akun.daftar', [
            'kembali' => $this->aman($request->query('kembali')),
            'tempatSorotan' => $this->tempatSorotan(),
        ]);
    }

    private function tempatSorotan(): array
    {
        $tempat = Katalog::tempat();
        $berfoto = array_filter($tempat, fn ($item) => ! empty($item['foto']));

        return array_slice(array_values($berfoto ?: $tempat), 0, 3);
    }

    public function prosesDaftar(Request $request)
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email:rfc'],
            'no_hp' => ['required', 'digits_between:10,15', 'regex:/^08[0-9]+$/'],
            'tanggal_lahir' => ['required', 'date', 'before:today'],
            'password' => ['required', 'confirmed', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,}$/'],
            'setuju' => ['accepted'],
        ], [
            'nama.required' => 'Isi nama yang akan tampil di akun.',
            'email.required' => 'Isi alamat email.',
            'email.email' => 'Alamat email belum benar.',
            'no_hp.required' => 'Isi nomor HP.',
            'no_hp.digits_between' => 'Nomor HP harus terdiri dari 10 sampai 15 angka.',
            'no_hp.regex' => 'Nomor HP harus berupa angka dan diawali 08.',
            'tanggal_lahir.required' => 'Isi tanggal lahir.',
            'password.regex' => 'Password minimal 8 karakter dan harus memiliki huruf kecil, huruf kapital, angka, dan simbol.',
            'password.confirmed' => 'Ulangi kata sandi yang sama.',
            'setuju.accepted' => 'Setujui ketentuan untuk membuat akun.',
        ]);

        $api = new SupabaseApi;
        if ($api->findUserByEmail($data['email'])) {
            return back()->withErrors(['email' => 'Email tersebut sudah terdaftar.'])->withInput();
        }
        $user = $api->createUser([
            'name' => $data['nama'],
            'email' => strtolower($data['email']),
            'no_hp' => $data['no_hp'] ?? null,
            'tanggal_lahir' => $data['tanggal_lahir'],
            'password' => Hash::make($data['password']),
            'peran' => 'wisatawan',
            'email_verified_at' => null,
        ]);
        $request->session()->forget('pengguna');
        $this->kirimVerifikasi($user);

        return redirect()->route('verifikasi', ['email' => $user['email']])
            ->with('pesan', 'Akun dibuat. Periksa email untuk mengonfirmasi akun.');
    }

    public function keluar(Request $request)
    {
        $request->session()->forget('pengguna');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('beranda');
    }

    public function verifikasi(Request $request)
    {
        $email = $request->query('email');
        $cooldownVerifikasi = is_string($email) ? $this->cooldownVerifikasi($request, $email) : 0;

        return view('akun.verifikasi', [
            'email' => $email,
            'pesan' => 'Periksa kotak masuk emailmu dan klik link konfirmasi untuk mengaktifkan akun. Periksa juga folder spam.',
            'tempatSorotan' => $this->tempatSorotan(),
            'cooldownVerifikasi' => $cooldownVerifikasi,
            'labelCooldownVerifikasi' => $this->labelCooldownVerifikasi($cooldownVerifikasi),
        ]);
    }

    public function konfirmasiEmail(Request $request, string $token)
    {
        $record = (new SupabaseApi)->findVerificationToken(hash('sha256', $token));
        abort_unless($record && now()->lessThan(\Carbon\Carbon::parse($record['expires_at'])), 410, 'Link verifikasi sudah kedaluwarsa.');

        $api = new SupabaseApi;
        $user = $api->findUserByEmail($record['email']);
        abort_unless($user, 404, 'Akun tidak ditemukan.');
        $user = $api->updateUser($user['id'], ['email_verified_at' => now()->toDateTimeString()]);
        $api->deleteVerificationToken($record['id']);
        $request->session()->regenerate();
        $this->simpanSesiPengguna($request, $user);

        return redirect()->route('beranda')->with('sambutan', $user['name']);
    }

    public function kirimUlangVerifikasi(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $kunci = fn (string $jenis): string => $this->kunciRateLimitVerifikasi($request, $data['email'], $jenis);
        $kunciBlokir = $kunci('terkunci');

        if (RateLimiter::tooManyAttempts($kunciBlokir, 1)) {
            $sisa = RateLimiter::availableIn($kunciBlokir);

            return redirect()->route('verifikasi', ['email' => $data['email']])
                ->with('pesan', "Batas percobaan tercapai. Coba lagi dalam {$sisa} detik.");
        }

        $kunciPercobaan = $kunci('percobaan');
        if (RateLimiter::tooManyAttempts($kunciPercobaan, self::JUMLAH_MAKS_KIRIM_ULANG)) {
            RateLimiter::hit($kunciBlokir, self::BLOKIR_KIRIM_ULANG);

            return redirect()->route('verifikasi', ['email' => $data['email']])
                ->with('pesan', 'Batas 3 percobaan tercapai. Tombol akan aktif kembali dalam 1 jam.');
        }

        $kunciJeda = $kunci('jeda');
        if (RateLimiter::tooManyAttempts($kunciJeda, 1)) {
            $sisa = RateLimiter::availableIn($kunciJeda);

            return redirect()->route('verifikasi', ['email' => $data['email']])
                ->with('pesan', "Tunggu {$sisa} detik sebelum meminta link baru lagi.");
        }

        RateLimiter::hit($kunciPercobaan, self::BLOKIR_KIRIM_ULANG);
        RateLimiter::hit($kunciJeda, self::JEDA_KIRIM_ULANG);

        $user = (new SupabaseApi)->findUserByEmail($data['email']);
        if ($user && empty($user['email_verified_at'])) {
            $this->kirimVerifikasi($user);

            return redirect()->route('verifikasi', ['email' => $data['email']])
                ->with('pesan_sukses', 'Email berhasil dikirim ulang.');
        }

        return redirect()->route('verifikasi', ['email' => $data['email']]);
    }

    private function kunciRateLimitVerifikasi(Request $request, string $email, string $jenis): string
    {
        return 'verifikasi-email.'.$jenis.'.'.hash('sha256', strtolower(trim($email)).'|'.$request->ip());
    }

    private function cooldownVerifikasi(Request $request, string $email): int
    {
        foreach (['terkunci', 'jeda'] as $jenis) {
            $kunci = $this->kunciRateLimitVerifikasi($request, $email, $jenis);
            if (RateLimiter::tooManyAttempts($kunci, 1)) {
                return RateLimiter::availableIn($kunci);
            }
        }

        return 0;
    }

    private function labelCooldownVerifikasi(int $detik): string
    {
        if ($detik >= 3600) {
            return (int) ceil($detik / 3600).' jam';
        }
        if ($detik >= 60) {
            return (int) ceil($detik / 60).' menit';
        }

        return $detik.' detik';
    }

    private function kirimVerifikasi(array $user): void
    {
        $token = Str::random(64);
        (new SupabaseApi)->createVerificationToken([
            'email' => $user['email'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours(24)->toDateTimeString(),
        ]);
        Mail::to($user['email'])->send(new VerifikasiEmail(route('verifikasi.konfirmasi', ['token' => $token])));
    }

    public function google(Request $request)
{
    $config = config('services.google');

    abort_unless(
        !empty($config['client_id']) &&
        !empty($config['client_secret']),
        503,
        'Login Google belum dikonfigurasi.'
    );

    $state = Str::random(40);

    $request->session()->put('google_oauth_state', $state);
    $request->session()->put(
        'google_oauth_kembali',
        $this->aman($request->query('kembali'))
    );

    $query = http_build_query([
        'client_id' => $config['client_id'],
        'redirect_uri' => $this->googleRedirectUri(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'access_type' => 'online',
        'prompt' => 'select_account',
    ]);

    return redirect(
        'https://accounts.google.com/o/oauth2/v2/auth?' . $query
    );
}

    public function googleCallback(Request $request)
{
    if ($request->filled('error')) {
        return redirect()->route('masuk')
            ->withErrors(['email' => 'Login Google dibatalkan.']);
    }

    $expectedState = $request->session()->pull('google_oauth_state');
    $kembali = $request->session()->pull('google_oauth_kembali');

    abort_unless(
        $request->filled('code') &&
        $request->filled('state') &&
        is_string($expectedState) &&
        hash_equals($expectedState, $request->input('state')),
        419,
        'Sesi login Google tidak valid. Silakan coba lagi.'
    );

    $config = config('services.google');

    try {
        $response = Http::asForm()
            ->acceptJson()
            ->timeout(15)
            ->post('https://oauth2.googleapis.com/token', [
                'code' => $request->input('code'),
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'redirect_uri' => $this->googleRedirectUri(),
                'grant_type' => 'authorization_code',
            ]);

        if (!$response->successful()) {
            report(new \RuntimeException(
                'Google OAuth gagal: HTTP ' . $response->status() .
                ', ' . $response->json('error', 'unknown_error')
            ));

            return redirect()->route('masuk')
                ->withErrors([
                    'email' => 'Login Google gagal. Silakan mulai proses login dari awal.'
                ]);
        }

        $token = $response->json();

        if (empty($token['access_token'])) {
            throw new \RuntimeException(
                'Google tidak mengembalikan access token.'
            );
        }

        $profile = Http::withToken($token['access_token'])
            ->acceptJson()
            ->timeout(15)
            ->get('https://openidconnect.googleapis.com/v1/userinfo')
            ->throw()
            ->json();

    } catch (\Illuminate\Http\Client\ConnectionException $e) {
        report($e);

        return redirect()->route('masuk')
            ->withErrors([
                'email' => 'Tidak dapat terhubung ke Google. Coba lagi.'
            ]);
    } catch (\Throwable $e) {
        report($e);

        return redirect()->route('masuk')
            ->withErrors([
                'email' => 'Terjadi kesalahan saat memproses login Google.'
            ]);
    }

    abort_unless(
        ($profile['email_verified'] ?? false) &&
        !empty($profile['email']) &&
        !empty($profile['sub']),
        422,
        'Akun Google belum terverifikasi.'
    );

    $api = new SupabaseApi;
    $user = $api->findUserByEmail($profile['email']);

    if (!$user) {
        $user = $api->createUser([
            'name' => $profile['name'] ?? Str::before($profile['email'], '@'),
            'email' => strtolower($profile['email']),
            'google_id' => $profile['sub'],
            'email_verified_at' => null,
            'peran' => 'wisatawan',
            'password' => Hash::make(Str::random(40)),
        ]);
    } elseif (($user['google_id'] ?? null) !== $profile['sub']) {
        $user = $api->updateUser($user['id'], [
            'google_id' => $profile['sub'],
        ]);
    }

    if (empty($user['email_verified_at'])) {
        $this->kirimVerifikasi($user);

        return redirect()->route('verifikasi', [
            'email' => $user['email']
        ])->with(
            'pesan',
            'Periksa email untuk mengonfirmasi akun Google sebelum masuk.'
        );
    }

    $user = $this->pulihkanAkunNonaktif($user);
    if ($user === false) {
        return redirect()->route('masuk')
            ->withErrors(['email' => 'Masa pemulihan akun telah berakhir dan akun sudah dihapus permanen.']);
    }

    if (!empty($user['ditangguhkan_pada'])) {
        return redirect()->route('masuk')
            ->withErrors([
                'email' => 'Akun ini ditangguhkan. Hubungi admin melalui halaman Hubungi Admin.'
            ]);
    }

    $request->session()->regenerate();
    $this->simpanSesiPengguna($request, $user);

    return redirect(
        $kembali ?? $this->tujuan($user['peran'])
    )->with('sambutan', $user['name']);
}
    public function pengaturan(Request $request)
    {
        $u = $this->pengguna($request);
        $user = (new SupabaseApi)->findUserByEmail($u['email']);
        abort_unless($user, 404, 'Akun tidak ditemukan.');

        $wisatawan = ($user['peran'] ?? null) === 'wisatawan';

        return view('akun.pengaturan', [
            'user' => $user,
            'akunGoogle' => ! empty($user['google_id']),
            'jumlah' => $wisatawan ? [
                'pesanan' => count(Penyimpanan::pesanan((int) $user['id'], aman: true)),
                'ulasan' => count(Penyimpanan::ulasan(idPengguna: (int) $user['id'], aman: true)),
                'rencana' => count(Penyimpanan::rencana((int) $user['id'], aman: true)),
            ] : [],
        ]);
    }

    public function simpanPengaturan(Request $request)
    {
        $u = $this->pengguna($request);
        $api = new SupabaseApi;
        $user = $api->findUserByEmail($u['email']);
        abort_unless($user, 404, 'Akun tidak ditemukan.');
        $akunGoogle = ! empty($user['google_id']);
        $aturan = [
            'nama' => ['required', 'string', 'max:60'],
            'tanggal_lahir' => ['required', 'date', 'before:today'],
            'no_hp' => ['required', 'digits_between:10,15', 'regex:/^08[0-9]+$/'],
            'foto_profil' => ['nullable', 'image', 'max:2048'],
        ];
        if (! $akunGoogle) {
            $aturan['password'] = ['nullable', 'confirmed', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,}$/'];
        }
        $data = $request->validate($aturan, [
            'nama.required' => 'Isi nama.',
            'tanggal_lahir.required' => 'Isi tanggal lahir.',
            'no_hp.required' => 'Isi nomor HP.',
            'no_hp.digits_between' => 'Nomor HP harus terdiri dari 10 sampai 15 angka.',
            'no_hp.regex' => 'Nomor HP harus berupa angka dan diawali 08.',
            'foto_profil.image' => 'Foto profil harus berupa gambar.',
            'foto_profil.max' => 'Foto profil maksimal 2 MB.',
            'password.regex' => 'Password minimal 8 karakter dan harus memiliki huruf kecil, huruf kapital, angka, dan simbol.',
            'password.confirmed' => 'Ulangi kata sandi yang sama.',
        ]);

        $attributes = [
            'name' => $data['nama'],
            'tanggal_lahir' => $data['tanggal_lahir'],
            'no_hp' => $data['no_hp'],
        ];
        if (! empty($data['password'])) {
            $attributes['password'] = Hash::make($data['password']);
        }
        $fotoBaru = null;
        if ($request->hasFile('foto_profil')) {
            $direktori = public_path('storage/profil_picture');
            File::ensureDirectoryExists($direktori);

            $namaFile = Str::slug($data['nama']) ?: 'pengguna';
            $idAkun = Str::slug((string) ($user['id'] ?? '')) ?: substr(hash('sha256', strtolower($user['email'])), 0, 12);
            $namaFile .= '-'.$idAkun.'-'.Str::random(8).'.'.$request->file('foto_profil')->extension();
            $request->file('foto_profil')->move($direktori, $namaFile);
            $fotoBaru = 'profil_picture/'.$namaFile;
            $attributes['foto_profil'] = $fotoBaru;
        }

        try {
            $updated = $api->updateUser($user['id'], $attributes);
        } catch (\Illuminate\Http\Client\RequestException|\Illuminate\Http\Client\ConnectionException|\RuntimeException $exception) {
            if ($fotoBaru !== null) {
                File::delete(public_path('storage/'.$fotoBaru));
            }

            report($exception);

            return redirect()->route('akun.pengaturan')
                ->withInput($request->except(['password', 'password_confirmation']))
                ->with('galat', 'Perubahan akun gagal disimpan. Coba lagi beberapa saat lagi.');
        } catch (\Throwable $exception) {
            if ($fotoBaru !== null) {
                File::delete(public_path('storage/'.$fotoBaru));
            }

            throw $exception;
        }

        if ($fotoBaru !== null && ! empty($user['foto_profil']) && $user['foto_profil'] !== $fotoBaru) {
            $fotoLama = basename($user['foto_profil']);
            if (str_starts_with($user['foto_profil'], 'profil_picture/')) {
                File::delete(public_path('storage/profil_picture/'.$fotoLama));
            } else {
                Storage::disk('public')->delete($user['foto_profil']);
            }
        }
        $this->simpanSesiPengguna($request, $updated);

        return redirect()->route('akun.pengaturan')->with('pesan', 'Pengaturan akun berhasil disimpan.');
    }

    public function akun(Request $request)
    {
        $u = $this->pengguna($request);

        return view('akun.akun', [
            'u' => $u,
            'tindakanAkunPending' => $request->session()->get('tindakan_akun_pending'),
            'pesanan' => count(Penyimpanan::pesanan((int) $u['id'], aman: true)),
            'rencana' => count(Penyimpanan::rencana((int) $u['id'], aman: true)),
            'ulasan' => count(Penyimpanan::ulasan(idPengguna: (int) $u['id'], aman: true)),
        ]);
    }

    public function kirimKodeTindakanAkun(Request $request)
    {
        $data = $request->validate([
            'tindakan' => ['required', Rule::in(['nonaktifkan', 'hapus'])],
        ]);

        $u = $this->pengguna($request);
        $api = new SupabaseApi;
        $user = $api->findUserByEmail($u['email']);
        abort_unless($user && (string) $user['id'] === (string) $u['id'], 404, 'Akun tidak ditemukan.');
        abort_if(! empty($user['dinonaktifkan_pada']), 409, 'Akun ini sudah dinonaktifkan.');

        $kunci = hash('sha256', $user['id'].'|'.$request->ip());
        $kunciJeda = 'tindakan-akun.jeda.'.$kunci;
        $kunciBatas = 'tindakan-akun.batas.'.$kunci;
        if (RateLimiter::tooManyAttempts($kunciJeda, 1)) {
            return back()->with('galat', 'Tunggu '.RateLimiter::availableIn($kunciJeda).' detik sebelum meminta kode baru.');
        }
        if (RateLimiter::tooManyAttempts($kunciBatas, 3)) {
            return back()->with('galat', 'Batas 3 permintaan kode tercapai. Coba lagi dalam 1 jam.');
        }

        RateLimiter::hit($kunciJeda, 60);
        RateLimiter::hit($kunciBatas, 3600);

        $kode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $api->simpanOtpTindakanAkun($user['id'], [
            'tindakan' => $data['tindakan'],
            'code_hash' => hash('sha256', $kode),
            'expires_at' => now()->addSeconds(self::MASA_BERLAKU_OTP_TINDAKAN)->toDateTimeString(),
            'attempts' => 0,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
        $request->session()->put('tindakan_akun_pending', [
            'user_id' => $user['id'],
            'tindakan' => $data['tindakan'],
        ]);

        Mail::to($user['email'])->send(new TindakanAkunEmail($kode, $data['tindakan']));

        return redirect()->route('akun')->with('pesan', 'Kode verifikasi 6 digit telah dikirim ke email akunmu.');
    }

    public function verifikasiTindakanAkun(Request $request)
    {
        $data = $request->validate([
            'kode' => ['required', 'regex:/^[0-9]{6}$/'],
        ], [
            'kode.regex' => 'Masukkan kode OTP yang terdiri dari 6 angka.',
        ]);

        $pending = $request->session()->get('tindakan_akun_pending');
        abort_unless(is_array($pending) && isset($pending['user_id'], $pending['tindakan']), 403, 'Tidak ada tindakan akun yang perlu diverifikasi.');

        $u = $this->pengguna($request);
        abort_unless((string) ($u['id'] ?? '') === (string) $pending['user_id'], 403);

        $api = new SupabaseApi;
        $otp = $api->otpTindakanAkun($pending['user_id']);
        if (! $otp || $otp['tindakan'] !== $pending['tindakan']
            || now()->greaterThanOrEqualTo(\Carbon\Carbon::parse($otp['expires_at']))) {
            $api->hapusOtpTindakanAkun($pending['user_id']);
            $request->session()->forget('tindakan_akun_pending');

            return redirect()->route('akun')->with('galat', 'Kode OTP sudah kedaluwarsa. Minta kode baru untuk melanjutkan.');
        }

        $percobaan = (int) $otp['attempts'] + 1;
        if (! hash_equals($otp['code_hash'], hash('sha256', $data['kode']))) {
            if ($percobaan >= self::MAKS_PERCOBAAN_OTP_TINDAKAN) {
                $api->hapusOtpTindakanAkun($pending['user_id']);
                $request->session()->forget('tindakan_akun_pending');
            } else {
                $api->tambahPercobaanOtpTindakanAkun($pending['user_id'], $percobaan);
            }

            return redirect()->route('akun')->with('galat', $percobaan >= self::MAKS_PERCOBAAN_OTP_TINDAKAN
                ? 'Batas percobaan kode tercapai. Minta kode OTP baru.'
                : 'Kode OTP tidak cocok. Periksa kembali kode di emailmu.');
        }

        $user = $api->findUserByEmail($u['email']);
        abort_unless($user && (string) $user['id'] === (string) $pending['user_id'], 404, 'Akun tidak ditemukan.');

        try {
            if ($pending['tindakan'] === 'nonaktifkan') {
                $api->hapusOtpTindakanAkun($pending['user_id']);
                $api->updateUser($user['id'], [
                    'dinonaktifkan_pada' => now()->toDateTimeString(),
                    'hapus_otomatis_pada' => now()->addMonthsNoOverflow(3)->toDateTimeString(),
                ]);
            } else {
                $api->deleteUser($user['id']);
                $this->hapusFotoProfil($user['foto_profil'] ?? null);
            }
        } catch (\Illuminate\Http\Client\RequestException|\Illuminate\Http\Client\ConnectionException|\RuntimeException $exception) {
            report($exception);

            return redirect()->route('akun')->with('galat', 'Tindakan akun gagal diproses. Coba lagi atau minta kode OTP baru.');
        }

        $request->session()->forget(['pengguna', 'tindakan_akun_pending']);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('masuk')->with('pesan', $pending['tindakan'] === 'nonaktifkan'
            ? 'Akun dinonaktifkan. Masuk kembali dalam 3 bulan untuk mengaktifkannya lagi.'
            : 'Akun dan data profil berhasil dihapus permanen.');
    }

    public function batalkanTindakanAkun(Request $request)
    {
        $pending = $request->session()->get('tindakan_akun_pending');
        if (is_array($pending) && isset($pending['user_id'])) {
            (new SupabaseApi)->hapusOtpTindakanAkun($pending['user_id']);
        }
        $request->session()->forget('tindakan_akun_pending');

        return redirect()->route('akun')->with('pesan', 'Verifikasi tindakan akun dibatalkan.');
    }

    private function hapusFotoProfil(?string $foto): void
    {
        if (! $foto) {
            return;
        }

        if (str_starts_with($foto, 'profil_picture/')) {
            File::delete(public_path('storage/'.$foto));
        } else {
            Storage::disk('public')->delete($foto);
        }
    }

    public function ulasanSaya(Request $request)
    {
        return view('akun.ulasan', ['ulasan' => Penyimpanan::ulasan(idPengguna: (int) $this->pengguna($request)['id'])]);
    }

    public function perbaruiUlasanSaya(Request $request, string $id)
    {
        $u = $this->pengguna($request);
        $ulasan = collect(Penyimpanan::ulasan(idPengguna: (int) $u['id']))
            ->first(fn ($ulasan) => $ulasan['id'] === $id);
        abort_unless($ulasan, 404);

        $data = $request->validate([
            'bintang' => ['required', 'integer', 'between:1,5'],
            'teks' => ['required', 'string', 'min:10', 'max:600'],
        ], [
            'bintang.required' => 'Pilih jumlah bintang.',
            'teks.min' => 'Tulis minimal 10 karakter supaya ulasanmu berguna bagi orang lain.',
        ]);
        Penyimpanan::tulisUlasan($data + [
            'tempat' => $ulasan['tempat'],
            'id_pengguna' => (int) $u['id'],
            'email' => $u['email'],
            'nama' => $u['nama'],
        ]);

        return redirect()->to($this->urlKembaliDariUlasan($request, $ulasan))
            ->with('pesan', 'Ulasan dan rating berhasil diperbarui.');
    }

    public function hapusUlasanSaya(Request $request, string $id)
    {
        $idPengguna = (int) $this->pengguna($request)['id'];
        $ulasan = collect(Penyimpanan::ulasan(idPengguna: $idPengguna))
            ->first(fn ($ulasan) => $ulasan['id'] === $id);
        abort_unless($ulasan, 404);

        Penyimpanan::hapusUlasan($id);

        return redirect()->to($this->urlKembaliDariUlasan($request, $ulasan))
            ->with('pesan', 'Ulasan dan rating berhasil dihapus.');
    }

    private function urlKembaliDariUlasan(Request $request, array $ulasan): string
    {
        return $request->input('kembali') === 'tempat'
            ? route('tempat', $ulasan['tempat']).'#ulasan'
            : route('ulasan.saya');
    }

    public function tulisUlasan(Request $request, string $slug)
    {
        abort_unless(Katalog::get($slug), 404);
        $data = $request->validate(['bintang' => ['required', 'integer', 'between:1,5'], 'teks' => ['required', 'string', 'min:10', 'max:600']], [
            'bintang.required' => 'Pilih jumlah bintang.',
            'teks.min' => 'Tulis minimal 10 karakter supaya ulasanmu berguna bagi orang lain.',
        ]);
        $u = $this->pengguna($request);
        $hasil = Penyimpanan::tulisUlasan($data + ['tempat' => $slug, 'id_pengguna' => (int) $u['id'], 'email' => $u['email'], 'nama' => $u['nama']]);

        return redirect()->to(route('tempat', $slug).'#ulasan')->with('pesan', ! empty($hasil['diperbarui'])
            ? 'Ulasanmu sudah diperbarui.'
            : 'Terima kasih. Ulasanmu langsung ditayangkan.');
    }

    public function rencanaSaya(Request $request)
    {
        return view('akun.rencana', ['rencana' => Penyimpanan::rencana((int) $this->pengguna($request)['id'])]);
    }

    public function simpanRencana(Request $request)
    {
        $data = $this->validasiRencana($request);
        Penyimpanan::simpanRencana($data + [
            'id_pengguna' => (int) $this->pengguna($request)['id'],
        ]);

        return redirect()->route('rencana.saya')->with('pesan', 'Rencana perjalanan berhasil disimpan.');
    }

    public function perbaruiRencana(Request $request, string $kode)
    {
        $pengguna = $this->pengguna($request);
        $rencana = Penyimpanan::cariRencanaPengguna((int) $pengguna['id'], $kode);
        abort_unless($rencana !== null, 404);

        $r = Penyimpanan::perbaruiRencana($this->validasiRencana($request) + [
            'id_rencana' => (int) $rencana['id'],
            'id_pengguna' => (int) $pengguna['id'],
        ]);
        abort_unless($r !== null, 404);

        return redirect()->route('rencana.saya')->with('pesan', 'Rencana perjalanan berhasil diperbarui.');
    }

    public function hapusRencana(Request $request, string $kode)
    {
        $pengguna = $this->pengguna($request);
        $rencana = Penyimpanan::cariRencanaPengguna((int) $pengguna['id'], $kode);
        abort_unless($rencana !== null, 404);
        abort_unless(Penyimpanan::hapusRencana((int) $rencana['id'], (int) $pengguna['id']), 404);

        return redirect()->route('rencana.saya')->with('pesan', 'Rencana perjalanan berhasil dihapus.');
    }

    private function validasiRencana(Request $request): array
    {
        $data = $request->validate([
            'judul' => ['required', 'string', 'max:80'],
            'minat' => ['required', 'array'], 'minat.*' => ['in:'.implode(',', array_keys(Katalog::KATEGORI))],
            'durasi' => ['required', 'integer', 'in:2,4,6,8'],
            'moda' => ['required', 'array', 'min:1'], 'moda.*' => ['in:jalan,motor,mobil'],
            'mulai' => ['required', 'string', Rule::in(array_keys(Katalog::tempat()))],
            'tujuan' => ['sometimes', 'array', 'max:10'],
            'tujuan.*' => ['string', 'distinct', Rule::in(array_keys(Katalog::tempat()))],
        ]);
        $data['tujuan'] ??= [];

        return $data;
    }
}
