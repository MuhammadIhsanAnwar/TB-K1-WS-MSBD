<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kode verifikasi tindakan akun</title>
</head>
<body style="margin:0;background:#ece7da;color:#1b2622;font-family:Arial,Helvetica,sans-serif;line-height:1.6;">
    <div style="padding:32px 16px;">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;margin:0 auto;background:#ffffff;border-radius:18px;overflow:hidden;">
            <tr><td style="padding:16px 24px;background:#0f3b2e;text-align:center;border-bottom:5px solid #e3a51c;">
                <img src="{{ url('storage/logo_app/png/MedanVisit_logo_horizontal_gelap.png') }}" alt="MedanVisit" width="340" style="display:block;margin:0 auto;width:100%;max-width:340px;height:auto;border:0;">
            </td></tr>
            <tr><td style="padding:40px;">
                <h1 style="margin:0 0 16px;color:#0f3b2e;font-size:26px;">Verifikasi tindakan akun</h1>
                <p>Kami menerima permintaan untuk {{ $tindakan === 'nonaktifkan' ? 'menonaktifkan' : 'menghapus permanen' }} akun MedanVisit-mu.</p>
                <p style="margin:24px 0;text-align:center;font-size:36px;font-weight:bold;letter-spacing:10px;color:#0f3b2e;">{{ $kode }}</p>
                <p>Kode berlaku selama 10 menit dan hanya dapat digunakan satu kali. Jangan bagikan kode ini kepada siapa pun.</p>
                @if ($tindakan === 'hapus')
                    <p style="color:#a3221b;font-weight:bold;">Setelah kode diverifikasi, akun akan langsung dihapus permanen.</p>
                @else
                    <p>Akun nonaktif akan dihapus permanen setelah 3 bulan jika kamu tidak masuk kembali.</p>
                @endif
            </td></tr>
            <tr><td style="padding:20px 40px;background:#f6f1e4;color:#52645b;font-size:12px;">
                Jika kamu tidak meminta tindakan ini, abaikan email ini. Akunmu tidak berubah tanpa verifikasi kode.
            </td></tr>
        </table>
    </div>
</body>
</html>
