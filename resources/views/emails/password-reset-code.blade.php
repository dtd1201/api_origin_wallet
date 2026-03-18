<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Origin Wallet password reset code</title>
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
                                Use the verification code below to reset your Origin Wallet password.
                            </p>
                            <div style="margin:24px 0;padding:20px;background-color:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;text-align:center;">
                                <div style="font-size:32px;letter-spacing:8px;font-weight:700;color:#1d4ed8;">
                                    {{ $verificationCode }}
                                </div>
                            </div>
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                                This code will expire in {{ $expiresInMinutes }} minutes.
                            </p>
                            <p style="margin:0;font-size:14px;line-height:1.6;color:#6b7280;">
                                If you did not request a password reset, you can safely ignore this email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
