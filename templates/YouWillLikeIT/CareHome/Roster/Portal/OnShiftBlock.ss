<section class="ch-card rp-onshift" aria-labelledby="rp-onshift-title">
    <div class="rp-onshift-head">
        <h2 id="rp-onshift-title"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.ON_SHIFT "Danas u smjeni" %></h2>
        <a href="$RosterLink"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.MY_ROSTER "Moj raspored" %> &rsaquo;</a>
    </div>
    <% if $OnShift %>
    <ul>
        <% loop $OnShift %>
        <li<% if $IsMe %> class="rp-me"<% end_if %>>
            <span class="rp-code" style="background:$Colour;color:$Text">$Code</span>
            <span>$Name</span>
            <small>$Time</small>
        </li>
        <% end_loop %>
    </ul>
    <% else %>
    <p class="rp-muted"><%t YouWillLikeIT\\CareHome\\Roster\\Portal.NOBODY "Raspored za ovaj trenutak nije objavljen." %></p>
    <% end_if %>
</section>
<style>
    .rp-onshift { padding: 1rem; margin-bottom: 1rem; }
    .rp-onshift-head { display: flex; justify-content: space-between; align-items: baseline; gap: .5rem; }
    .rp-onshift h2 { margin: 0 0 .5rem; font-size: 1.05rem; font-weight: 700; }
    .rp-onshift a { color: #155e63; font-weight: 600; font-size: .9rem; }
    .rp-onshift ul { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: .4rem .9rem; }
    .rp-onshift li { display: flex; align-items: center; gap: .4rem; }
    .rp-onshift li small { color: #64748b; }
    .rp-onshift .rp-me span:nth-child(2) { font-weight: 700; }
    .rp-code { border-radius: .35rem; padding: .05rem .4rem; font-size: .75rem; font-weight: 700; }
    .rp-muted { color: #64748b; margin: 0; }
</style>
