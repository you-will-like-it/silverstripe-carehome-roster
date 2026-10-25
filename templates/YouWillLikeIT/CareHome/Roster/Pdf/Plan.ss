<style>
    @page { sheet-size: A4-L; margin: 10mm 8mm 12mm 8mm; margin-header: 0; margin-footer: 5mm; }
    body { font-family: dejavusans; font-size: 7pt; color: #0f172a; }
    h1 { font-size: 12pt; margin: 0 0 1mm; }
    .muted { color: #64748b; }
    table.plan { border-collapse: collapse; width: 100%; table-layout: fixed; }
    .plan th, .plan td { border: 0.2mm solid #cbd5e1; padding: 0.6mm 0.3mm; text-align: center; overflow: hidden; }
    .plan th { background: #f1f5f9; font-size: 6.5pt; }
    .plan .name { text-align: left; width: 34mm; padding-left: 1mm; white-space: nowrap; }
    .plan .we { background: #f8fafc; }
    .plan .hol { background: #fef3c7; }
    .plan td.code { font-weight: bold; font-size: 6.5pt; }
    .legend { margin-top: 3mm; }
    .legend span { display: inline-block; margin: 0 3mm 1mm 0; }
    .legend b { padding: 0.3mm 1mm; }
</style>
<h1>$Heading</h1>
<p class="muted"><% if $Facility %>$Facility · <% end_if %><%t YouWillLikeIT\\CareHome\\Roster\\Report.STATUS "Status rasporeda" %>: $Status · <%t YouWillLikeIT\\CareHome\\Roster\\Report.GENERATED "Generisano" %> $GeneratedAt</p>
<table class="plan">
    <thead>
    <tr>
        <th class="name"><%t YouWillLikeIT\\CareHome\\Roster\\Report.WORKER "Radnik" %></th>
        <% loop $Days %><th class="<% if $IsHoliday %>hol<% else_if $IsWeekend %>we<% end_if %>">$Day<br>$Weekday</th><% end_loop %>
    </tr>
    </thead>
    <tbody>
    <% loop $Rows %>
    <tr>
        <td class="name">$Name <span class="muted">$Qualification</span></td>
        <% loop $Cells %>
            <% if $Codes %>
                <td class="code" style="background:$Colour;color:$Text">$Codes</td>
            <% else %>
                <td class="<% if $IsWeekend %>we<% end_if %>"></td>
            <% end_if %>
        <% end_loop %>
    </tr>
    <% end_loop %>
    </tbody>
</table>
<div class="legend">
    <% loop $Legend %><span><b style="background:$Colour;color:$Text">$Code</b> $Title<% if $Time %> $Time<% end_if %></span><% end_loop %>
</div>
