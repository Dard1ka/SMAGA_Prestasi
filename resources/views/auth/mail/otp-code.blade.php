<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
</head>
<body style="margin:0;padding:24px;background:#F3F5F8;font-family:Arial,Helvetica,sans-serif;color:#141A2B;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;margin:0 auto;background:#FFFFFF;border:1px solid #DDE2E9;border-radius:10px;">
        <tr>
            <td style="padding:28px;">
                <p style="margin:0 0 4px;font-size:13px;font-weight:bold;color:#1B2A4E;">SMAGA Talent Hub</p>
                <p style="margin:0 0 16px;font-size:16px;">Halo {{ $firstName }},</p>
                <p style="margin:0 0 16px;font-size:15px;line-height:1.5;">{{ $intro }} Masukkan kode ini:</p>
                <p style="margin:0 0 16px;font-size:32px;font-weight:bold;letter-spacing:8px;">{{ $code }}</p>
                <p style="margin:0 0 16px;font-size:14px;color:#3A4254;">Kode berlaku {{ $ttlMinutes }} menit dan hanya bisa dipakai sekali.</p>
                <p style="margin:0;font-size:14px;color:#3A4254;">Jika tidak merasa memintanya, abaikan email ini dan hubungi Kesiswaan.</p>
            </td>
        </tr>
    </table>
    <p style="max-width:480px;margin:12px auto 0;font-size:13px;color:#566072;text-align:center;">Email otomatis, jangan dibalas.</p>
</body>
</html>
