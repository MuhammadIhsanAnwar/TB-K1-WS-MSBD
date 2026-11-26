Kode verifikasi tindakan akun MedanVisit

Kami menerima permintaan untuk {{ $tindakan === 'nonaktifkan' ? 'menonaktifkan' : 'menghapus permanen' }} akun MedanVisit-mu.

Kode OTP: {{ $kode }}

Kode berlaku selama 10 menit dan hanya dapat digunakan satu kali. Jangan bagikan kode ini kepada siapa pun.
@if ($tindakan === 'hapus')
Setelah kode diverifikasi, akun akan langsung dihapus permanen.
@else
Akun nonaktif akan dihapus permanen setelah 3 bulan jika kamu tidak masuk kembali.
@endif

Jika kamu tidak meminta tindakan ini, abaikan email ini. Akunmu tidak berubah tanpa verifikasi kode.
