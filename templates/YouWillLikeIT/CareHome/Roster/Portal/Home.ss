<% include YouWillLikeIT\\CareHome\\PortalHead %>
$RosterStyles
<div class="rp-top">
    <h1>$Title</h1>
    <% if $BackLink %><a href="$BackLink" class="ch-btn-ghost rp-small-btn"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.BACK "Nazad na portal" %></a><% end_if %>
</div>

<%-- notifications --%>
<section id="obavjestenja" class="ch-card rp-section">
    <div class="rp-section-head">
        <h2><%t YouWillLikeIT\\CareHome\\Roster\\Portal.NOTIFICATIONS "Obavještenja" %><% if $UnreadCount %> <span class="rp-badge">$UnreadCount</span><% end_if %></h2>
        <% if $UnreadCount %>
        <form method="post" action="$ReadAction">
            <input type="hidden" name="$SecurityTokenName" value="$SecurityTokenValue">
            <button type="submit" class="rp-link-btn"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.MARK_READ "Označi kao pročitano" %></button>
        </form>
        <% end_if %>
    </div>
    <% if $Notifications %>
    <ul class="rp-list">
        <% loop $Notifications %>
        <li class="<% if not $ReadAt %>rp-unread<% end_if %>">
            <strong>$Subject</strong>
            <span class="rp-muted">$Message</span>
            <small class="rp-muted">$Created.Format('dd.MM. HH:mm')</small>
        </li>
        <% end_loop %>
    </ul>
    <% else %>
    <p class="rp-muted"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.NO_NOTIFICATIONS "Nema obavještenja." %></p>
    <% end_if %>
</section>

<%-- upcoming --%>
<section class="ch-card rp-section">
    <h2><%t YouWillLikeIT\\CareHome\\Roster\\Portal.UPCOMING "Sljedeće smjene" %></h2>
    <% if $Upcoming %>
    <ul class="rp-list">
        <% loop $Upcoming %>
        <li class="rp-row">
            <span class="rp-code" style="background:$Colour;color:$Text">$Code</span>
            <span class="rp-grow">$Label<% if $Changed %> <span class="rp-changed" title="<%t YouWillLikeIT\\CareHome\\Roster\\Portal.CHANGED "Izmijenjeno nakon objave" %>">●</span><% end_if %></span>
            <% if $SwapLink %><a href="$SwapLink" class="rp-link-btn"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.SWAP "Zamjena" %></a><% end_if %>
        </li>
        <% end_loop %>
    </ul>
    <% else %>
    <p class="rp-muted"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.NO_UPCOMING "Nema objavljenih smjena." %></p>
    <% end_if %>
</section>

<%-- month --%>
<section class="ch-card rp-section">
    <div class="rp-section-head">
        <a href="$PrevLink" class="rp-link-btn" aria-label="<%t YouWillLikeIT\\CareHome\\Roster\\Portal.PREV "Prethodni mjesec" %>">&lsaquo;</a>
        <h2>$MonthLabel</h2>
        <a href="$NextLink" class="rp-link-btn" aria-label="<%t YouWillLikeIT\\CareHome\\Roster\\Portal.NEXT "Sljedeći mjesec" %>">&rsaquo;</a>
    </div>
    <% if $MonthVisible %>
    <ul class="rp-days">
        <% loop $Days %>
        <li class="<% if $IsWeekend %>rp-weekend<% end_if %> <% if $IsToday %>rp-today<% end_if %> <% if $Holiday %>rp-holiday<% end_if %>">
            <span class="rp-date"><strong>$Day</strong> <small>$Weekday</small></span>
            <span class="rp-grow">
                <% if $Holiday %><small class="rp-holiday-name">$Holiday</small><% end_if %>
                <% loop $Shifts %>
                    <span class="rp-shift">
                        <span class="rp-code" style="background:$Colour;color:$Text">$Code</span>
                        $Time
                        <% if $Changed %><span class="rp-changed" title="<%t YouWillLikeIT\\CareHome\\Roster\\Portal.CHANGED "Izmijenjeno nakon objave" %>">●</span><% end_if %>
                        <% if $SubstituteFor %><small class="rp-muted">(<%t YouWillLikeIT\\CareHome\\Roster\\Portal.SUB_FOR "zamjena za" %> $SubstituteFor)</small><% end_if %>
                        <% if $SwapLink %><a href="$SwapLink" class="rp-link-btn"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.SWAP "Zamjena" %></a><% end_if %>
                    </span>
                <% end_loop %>
                <% with $Absence %>
                    <span class="rp-code <% if $Pending %>rp-pending<% end_if %>" style="background:$Colour;color:$Text" title="$Title">$Code</span> <small>$Title<% if $Pending %> (<%t YouWillLikeIT\\CareHome\\Roster\\Portal.PENDING "na čekanju" %>)<% end_if %></small>
                <% end_with %>
            </span>
        </li>
        <% end_loop %>
    </ul>
    <% else %>
    <p class="rp-muted"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.NOT_PUBLISHED "Raspored za ovaj mjesec još nije objavljen." %></p>
    <% end_if %>
</section>

