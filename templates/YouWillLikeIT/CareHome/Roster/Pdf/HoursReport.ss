<style>
    @page { sheet-size: A4-L; margin: 12mm 10mm 14mm 10mm; margin-header: 0; margin-footer: 6mm; }
    body { font-family: dejavusans; font-size: 7.5pt; color: #0f172a; }
    h1 { font-size: 12pt; margin: 0 0 1mm; }
    .rr-muted { color: #64748b; }
    .rr-small { font-size: 6.5pt; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 0.2mm solid #cbd5e1; padding: 1mm 1.2mm; text-align: right; }
    th { background: #f1f5f9; }
    .rr-left { text-align: left; }
    .rr-plus { color: #b45309; }
    .rr-minus { color: #be123c; }
    tfoot th { background: #e2e8f0; }
</style>
<h1>$Heading</h1>
<p class="rr-muted"><% if $Facility %>$Facility · <% end_if %><%t YouWillLikeIT\\CareHome\\Roster\\Report.STATUS "Status rasporeda" %>: $PeriodStatus</p>
<% include YouWillLikeIT\\CareHome\\Roster\\Report\\HoursTable %>
