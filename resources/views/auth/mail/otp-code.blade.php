<!DOCTYPE html>
<html lang="id">
<head>
    {{--
        OTP mail, HTML part (App\Mail\OtpCodeMail). Plain Blade with inline styles, no Markdown and no link.
        Same card as the notification mails: the values repeat resources/views/vendor/mail/html/themes/sth.css by
        hand (page #F3F5F8, card 570px with a 1px #DDE2E9 line and radius 10, header "SMAGA Talent Hub / SMA Negeri 3
        Semarang", greeting 18/600, text 16, footer 13 #566072). Change both together. Nothing under 13px, no image.
    --}}
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ config('app.name') }}</title>
    <style>
        @media only screen and (max-width: 600px) {
            .inner-body,
            .footer {
                width: 100% !important;
            }

            .content-cell,
            .header {
                padding-left: 20px !important;
                padding-right: 20px !important;
            }
        }
    </style>
</head>
<body style="margin:0;padding:0;width:100%;background-color:#F3F5F8;color:#141A2B;line-height:1.5;font-family:'Public Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;-webkit-text-size-adjust:none;">
    {{-- Inbox preview line: the sentence, never the code (it would show on a lock screen) --}}
    <div class="preheader" style="display:none;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">{{ $intro }}</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;margin:0;padding:24px 12px 0;background-color:#F3F5F8;">
        <tr>
            <td align="center">
                <table class="inner-body" role="presentation" align="center" width="570" cellpadding="0" cellspacing="0" style="width:570px;margin:0 auto;background-color:#FFFFFF;border:1px solid #DDE2E9;border-radius:10px;">
                    <tr>
                        <td class="header" style="padding:20px 32px 16px;border-bottom:1px solid #DDE2E9;text-align:left;font-family:'Public Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                            <span style="font-size:16px;font-weight:700;color:#1B2A4E;">SMAGA Talent Hub</span><br>
                            <span style="font-size:13px;color:#566072;">SMA Negeri 3 Semarang</span>
                        </td>
                    </tr>
                    <tr>
                        <td class="content-cell" style="padding:24px 32px;font-family:'Public Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                            <h1 style="margin:0 0 16px;font-size:18px;font-weight:600;line-height:1.4;color:#141A2B;text-align:left;">{{ $greetingLine }}</h1>
                            <p style="margin:0 0 16px;font-size:16px;line-height:1.5;color:#141A2B;text-align:left;">{{ $intro }} Masukkan kode ini:</p>
                            <p style="margin:0 0 16px;font-size:32px;font-weight:700;line-height:1.3;letter-spacing:8px;color:#141A2B;text-align:left;">{{ $code }}</p>
                            <p style="margin:0 0 8px;font-size:14px;line-height:1.5;color:#3A4254;text-align:left;">Kode berlaku {{ $ttlMinutes }} menit dan hanya bisa dipakai sekali.</p>
                            <p style="margin:0;font-size:14px;line-height:1.5;color:#3A4254;text-align:left;">Jika tidak merasa memintanya, abaikan email ini dan hubungi Kesiswaan.</p>
                        </td>
                    </tr>
                </table>
                <table class="footer" role="presentation" align="center" width="570" cellpadding="0" cellspacing="0" style="width:570px;margin:0 auto;">
                    <tr>
                        <td class="content-cell" align="center" style="padding:16px 32px 24px;font-family:'Public Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                            <p style="margin:0;font-size:13px;line-height:1.5;color:#566072;text-align:center;">{{ $footer }}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
