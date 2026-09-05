<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Your Pingly verification code</title>
</head>
<body style="margin:0; padding:0; background-color:#f1f5f9; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9; padding:32px 16px;">
<tr>
<td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 12px 32px -12px rgba(15,23,42,0.15);">

@include('emails.partials.banner')

<tr>
<td style="padding:36px 40px 8px;">
<h1 style="margin:0 0 12px; font-size:18px; line-height:1.35; color:#0f172a;">
Verify your email
</h1>
<p style="margin:0 0 24px; font-size:14.5px; line-height:1.7; color:#334155;">
Enter this code to finish setting up your Pingly account. It expires in 10 minutes.
</p>
</td>
</tr>

<tr>
<td style="padding:0 40px 28px;" align="center">
<span style="display:inline-block; padding:16px 32px; border-radius:10px; background-color:#f0fdfa; border:1px solid #99f6e4; font-size:32px; font-weight:700; letter-spacing:8px; color:#0d9488;">
{{ $otp }}
</span>
</td>
</tr>

<tr>
<td style="padding:20px 40px 32px; border-top:1px solid #e2e8f0;">
<p style="margin:0; font-size:12.5px; line-height:1.6; color:#94a3b8;">
Didn't try to sign in to Pingly? You can safely ignore this email.
</p>
</td>
</tr>

</table>
</td>
</tr>
</table>
</body>
</html>
