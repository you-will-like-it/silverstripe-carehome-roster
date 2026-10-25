<% include YouWillLikeIT\\CareHome\\PortalHead %>
$RosterStyles
<div class="rp-top">
    <h1>$Title</h1>
    <a href="$BackLink" class="ch-btn-ghost rp-small-btn"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.BACK_ROSTER "Nazad" %></a>
</div>

<section class="ch-card rp-section">
    <p class="rp-lead">$AssignmentLabel</p>

    <% if $Colleagues %>
    <form method="get" action="$ChooseAction" class="rp-form">
        <input type="hidden" name="assignment" value="$AssignmentID">
        <label class="ch-label" for="rp-colleague"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.COLLEAGUE "Ko preuzima smjenu?" %></label>
        <select id="rp-colleague" name="colleague" class="ch-input" onchange="this.form.submit()">
            <option value=""><%t YouWillLikeIT\\CareHome\\Roster\\Portal.CHOOSE "— odaberite kolegu —" %></option>
            <% loop $Colleagues %>
            <option value="$ID"<% if $Selected %> selected<% end_if %>>$Name<% if $Warning %> ⚠<% end_if %></option>
            <% end_loop %>
        </select>
        <noscript><button type="submit" class="ch-btn-ghost rp-small-btn"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.NEXT_STEP "Dalje" %></button></noscript>
        <p class="rp-muted rp-small"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.COLLEAGUE_HINT "Prikazani su samo kolege koji mogu preuzeti smjenu. ⚠ = krši pravila odmora, administrator odlučuje." %></p>
    </form>

    <% if $Colleague %>
    <form method="post" action="$SubmitAction" class="rp-form">
        <input type="hidden" name="$SecurityTokenName" value="$SecurityTokenValue">
        <input type="hidden" name="assignment" value="$AssignmentID">
        <input type="hidden" name="colleague" value="$Colleague.ID">

        <% if $CounterOptions %>
        <label class="ch-label" for="rp-counter"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.COUNTER "Zauzvrat preuzimam smjenu kolege (opcionalno)" %></label>
        <select id="rp-counter" name="counter" class="ch-input">
            <option value=""><%t YouWillLikeIT\\CareHome\\Roster\\Portal.NO_COUNTER "— bez razmjene —" %></option>
            <% loop $CounterOptions %><option value="$ID">$Label</option><% end_loop %>
        </select>
        <% end_if %>

        <label class="ch-label" for="rp-swap-note"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.NOTE_SHORT "Napomena" %></label>
        <input id="rp-swap-note" type="text" name="note" class="ch-input" maxlength="500">
        <button type="submit" class="ch-btn-primary rp-submit"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.SEND_TO "Pošalji zahtjev kolegi {name}" name=$Colleague.Name %></button>
    </form>
    <% end_if %>
    <% else %>
    <p class="rp-muted"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.NO_COLLEAGUES "Nema kolege koji može preuzeti ovu smjenu. Obratite se administratoru." %></p>
    <% end_if %>
</section>

<% include YouWillLikeIT\\CareHome\\PortalFoot %>
