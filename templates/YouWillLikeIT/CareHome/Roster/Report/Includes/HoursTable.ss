<table class="rr-table">
    <thead>
    <tr>
        <th class="rr-left"><%t YouWillLikeIT\\CareHome\\Roster\\Report.WORKER "Radnik" %></th>
        <th><%t YouWillLikeIT\\CareHome\\Roster\\Report.QUAL "Kval." %></th>
        <th><%t YouWillLikeIT\\CareHome\\Roster\\Report.CONTRACT "Ugovor h/sedm." %></th>
        <th><%t YouWillLikeIT\\CareHome\\Roster\\Report.SHIFTS "Smjene" %></th>
        <th><%t YouWillLikeIT\\CareHome\\Roster\\Report.WORK "Sati" %></th>
        <th><%t YouWillLikeIT\\CareHome\\Roster\\Report.NIGHT "Noćni" %> <small>($NightWindow)</small></th>
        <th><%t YouWillLikeIT\\CareHome\\Roster\\Report.SUNDAY "Nedjelja" %></th>
        <th><%t YouWillLikeIT\\CareHome\\Roster\\Report.HOLIDAY "Praznik" %></th>
        <th><%t YouWillLikeIT\\CareHome\\Roster\\Report.TARGET "Norma" %></th>
        <th><%t YouWillLikeIT\\CareHome\\Roster\\Report.DIFF "Razlika" %></th>
        <% loop $AbsenceTypes %><th title="$Title">$Code<br><small><%t YouWillLikeIT\\CareHome\\Roster\\Report.DAYS "dana" %></small></th><% end_loop %>
    </tr>
    </thead>
    <tbody>
    <% loop $Rows %>
    <tr>
        <td class="rr-left">$Name</td>
        <td>$Qualification</td>
        <td>$WeeklyHours</td>
        <td>$Shifts</td>
        <td><strong>$Work</strong></td>
        <td>$Night</td>
        <td>$Sunday</td>
        <td>$Holiday</td>
        <td>$Target</td>
        <td class="<% if $DiffPositive %>rr-plus<% else_if $DiffNegative %>rr-minus<% end_if %>">$Diff</td>
        <% loop $Absences %><td>$Days</td><% end_loop %>
    </tr>
    <% end_loop %>
    </tbody>
    <tfoot>
    <tr>
        <th class="rr-left"><%t YouWillLikeIT\\CareHome\\Roster\\Report.TOTAL "Ukupno" %></th>
        <th></th>
        <th></th>
        <th>$TotalShifts</th>
        <th>$TotalWork</th>
        <th>$TotalNight</th>
        <th>$TotalSunday</th>
        <th>$TotalHoliday</th>
        <th>$TotalTarget</th>
        <th>$TotalDiff</th>
        <% loop $TotalAbsences %><th>$Days</th><% end_loop %>
    </tr>
    </tfoot>
</table>
<p class="rr-muted rr-small">
    <%t YouWillLikeIT\\CareHome\\Roster\\Report.ALLOWANCES "Dodaci" %>: <%t YouWillLikeIT\\CareHome\\Roster\\Report.NIGHT "Noćni" %> $NightPct · <%t YouWillLikeIT\\CareHome\\Roster\\Report.SUNDAY "Nedjelja" %> $SundayPct · <%t YouWillLikeIT\\CareHome\\Roster\\Report.HOLIDAY "Praznik" %> $HolidayPct · <%t YouWillLikeIT\\CareHome\\Roster\\Report.OVERTIME "Prekovremeni" %> $OvertimePct.
    <%t YouWillLikeIT\\CareHome\\Roster\\Report.NOTE "Sati su iz plana (bez evidencije dolaska). Norma = ugovorni sati / 5 po radnom danu (pon–pet, bez praznika) umanjena za plaćena odsustva. Kategorije se preklapaju (noć nedjeljom broji se u obje). Smjena pripada mjesecu u kojem počinje." %>
    <%t YouWillLikeIT\\CareHome\\Roster\\Report.GENERATED "Generisano" %> $GeneratedAt.
</p>
