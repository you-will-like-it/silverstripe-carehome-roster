<!doctype html>
<html lang="bs">
<head><meta charset="utf-8"><title>Raspored smjena</title></head>
<body style="margin:0;padding:24px;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;">
    <tr><td style="padding:20px 24px;background:#155e63;color:#ffffff;border-radius:12px 12px 0 0;font-size:18px;font-weight:bold;">
        <%t YouWillLikeIT\\CareHome\\Roster\\Email.HEADER "Raspored smjena" %>
    </td></tr>
    <tr><td style="padding:20px 24px;">
        <p style="margin:0 0 16px;"><%t YouWillLikeIT\\CareHome\\Roster\\Email.HELLO "Pozdrav {name}," name=$Member.FirstName %></p>
        <% loop $Items %>
        <div style="padding:12px 0;border-top:1px solid #e2e8f0;">
            <strong>$Subject</strong><br>
            <span style="color:#334155;">$Message</span>
            <% if $Link %><br><a href="$Link" style="color:#155e63;"><%t YouWillLikeIT\\CareHome\\Roster\\Email.OPEN "Otvori raspored" %></a><% end_if %>
        </div>
        <% end_loop %>
        <p style="margin:16px 0 0;color:#64748b;font-size:12px;"><%t YouWillLikeIT\\CareHome\\Roster\\Email.FOOTER "Automatska poruka sistema doma. Raspored je dostupan i u aplikaciji (PIN prijava)." %></p>
    </td></tr>
</table>
</body>
</html>
