/* CareHome roster – planning board (Alpine component, no build step).
 * Loaded before the base module's bundle, which starts Alpine. */
(function () {
  'use strict';

  var emptyResult = function () {
    return { blocks: [], warnings: [], info: [] };
  };

  document.addEventListener('alpine:init', function () {
    window.Alpine.data('rosterBoard', function (config) {
      return {
        config: config,
        data: { groups: [], zones: [], days: [], types: [], rows: null, open: {}, shortfalls: {}, patterns: [], period: null, links: null },
        month: config.month,
        group: config.group,
        zone: config.zone || 0,
        loading: false,
        panel: null,
        ctx: {},
        tool: {},
        result: emptyResult(),
        flash: { text: '', type: 'info', list: [] },

        init: function () {
          this.reload(false);
        },

        get locked() {
          return !!(this.data.period && this.data.period.status === 'Locked');
        },

        /* ---------------------------------------------------------- data */

        reload: function (updateUrl) {
          var self = this;
          var query = '?m=' + encodeURIComponent(this.month) + '&g=' + encodeURIComponent(this.group) + '&z=' + encodeURIComponent(this.zone);
          this.loading = true;
          return fetch(this.config.dataUrl + query, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
              if (!response.ok) {
                throw new Error(String(response.status));
              }
              return response.json();
            })
            .then(function (json) {
              self.data = json;
              if (updateUrl !== false) {
                try {
                  window.history.replaceState(null, '', query);
                } catch (e) { /* ignore */ }
              }
            })
            .catch(function () {
              self.showFlash('error', 'Podaci se ne mogu učitati. Prijavite se ponovo ili osvježite stranicu.');
            })
            .finally(function () {
              self.loading = false;
            });
        },

        goMonth: function (ym) {
          if (!ym) {
            return;
          }
          this.month = ym;
          this.closePanel();
          this.reload(true);
        },

        post: function (action, fields) {
          var body = new FormData();
          body.append(this.config.token.name, this.config.token.value);
          body.append('m', this.month);
          body.append('g', this.group);
          Object.keys(fields || {}).forEach(function (key) {
            var value = fields[key];
            if (Array.isArray(value)) {
              value.forEach(function (v) {
                body.append(key + '[]', v);
              });
            } else if (value !== null && value !== undefined) {
              body.append(key, value);
            }
          });
          return fetch(this.config.actionBase.replace(/\/$/, '') + '/' + action, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
          }).then(function (response) {
            return response.json().catch(function () {
              return { saved: false, blocks: ['Neočekivan odgovor servera (' + response.status + ').'], warnings: [], info: [] };
            });
          }).catch(function () {
            return { saved: false, blocks: ['Server nije dostupan.'], warnings: [], info: [] };
          });
        },

        /**
         * Shows blocks/warnings in the panel. On success closes the panel (unless keepOpen)
         * and reloads the grid.
         */
        handle: function (promise, keepOpen) {
          var self = this;
          this.result = emptyResult();
          return promise.then(function (res) {
            self.result = { blocks: res.blocks || [], warnings: res.warnings || [], info: self.infoLines(res.info) };
            if (res.needsReason) {
              self.ctx.askReason = true;
              return res;
            }
            if (res.saved) {
              if (!keepOpen) {
                if (self.result.info.length) {
                  self.showFlash('success', 'Spremljeno.', self.result.info);
                }
                self.closePanel();
              }
              self.reload(false);
            }
            return res;
          });
        },

        infoLines: function (info) {
          if (!info) {
            return [];
          }
          if (Array.isArray(info)) {
            return info;
          }
          var lines = [];
          if (typeof info.created === 'number') {
            lines.push('Upisano smjena: ' + info.created);
          }
          (info.skipped || []).forEach(function (line) {
            lines.push('Preskočeno – ' + line);
          });
          Object.keys(info).forEach(function (key) {
            if (/^\d+$/.test(key)) {
              lines.push(info[key]);
            }
          });
          return lines;
        },

        showFlash: function (type, text, list) {
          this.flash = { type: type, text: text, list: list || [] };
        },

        /* ---------------------------------------------------------- view */

        chipStyle: function (o) {
          return o ? 'background:' + o.colour + ';color:' + o.text : '';
        },

        dayClass: function (day) {
          return {
            'rs-weekend': day.weekend,
            'rs-holiday': !!day.holiday,
            'rs-today': day.today,
          };
        },

        cellClass: function (row, day) {
          var cell = row.cells[day.ymd];
          var classes = this.dayClass(day);
          classes['rs-cell'] = true;
          classes['rs-not-employed'] = !cell.employed;
          classes['rs-has-absence'] = !!cell.absence && !cell.absence.wish;
          classes['rs-conflict'] = !!cell.absence && !cell.absence.wish && !cell.absence.pending && cell.items.length > 0;
          return classes;
        },

        cellTitle: function (row, day) {
          var cell = row.cells[day.ymd];
          var parts = [];
          if (day.holiday) {
            parts.push(day.holiday);
          }
          if (cell.absence) {
            parts.push(cell.absence.title);
          }
          cell.items.forEach(function (item) {
            parts.push(item.code + ' ' + item.time + (item.sub ? ' (zamjena za ' + item.sub + ')' : '') + (item.override ? ' – ! ' + item.override : ''));
          });
          return parts.join('\n');
        },

        dayLabel: function (day) {
          var parts = day.ymd.split('-');
          return day.wd + ', ' + parts[2] + '.' + parts[1] + '.' + parts[0] + '.' + (day.holiday ? ' – ' + day.holiday : '');
        },

        /* --------------------------------------------------------- panels */

        closePanel: function () {
          this.panel = null;
          this.ctx = {};
          this.result = emptyResult();
        },

        openCell: function (row, day) {
          var cell = row.cells[day.ymd];
          this.result = emptyResult();
          this.ctx = {
            row: row,
            day: day,
            dayLabel: this.dayLabel(day),
            cell: cell,
            selected: cell.items.map(function (item) { return item.type; }),
            reason: '',
            askReason: false,
            openItem: null,
            openReason: '',
          };
          this.panel = 'cell';
        },

        saveCell: function () {
          this.handle(this.post('cell', {
            member: this.ctx.row.id,
            date: this.ctx.day.ymd,
            types: this.ctx.selected,
            reason: this.ctx.reason,
          }));
        },

        startOpen: function (item) {
          this.ctx.openItem = item;
          this.ctx.openReason = '';
        },

        confirmOpen: function () {
          this.handle(this.post('open', { assignment: this.ctx.openItem.id, reason: this.ctx.openReason }));
        },

        openSlot: function (item, day) {
          this.showSlot(item, this.dayLabel(day), true);
        },

        openSlotForReplace: function (item) {
          this.showSlot(item, this.ctx.dayLabel, false);
        },

        showSlot: function (item, dayLabel, isOpenSlot) {
          var self = this;
          this.result = emptyResult();
          this.ctx = {
            item: item,
            dayLabel: dayLabel,
            isOpenSlot: isOpenSlot,
            candidates: [],
            loadingCandidates: true,
            member: null,
            reason: '',
            askReason: false,
          };
          this.panel = 'slot';
          fetch(this.config.actionBase.replace(/\/$/, '') + '/candidates?assignment=' + item.id, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (json) { self.ctx.candidates = json.candidates || []; })
            .catch(function () { self.result.blocks = ['Dostupnost se ne može provjeriti.']; })
            .finally(function () { self.ctx.loadingCandidates = false; });
        },

        assign: function () {
          this.handle(this.post('assign', { assignment: this.ctx.item.id, member: this.ctx.member, reason: this.ctx.reason }));
        },

        removeSlot: function () {
          if (!window.confirm('Ukloniti ovu otvorenu smjenu iz rasporeda?')) {
            return;
          }
          this.handle(this.post('delete', { assignment: this.ctx.item.id }));
        },

        /* ---------------------------------------------------------- tools */

        openTool: function (name) {
          var first = this.month + '-01';
          var last = this.data.days.length ? this.data.days[this.data.days.length - 1].ymd : first;
          this.result = emptyResult();
          this.ctx = { reason: '', askReason: name === 'pattern' || name === 'unlock' };
          this.tool = {
            mode: 'month',
            week: first,
            pattern: this.data.patterns.length ? this.data.patterns[0].id : null,
            anchor: first,
            from: first,
            to: last,
            members: [],
            type: this.data.types.length ? this.data.types[0].id : null,
            date: this.data.today && this.data.today.indexOf(this.month) === 0 ? this.data.today : first,
          };
          this.panel = name;
        },

        runCopy: function () {
          this.handle(this.post('copy', { mode: this.tool.mode, week: this.tool.week, reason: this.ctx.reason }), true);
        },

        runPattern: function () {
          this.handle(this.post('pattern', {
            pattern: this.tool.pattern,
            anchor: this.tool.anchor,
            from: this.tool.from,
            to: this.tool.to,
            members: this.tool.members,
            reason: this.ctx.reason,
          }), true);
        },

        runAddOpen: function () {
          this.handle(this.post('addopen', { type: this.tool.type, date: this.tool.date }));
        },

        runUnlock: function () {
          this.handle(this.post('unlock', { reason: this.ctx.reason }));
        },

        periodAction: function (action, question) {
          var self = this;
          if (!window.confirm(question)) {
            return;
          }
          this.post(action, {}).then(function (res) {
            if (res.saved) {
              self.showFlash('success', 'Spremljeno.', self.infoLines(res.info));
              self.reload(false);
            } else {
              self.showFlash('error', (res.blocks || []).join(' ') || 'Greška.');
            }
          });
        },
      };
    });
  });
})();
