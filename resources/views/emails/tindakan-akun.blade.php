<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfirmasi tindakan akun</title>
</head>
<body style="margin:0;background:#ece7da;color:#1b2622;font-family:Arial,Helvetica,sans-serif;line-height:1.6;">
    <div style="padding:32px 16px;">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;margin:0 auto;background:#ffffff;border-radius:18px;overflow:hidden;">
            <tr><td style="padding:16px 24px;background:#0f3b2e;text-align:center;border-bottom:5px solid #e3a51c;">
                <img src="{{ url('storage/logo_app/png/MedanVisit_logo_horizontal_gelap.png') }}" alt="MedanVisit" width="340" style="display:block;margin:0 auto;width:100%;max-width:340px;height:auto;border:0;">
            </td></tr>
            <tr><td style="padding:40px;">
                <h1 style="margin:0 0 16px;color:#0f3b2e;font-size:26px;">Konfirmasi tindakan akun</h1>
                <p>Kami menerima permintaan untuk {{ $tindakan === 'nonaktifkan' ? 'menonaktifkan' : 'menghapus permanen' }} akun MedanVisit-mu.</p>
                <p>Gunakan tautan berikut untuk melanjutkan. Kamu akan diminta memasukkan password sebelum mengonfirmasi tindakan.</p>
                <p style="margin:28px 0;text-align:center;">
                    <a href="{{ $url }}" style="display:inline-block;padding:14px 24px;border-radius:8px;background:#0f3b2e;color:#fff;text-decoration:none;font-weight:bold;">Lanjutkan tindakan akun</a>
                </p>
                <p>Tautan berlaku selama 30 menit dan hanya dapat digunakan satu kali. Jangan bagikan tautan ini kepada siapa pun.</p>
                @if ($tindakan === 'hapus')
                    <p style="color:#a3221b;font-weight:bold;">Akun hanya akan dihapus permanen setelah kamu memasukkan password dan mengonfirmasi sekali lagi.</p>
                @else
                    <p>Akun nonaktif akan dihapus permanen setelah 3 bulan jika kamu tidak masuk kembali.</p>
                @endif
            </td></tr>
            <tr><td style="padding:20px 40px;background:#f6f1e4;color:#52645b;font-size:12px;">
                Jika kamu tidak meminta tindakan ini, abaikan email ini. Akunmu tidak berubah tanpa password dan konfirmasi akhir.
            </td></tr>
        </table>
    </div>
</body>
</html>
