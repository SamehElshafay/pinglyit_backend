<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Welcome to Pingly</title>
</head>
<body style="margin:0; padding:0; background-color:#f1f5f9; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9; padding:32px 16px;">
<tr>
<td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 12px 32px -12px rgba(15,23,42,0.15);">

<!-- Brand banner -->
<tr>
<td style="background-color:#0d9488; background-image:linear-gradient(135deg,#0d9488 0%,#134e4a 100%); padding:32px 40px;">
<span style="font-size:22px; font-weight:700; color:#ffffff; letter-spacing:-0.3px;">Pingly</span>
</td>
</tr>

<!-- Body -->
<tr>
<td style="padding:36px 40px 8px;">
<h1 style="margin:0 0 18px; font-size:20px; line-height:1.35; color:#0f172a;">
Welcome aboard, {{ $user->name }} 👋
</h1>
<p style="margin:0 0 16px; font-size:14.5px; line-height:1.7; color:#334155;">
Thanks for creating a Pingly account for
@if($user->company)
<strong>{{ $user->company->name }}</strong>.
@else
your company.
@endif
Your wallet is set up and ready — everything on Pingly runs through it, one balance for every service.
</p>
<p style="margin:0 0 16px; font-size:14.5px; line-height:1.7; color:#334155;">
Whenever you're ready, connect the <strong>WhatsApp Gateway</strong> or the <strong>AI Gateway</strong> from your
dashboard — or turn on AI auto-reply so Pingly's AI can respond to your WhatsApp clients on its own.
</p>
</td>
</tr>

<!-- CTA -->
<tr>
<td style="padding:8px 40px 32px;">
<table role="presentation" cellpadding="0" cellspacing="0">
<tr>
<td style="border-radius:10px; background-color:#0d9488;">
<a href="{{ $dashboardUrl }}" target="_blank"
style="display:inline-block; padding:12px 26px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none;">
Go to your dashboard
</a>
</td>
</tr>
</table>
</td>
</tr>

<!-- Footer -->
<tr>
<td style="padding:20px 40px 32px; border-top:1px solid #e2e8f0;">
<p style="margin:0; font-size:12.5px; line-height:1.6; color:#94a3b8;">
Didn't create this account? You can safely ignore this email.
</p>
</td>
</tr>

</table>
</td>
</tr>
</table>
</body>
</html>
