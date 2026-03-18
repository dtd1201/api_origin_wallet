<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Origin Wallet verification code</title>
</head>
<body style="margin:0;padding:0;background-color:#f6f7fb;font-family:Arial,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f6f7fb;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background-color:#ffffff;border-radius:16px;overflow:hidden;">
                    <tr>
                        <td style="background-color:#0f172a;padding:24px 32px;color:#ffffff;font-size:24px;font-weight:700;">
                            Origin Wallet
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 16px;font-size:16px;line-height:1.6;">
                                Hello {{ $fullName !== '' ? $fullName : 'there' }},
                            </p>
                            <p style="margin:0 0 16px;font-size:16px;line-height:1.6;">
                                Use the verification code below to complete your Origin Wallet registration.
                            </p>
                            <p style="margin:0 0 20px;font-size:16px;line-height:1.6;">
                                Or click the button below to verify instantly.
                            </p>
                            <div style="margin:0 0 24px;text-align:center;">
                                <a href="{{ $activationUrl }}" style="display:inline-block;background-color:#0f172a;color:#ffffff;text-decoration:none;padding:14px 24px;border-radius:10px;font-size:15px;font-weight:700;">
                                    Verify Email
                                </a>
                            </div>
                            <div style="margin:24px 0;padding:20px;background-color:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;text-align:center;">
                                <div style="font-size:32px;letter-spacing:8px;font-weight:700;color:#1d4ed8;">
                                    {{ $verificationCode }}
                                </div>
                            </div>
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                                This code will expire in {{ $expiresInMinutes }} minutes.
                            </p>
                            <p style="margin:0;font-size:14px;line-height:1.6;color:#6b7280;">
                                If you did not request this, you can safely ignore this email.
                            </p>
                            <p style="margin:16px 0 0;font-size:12px;line-height:1.6;color:#9ca3af;word-break:break-all;">
                                If the button does not work, open this link:<br>
                                <a href="{{ $activationUrl }}" style="color:#2563eb;">{{ $activationUrl }}</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
