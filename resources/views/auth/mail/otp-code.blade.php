<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
</head>
<body style="margin:0;padding:24px;background:#F4F5FB;font-family:Arial,Helvetica,sans-serif;color:#151A33;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;margin:0 auto;background:#FFFFFF;border:1px solid #E7E9F3;border-radius:16px;">
        <tr>
            <td style="padding:28px;">
                <p style="margin:0 0 4px;font-size:13px;font-weight:bold;color:#3148D6;">SMAGA Talent Hub</p>
                <p style="margin:0 0 16px;font-size:16px;">Halo {{ $firstName }},</p>
                <p style="margin:0 0 16px;font-size:15px;line-height:1.5;">{{ $intro }} Masukkan kode ini:</p>
                <p style="margin:0 0 16px;font-size:32px;font-weight:bold;letter-spacing:8px;">{{ $code }}</p>
                <p style="margin:0 0 16px;font-size:14px;color:#4A5068;">Kode berlaku {{ $ttlMinutes }} menit dan hanya bisa dipakai sekali.</p>
                <p style="margin:0;font-size:14px;color:#4A5068;">Jika bukan kamu, abaikan email ini dan hubungi Kesiswaan.</p>
            </td>
        </tr>
    </table>
    <p style="max-width:480px;margin:12px auto 0;font-size:12px;color:#8A90A6;text-align:center;">Email otomatis, jangan dibalas.</p>
</body>
</html>
