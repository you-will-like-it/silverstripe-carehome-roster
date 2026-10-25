<!doctype html>
<html lang="$ContentLocale">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>$Title</title>
    $BoardStyles
    $BoardScript
    $ViteTags
</head>
<body class="rs-body">
<div class="rs-app" x-data="rosterBoard($BoardConfig.ATT)" x-init="init()" x-cloak @keydown.escape.window="closePanel()">

    <header class="rs-toolbar">
        <a class="rs-back" x-bind:href="config.cmsUrl" title="CMS">&larr;</a>
        <h1 class="rs-title">$Title</h1>

        <div class="rs-month">
            <button type="button" class="rs-btn rs-btn-ghost" @click="goMonth(data.period.prev)" aria-label="<%t YouWillLikeIT\\CareHome\\Roster\\Board.PREV "Prethodni mjesec" %>">&lsaquo;</button>
            <strong x-text="data.period ? data.period.label : ''"></strong>
            <button type="button" class="rs-btn rs-btn-ghost" @click="goMonth(data.period.next)" aria-label="<%t YouWillLikeIT\\CareHome\\Roster\\Board.NEXT "Sljedeći mjesec" %>">&rsaquo;</button>
            <span class="rs-status" x-bind:class="'rs-status-' + (data.period ? data.period.status : '')" x-text="data.period ? data.period.statusLabel : ''"></span>
        </div>

        <select class="rs-select" x-model="group" @change="reload(true)" aria-label="<%t YouWillLikeIT\\CareHome\\Roster\\Board.GROUP "Grupa" %>">
            <template x-for="g in data.groups" x-bind:key="g.id"><option x-bind:value="g.id" x-text="g.title"></option></template>
        </select>
        <select class="rs-select" x-model.number="zone" @change="reload(true)" aria-label="<%t YouWillLikeIT\\CareHome\\Roster\\Board.ZONE "Zona" %>">
            <template x-for="z in data.zones" x-bind:key="z.id"><option x-bind:value="z.id" x-text="z.title"></option></template>
        </select>

        <div class="rs-actions">
            <button type="button" class="rs-btn" @click="openTool('copy')" x-show="!locked"><%t YouWillLikeIT\\CareHome\\Roster\\Board.COPY "Kopiraj" %></button>
            <button type="button" class="rs-btn" @click="openTool('pattern')" x-show="!locked"><%t YouWillLikeIT\\CareHome\\Roster\\Board.PATTERN "Rotacija" %></button>
            <button type="button" class="rs-btn" @click="openTool('addopen')" x-show="!locked"><%t YouWillLikeIT\\CareHome\\Roster\\Board.ADD_OPEN "Otvorena smjena" %></button>
            <a class="rs-btn" x-bind:href="data.links ? data.links.pdf : '#'" target="_blank" rel="noopener">PDF</a>
            <a class="rs-btn" x-bind:href="data.links ? data.links.report : '#'" target="_blank" rel="noopener"><%t YouWillLikeIT\\CareHome\\Roster\\Board.HOURS "Sati" %></a>
            <button type="button" class="rs-btn rs-btn-primary" x-show="data.period && data.period.status === 'Draft'" @click="periodAction('publish', '<%t YouWillLikeIT\\CareHome\\Roster\\Board.CONFIRM_PUBLISH "Objaviti raspored? Radnici dobijaju obavještenje." %>')"><%t YouWillLikeIT\\CareHome\\Roster\\Board.PUBLISH "Objavi" %></button>
            <button type="button" class="rs-btn" x-show="data.period && data.period.status === 'Published'" @click="periodAction('lock', '<%t YouWillLikeIT\\CareHome\\Roster\\Board.CONFIRM_LOCK "Zaključati mjesec? Nakon toga izmjene nisu moguće." %>')"><%t YouWillLikeIT\\CareHome\\Roster\\Board.LOCK "Zaključaj" %></button>
            <button type="button" class="rs-btn" x-show="locked && config.isSuperAdmin" @click="openTool('unlock')"><%t YouWillLikeIT\\CareHome\\Roster\\Board.UNLOCK "Otključaj" %></button>
        </div>
    </header>

    <div class="rs-legend">
        <template x-for="t in data.types" x-bind:key="t.id">
            <span class="rs-chip" x-bind:style="chipStyle(t)" x-bind:title="t.title + ' ' + t.time + (t.mismatch ? ' – ' + t.mismatch : '')">
                <span x-text="t.code"></span> <small x-text="t.time"></small><span x-show="t.mismatch"> ⚠</span>
            </span>
        </template>
        <span class="rs-legend-note"><%t YouWillLikeIT\\CareHome\\Roster\\Board.LEGEND "● izmjena nakon objave · ! odstupanje od pravila · ⇄ zamjena · prugasto = zahtjev na čekanju" %></span>
    </div>

    <div class="rs-flash" x-show="flash.text" x-bind:class="'rs-flash-' + flash.type" role="status">
        <span x-text="flash.text"></span>
        <ul x-show="flash.list.length"><template x-for="line in flash.list"><li x-text="line"></li></template></ul>
        <button type="button" class="rs-flash-close" @click="flash.text = ''" aria-label="×">×</button>
    </div>

    <div class="rs-loading" x-show="loading"><%t YouWillLikeIT\\CareHome\\Roster\\Board.LOADING "Učitavam…" %></div>

    <div class="rs-grid-wrap" x-ref="scroller">
        <table class="rs-grid" x-show="data.rows">
            <thead>
            <tr>
                <th class="rs-name-col"><%t YouWillLikeIT\\CareHome\\Roster\\Board.WORKER "Radnik" %></th>
                <template x-for="day in data.days" x-bind:key="day.ymd">
                    <th x-bind:class="dayClass(day)" x-bind:title="day.holiday || ''">
                        <span class="rs-day-num" x-text="day.d"></span>
                        <span class="rs-day-wd" x-text="day.wd"></span>
                    </th>
                </template>
                <th class="rs-sum-col"><%t YouWillLikeIT\\CareHome\\Roster\\Board.SUM "h" %></th>
            </tr>
            </thead>
            <tbody>
            <template x-for="row in data.rows" x-bind:key="row.id">
                <tr>
                    <th class="rs-name-col" scope="row">
                        <span class="rs-name" x-text="row.name"></span>
                        <span class="rs-qual" x-text="row.qual"></span>
                        <span class="rs-warn-badge" x-show="row.contractEnds" x-bind:title="'<%t YouWillLikeIT\\CareHome\\Roster\\Board.CONTRACT_ENDS "Ugovor ističe" %> ' + row.contractEnds">⏳</span>
                    </th>
                    <template x-for="day in data.days" x-bind:key="row.id + day.ymd">
                        <td x-bind:class="cellClass(row, day)" @click="openCell(row, day)" x-bind:title="cellTitle(row, day)">
                            <template x-if="row.cells[day.ymd].absence">
                                <span class="rs-absence" x-bind:class="{ 'rs-pending': row.cells[day.ymd].absence.pending, 'rs-wish': row.cells[day.ymd].absence.wish }"
                                      x-bind:style="chipStyle(row.cells[day.ymd].absence)" x-text="row.cells[day.ymd].absence.code"></span>
                            </template>
                            <template x-for="item in row.cells[day.ymd].items" x-bind:key="item.id">
                                <span class="rs-shift" x-bind:style="chipStyle(item)">
                                    <span x-text="item.code"></span><span class="rs-mark" x-show="item.changed">●</span><span class="rs-mark" x-show="item.override">!</span><span class="rs-mark" x-show="item.sub">⇄</span>
                                </span>
                            </template>
                        </td>
                    </template>
                    <td class="rs-sum-col" x-bind:title="'<%t YouWillLikeIT\\CareHome\\Roster\\Board.WEEKLY "Ugovor sedmično" %>: ' + row.weekly + ' h'" x-text="row.hours"></td>
                </tr>
            </template>
            <tr class="rs-open-row">
                <th class="rs-name-col" scope="row"><%t YouWillLikeIT\\CareHome\\Roster\\Board.OPEN "Otvorene smjene" %></th>
                <template x-for="day in data.days" x-bind:key="'open' + day.ymd">
                    <td x-bind:class="dayClass(day)">
                        <template x-for="item in (data.open[day.ymd] || [])" x-bind:key="item.id">
                            <button type="button" class="rs-shift rs-open" x-bind:style="chipStyle(item)" @click="openSlot(item, day)" x-bind:title="item.time + ' · ' + item.reason" x-text="item.code"></button>
                        </template>
                    </td>
                </template>
                <td class="rs-sum-col"></td>
            </tr>
            <tr class="rs-cover-row">
                <th class="rs-name-col" scope="row"><%t YouWillLikeIT\\CareHome\\Roster\\Board.COVERAGE "Popunjenost" %></th>
                <template x-for="day in data.days" x-bind:key="'cov' + day.ymd">
                    <td x-bind:class="dayClass(day)">
                        <span class="rs-short" x-show="(data.shortfalls[day.ymd] || []).length" x-bind:title="(data.shortfalls[day.ymd] || []).join('\n')">⚠<span x-text="(data.shortfalls[day.ymd] || []).length"></span></span>
                        <span class="rs-ok" x-show="!(data.shortfalls[day.ymd] || []).length">✓</span>
                    </td>
                </template>
                <td class="rs-sum-col"></td>
            </tr>
            </tbody>
        </table>
        <p class="rs-empty" x-show="data.rows && !data.rows.length"><%t YouWillLikeIT\\CareHome\\Roster\\Board.NO_WORKERS "Nema radnika u ovoj grupi/zoni. Uloga i zaposlenje se postavljaju na korisniku (Sigurnost)." %></p>
    </div>

    <%-- side panel --%>
    <div class="rs-backdrop" x-show="panel" @click="closePanel()"></div>
    <aside class="rs-panel" x-show="panel" x-transition role="dialog" aria-modal="true">
        <button type="button" class="rs-panel-close" @click="closePanel()" aria-label="×">×</button>

        <%-- cell editor --%>
        <template x-if="panel === 'cell'">
            <div>
                <h2 x-text="ctx.row.name"></h2>
                <p class="rs-sub" x-text="ctx.dayLabel"></p>

                <template x-if="ctx.cell.absence">
                    <p class="rs-note" x-bind:style="chipStyle(ctx.cell.absence)" x-text="ctx.cell.absence.title"></p>
                </template>
                <p class="rs-note rs-note-warn" x-show="!ctx.cell.employed"><%t YouWillLikeIT\\CareHome\\Roster\\Board.NOT_EMPLOYED "Radnik nije zaposlen na ovaj dan." %></p>

                <fieldset class="rs-types" x-bind:disabled="locked">
                    <legend><%t YouWillLikeIT\\CareHome\\Roster\\Board.SHIFTS "Smjene" %></legend>
                    <template x-for="t in data.types" x-bind:key="t.id">
                        <label class="rs-type-option">
                            <input type="checkbox" x-bind:value="t.id" x-model.number="ctx.selected">
                            <span class="rs-chip" x-bind:style="chipStyle(t)" x-text="t.code"></span>
                            <span x-text="t.title + ' ' + t.time"></span>
                        </label>
                    </template>
                </fieldset>

                <template x-if="ctx.cell.items.length">
                    <div class="rs-existing">
                        <h3><%t YouWillLikeIT\\CareHome\\Roster\\Board.EXISTING "Postojeće smjene" %></h3>
                        <template x-for="item in ctx.cell.items" x-bind:key="item.id">
                            <div class="rs-existing-item">
                                <span class="rs-chip" x-bind:style="chipStyle(item)" x-text="item.code + ' ' + item.time"></span>
                                <span class="rs-small" x-show="item.sub" x-text="'⇄ ' + item.sub"></span>
                                <span class="rs-small" x-show="item.override" x-text="'! ' + item.override"></span>
                                <div class="rs-row" x-show="!locked">
                                    <button type="button" class="rs-btn rs-btn-small" @click="openSlotForReplace(item)"><%t YouWillLikeIT\\CareHome\\Roster\\Board.REPLACE "Zamijeni radnika" %></button>
                                    <button type="button" class="rs-btn rs-btn-small" @click="startOpen(item)"><%t YouWillLikeIT\\CareHome\\Roster\\Board.MAKE_OPEN "Otvori (odsutan)" %></button>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                <div x-show="ctx.openItem" class="rs-form-block">
                    <label class="rs-label" for="rs-open-reason"><%t YouWillLikeIT\\CareHome\\Roster\\Board.OPEN_REASON "Razlog (npr. javio bolovanje)" %></label>
                    <input id="rs-open-reason" class="rs-input" type="text" x-model="ctx.openReason">
                    <button type="button" class="rs-btn rs-btn-primary" @click="confirmOpen()"><%t YouWillLikeIT\\CareHome\\Roster\\Board.MAKE_OPEN_CONFIRM "Otvori smjenu za zamjenu" %></button>
                </div>

                <% include YouWillLikeIT\\CareHome\\Roster\\Board\\ResultBlock %>

                <div class="rs-row rs-panel-footer" x-show="!locked">
                    <button type="button" class="rs-btn rs-btn-primary" @click="saveCell()"><%t YouWillLikeIT\\CareHome\\Roster\\Board.SAVE "Spremi" %></button>
                    <button type="button" class="rs-btn" @click="ctx.selected = []; saveCell()"><%t YouWillLikeIT\\CareHome\\Roster\\Board.FREE "Slobodan dan" %></button>
                </div>
            </div>
        </template>

        <%-- open slot / replacement --%>
        <template x-if="panel === 'slot'">
            <div>
                <h2>
                    <span class="rs-chip" x-bind:style="chipStyle(ctx.item)" x-text="ctx.item.code"></span>
                    <span x-text="ctx.dayLabel + ' · ' + ctx.item.time"></span>
                </h2>
                <p class="rs-sub" x-show="ctx.item.reason" x-text="ctx.item.reason"></p>
                <p class="rs-sub" x-show="ctx.item.original" x-text="'<%t YouWillLikeIT\\CareHome\\Roster\\Board.ORIGINAL "Prvobitno" %>: ' + ctx.item.original"></p>

                <p x-show="ctx.loadingCandidates"><%t YouWillLikeIT\\CareHome\\Roster\\Board.CHECKING "Provjeravam dostupnost…" %></p>
                <ul class="rs-candidates">
                    <template x-for="c in ctx.candidates" x-bind:key="c.id">
                        <li x-bind:class="'rs-cand-' + c.state">
                            <label>
                                <input type="radio" name="rs-candidate" x-bind:value="c.id" x-model.number="ctx.member" x-bind:disabled="c.state === 'block' || locked">
                                <strong x-text="c.name"></strong> <small x-text="c.qual"></small>
                            </label>
                            <small class="rs-cand-msg" x-show="c.messages.length" x-text="c.messages.join(' ')"></small>
                        </li>
                    </template>
                </ul>

                <% include YouWillLikeIT\\CareHome\\Roster\\Board\\ResultBlock %>

                <div class="rs-row rs-panel-footer" x-show="!locked">
                    <button type="button" class="rs-btn rs-btn-primary" x-bind:disabled="!ctx.member" @click="assign()"><%t YouWillLikeIT\\CareHome\\Roster\\Board.ASSIGN "Dodijeli" %></button>
                    <button type="button" class="rs-btn rs-btn-danger" x-show="ctx.isOpenSlot" @click="removeSlot()"><%t YouWillLikeIT\\CareHome\\Roster\\Board.DELETE_SLOT "Ukloni smjenu" %></button>
                </div>
            </div>
        </template>

        <%-- tools --%>
        <template x-if="panel === 'copy'">
            <div>
                <h2><%t YouWillLikeIT\\CareHome\\Roster\\Board.COPY_TITLE "Kopiranje plana" %></h2>
                <p class="rs-sub"><%t YouWillLikeIT\\CareHome\\Roster\\Board.COPY_DESC "Postojeće smjene se ne prepisuju. Kopira se trenutno odabrana grupa. Mjesec se kopira sa pomakom od 28 dana (isti dani u sedmici, rotacija 12-24-12-48 ostaje u ritmu)." %></p>
                <label class="rs-type-option"><input type="radio" value="month" x-model="tool.mode"> <%t YouWillLikeIT\\CareHome\\Roster\\Board.COPY_MONTH "Cijeli mjesec iz prethodnog" %></label>
                <label class="rs-type-option"><input type="radio" value="week" x-model="tool.mode"> <%t YouWillLikeIT\\CareHome\\Roster\\Board.COPY_WEEK "Jednu sedmicu iz prethodne" %></label>
                <div x-show="tool.mode === 'week'">
                    <label class="rs-label" for="rs-week"><%t YouWillLikeIT\\CareHome\\Roster\\Board.WEEK_OF "Sedmica koja sadrži datum" %></label>
                    <input id="rs-week" class="rs-input" type="date" x-model="tool.week">
                </div>
                <% include YouWillLikeIT\\CareHome\\Roster\\Board\\ResultBlock %>
                <div class="rs-row rs-panel-footer"><button type="button" class="rs-btn rs-btn-primary" @click="runCopy()"><%t YouWillLikeIT\\CareHome\\Roster\\Board.RUN "Izvrši" %></button></div>
            </div>
        </template>

        <template x-if="panel === 'pattern'">
            <div>
                <h2><%t YouWillLikeIT\\CareHome\\Roster\\Board.PATTERN_TITLE "Popuni rotacijom" %></h2>
                <label class="rs-label" for="rs-pattern"><%t YouWillLikeIT\\CareHome\\Roster\\Board.PATTERN_LABEL "Obrazac" %></label>
                <select id="rs-pattern" class="rs-input" x-model.number="tool.pattern">
                    <template x-for="p in data.patterns" x-bind:key="p.id"><option x-bind:value="p.id" x-text="p.title + ' (' + p.pattern + ')'"></option></template>
                </select>
                <label class="rs-label" for="rs-anchor"><%t YouWillLikeIT\\CareHome\\Roster\\Board.ANCHOR "Prvi dan obrasca (početak ciklusa)" %></label>
                <input id="rs-anchor" class="rs-input" type="date" x-model="tool.anchor">
                <div class="rs-row">
                    <div><label class="rs-label" for="rs-from"><%t YouWillLikeIT\\CareHome\\Roster\\Board.FROM "Od" %></label><input id="rs-from" class="rs-input" type="date" x-model="tool.from"></div>
                    <div><label class="rs-label" for="rs-to"><%t YouWillLikeIT\\CareHome\\Roster\\Board.TO "Do" %></label><input id="rs-to" class="rs-input" type="date" x-model="tool.to"></div>
                </div>
                <fieldset class="rs-members">
                    <legend><%t YouWillLikeIT\\CareHome\\Roster\\Board.MEMBERS "Radnici" %></legend>
                    <template x-for="row in data.rows" x-bind:key="'pm' + row.id">
                        <label class="rs-type-option"><input type="checkbox" x-bind:value="row.id" x-model.number="tool.members"> <span x-text="row.name"></span></label>
                    </template>
                </fieldset>
                <p class="rs-sub"><%t YouWillLikeIT\\CareHome\\Roster\\Board.PATTERN_DESC "Dani koji već imaju smjenu ili odsustvo se preskaču. Dani sa upozorenjem se preskaču, osim ako unesete razlog ispod." %></p>
                <% include YouWillLikeIT\\CareHome\\Roster\\Board\\ResultBlock %>
                <div class="rs-row rs-panel-footer"><button type="button" class="rs-btn rs-btn-primary" @click="runPattern()"><%t YouWillLikeIT\\CareHome\\Roster\\Board.RUN "Izvrši" %></button></div>
            </div>
        </template>

        <template x-if="panel === 'addopen'">
            <div>
                <h2><%t YouWillLikeIT\\CareHome\\Roster\\Board.ADD_OPEN_TITLE "Nova otvorena smjena" %></h2>
                <label class="rs-label" for="rs-ao-type"><%t YouWillLikeIT\\CareHome\\Roster\\Board.SHIFT "Smjena" %></label>
                <select id="rs-ao-type" class="rs-input" x-model.number="tool.type">
                    <template x-for="t in data.types" x-bind:key="t.id"><option x-bind:value="t.id" x-text="t.code + ' · ' + t.title + ' ' + t.time"></option></template>
                </select>
                <label class="rs-label" for="rs-ao-date"><%t YouWillLikeIT\\CareHome\\Roster\\Board.DATE "Datum" %></label>
                <input id="rs-ao-date" class="rs-input" type="date" x-model="tool.date">
                <% include YouWillLikeIT\\CareHome\\Roster\\Board\\ResultBlock %>
                <div class="rs-row rs-panel-footer"><button type="button" class="rs-btn rs-btn-primary" @click="runAddOpen()"><%t YouWillLikeIT\\CareHome\\Roster\\Board.RUN "Izvrši" %></button></div>
            </div>
        </template>

        <template x-if="panel === 'unlock'">
            <div>
                <h2><%t YouWillLikeIT\\CareHome\\Roster\\Board.UNLOCK_TITLE "Otključavanje mjeseca" %></h2>
                <p class="rs-sub"><%t YouWillLikeIT\\CareHome\\Roster\\Board.UNLOCK_DESC "Razlog se upisuje u audit log. Nakon izmjena mjesec ponovo zaključajte." %></p>
                <% include YouWillLikeIT\\CareHome\\Roster\\Board\\ResultBlock %>
                <div class="rs-row rs-panel-footer"><button type="button" class="rs-btn rs-btn-primary" @click="runUnlock()"><%t YouWillLikeIT\\CareHome\\Roster\\Board.UNLOCK "Otključaj" %></button></div>
            </div>
        </template>
    </aside>
</div>
</body>
</html>
