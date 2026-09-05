{{--
    Shared banner for every transactional email (welcome, otp, password
    reset could adopt it too). The icon is inlined as a base64 data URI —
    there's no public production domain yet to host it at a real URL, and
    this SVG is tiny (~250 bytes). Data URIs render fine in Gmail/Apple
    Mail/mobile clients but not older Outlook desktop (Word's rendering
    engine) — acceptable for now, revisit once assets can be hosted at a
    real URL instead.
--}}
<tr>
<td style="background-color:#0d9488; background-image:linear-gradient(135deg,#0d9488 0%,#134e4a 100%); padding:28px 40px;">
<table role="presentation" cellpadding="0" cellspacing="0">
<tr>
<td style="padding-right:10px; vertical-align:middle;">
<img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMDAgMTAwIj4KICA8Y2lyY2xlIGN4PSI1MCIgY3k9IjUwIiByPSIyNiIgZmlsbD0ibm9uZSIgc3Ryb2tlPSIjRkZGRkZGIiBzdHJva2Utd2lkdGg9IjEwIi8+CiAgPGNpcmNsZSBjeD0iNjguNCIgY3k9IjMxLjYiIHI9IjEwIiBmaWxsPSIjRkZGRkZGIi8+CiAgPGNpcmNsZSBjeD0iMjQiIGN5PSI3NiIgcj0iNi41IiBmaWxsPSIjRkZGRkZGIi8+Cjwvc3ZnPgo=" width="26" height="26" alt="" style="display:block;">
</td>
<td style="vertical-align:middle;">
<span style="font-size:22px; font-weight:700; color:#ffffff; letter-spacing:-0.3px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">Pingly</span>
</td>
</tr>
</table>
</td>
</tr>