<%-- absences --%>
<section id="odsustva" class="ch-card rp-section">
    <h2><%t YouWillLikeIT\\CareHome\\Roster\\Portal.ABSENCES "Odsustva" %></h2>
    <p class="rp-leave">
        <%t YouWillLikeIT\\CareHome\\Roster\\Portal.LEAVE "Godišnji odmor {year}: preostalo" year=$LeaveYear %> <strong>$LeaveRemaining</strong>
        · <%t YouWillLikeIT\\CareHome\\Roster\\Portal.LEAVE_USED "iskorišteno" %> $LeaveUsed
        <% if $LeavePending %>· <%t YouWillLikeIT\\CareHome\\Roster\\Portal.LEAVE_PENDING "na čekanju" %> $LeavePending<% end_if %>
        <% if $LeaveCarry %>· <%t YouWillLikeIT\\CareHome\\Roster\\Portal.LEAVE_CARRY "od toga preneseno" %> $LeaveCarry<% end_if %>
    </p>

    <% if $AbsenceTypes %>
    <form method="post" action="$AbsenceAction" class="rp-form">
        <input type="hidden" name="$SecurityTokenName" value="$SecurityTokenValue">
        <label class="ch-label" for="rp-type"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.TYPE "Vrsta" %></label>
        <select id="rp-type" name="type" class="ch-input" required>
            <% loop $AbsenceTypes %><option value="$Value">$Label</option><% end_loop %>
        </select>
        <div class="rp-two">
            <div>
                <label class="ch-label" for="rp-from"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.FROM "Od" %></label>
                <input id="rp-from" type="date" name="from" class="ch-input" min="$MinDate" value="$Today" required>
            </div>
            <div>
                <label class="ch-label" for="rp-to"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.TO "Do (uključivo)" %></label>
                <input id="rp-to" type="date" name="to" class="ch-input" min="$MinDate" value="$Today" required>
            </div>
        </div>
        <label class="ch-label" for="rp-note"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.NOTE "Napomena (bez dijagnoze)" %></label>
        <input id="rp-note" type="text" name="note" class="ch-input" maxlength="500">
        <button type="submit" class="ch-btn-primary rp-submit"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.SEND "Pošalji" %></button>
    </form>
    <% end_if %>

    <% if $Absences %>
    <ul class="rp-list">
        <% loop $Absences %>
        <li class="rp-row">
            <span class="rp-grow">
                <strong>$Type</strong> · $Range<% if $LeaveDays %> · $LeaveDays <%t YouWillLikeIT\\CareHome\\Roster\\Portal.DAYS "dana" %><% end_if %>
                <br><span class="rp-status rp-status-$StatusCode">$Status</span><% if $DecisionNote %> <small class="rp-muted">$DecisionNote</small><% end_if %>
            </span>
            <% if $CancelAction %>
            <form method="post" action="$CancelAction">
                <input type="hidden" name="$Up.SecurityTokenName" value="$Up.SecurityTokenValue">
                <button type="submit" class="rp-link-btn"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.WITHDRAW "Povuci" %></button>
            </form>
            <% end_if %>
        </li>
        <% end_loop %>
    </ul>
    <% end_if %>
</section>

<%-- swaps --%>
<section id="zamjene" class="ch-card rp-section">
    <h2><%t YouWillLikeIT\\CareHome\\Roster\\Portal.SWAPS "Zamjene smjena" %></h2>
    <% if $IncomingSwaps %>
    <h3><%t YouWillLikeIT\\CareHome\\Roster\\Portal.INCOMING "Kolege traže da preuzmete" %></h3>
    <ul class="rp-list">
        <% loop $IncomingSwaps %>
        <li>
            <strong>$From</strong>: $Summary
            <% if $Note %><br><small class="rp-muted">$Note</small><% end_if %>
            <div class="rp-row rp-actions">
                <form method="post" action="$AcceptAction" class="rp-inline">
                    <input type="hidden" name="$Up.SecurityTokenName" value="$Up.SecurityTokenValue">
                    <button type="submit" class="ch-btn-primary rp-small-btn"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.ACCEPT "Prihvatam" %></button>
                </form>
                <form method="post" action="$DeclineAction" class="rp-inline">
                    <input type="hidden" name="$Up.SecurityTokenName" value="$Up.SecurityTokenValue">
                    <button type="submit" class="ch-btn-ghost rp-small-btn"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.DECLINE "Odbijam" %></button>
                </form>
            </div>
        </li>
        <% end_loop %>
    </ul>
    <% end_if %>

    <% if $MySwaps %>
    <h3><%t YouWillLikeIT\\CareHome\\Roster\\Portal.MY_SWAPS "Moji zahtjevi" %></h3>
    <ul class="rp-list">
        <% loop $MySwaps %>
        <li class="rp-row">
            <span class="rp-grow">$Summary → <strong>$To</strong><br><span class="rp-status">$Status</span><% if $DecisionNote %> <small class="rp-muted">$DecisionNote</small><% end_if %></span>
            <% if $CancelAction %>
            <form method="post" action="$CancelAction">
                <input type="hidden" name="$Up.SecurityTokenName" value="$Up.SecurityTokenValue">
                <button type="submit" class="rp-link-btn"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.WITHDRAW "Povuci" %></button>
            </form>
            <% end_if %>
        </li>
        <% end_loop %>
    </ul>
    <% end_if %>

    <% if not $IncomingSwaps %><% if not $MySwaps %>
    <p class="rp-muted"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.SWAP_HINT "Zamjenu tražite klikom na „Zamjena" pored svoje smjene. Kolega potvrđuje, zatim odobrava administrator." %></p>
    <% end_if %><% end_if %>
</section>

<% include YouWillLikeIT\\CareHome\\PortalFoot %>
