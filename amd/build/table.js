// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * The interactive report table: instant search, type-aware column sorting,
 * faceted per-column filters, active-filter chips, live count and CSV export.
 *
 * Runs entirely client-side over the rows already rendered by the server, so
 * every interaction is instant and nothing depends on a round trip.
 *
 * @module     local_beacon/table
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('local_beacon/table', ['core/str'], function(Str) {

    // Module-level translated strings. Populated before any Table is constructed.
    // Fallback values are English so the table still works if get_strings fails.
    var STR = {
        drilltofilter: 'Filter to this',
        removefilter:  'Remove filter',
        clearselection: 'Clear',
        applyfilters:   'Apply',
    };

    var esc = function(s) {
        return String(s).replace(/[&<>"]/g, function(c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c];
        });
    };

    var Table = function(root) {
        this.root = root;
        this.table = root.querySelector('[data-role="table"]');
        if (!this.table) {
            return;
        }
        this.headers = Array.prototype.slice.call(this.table.querySelectorAll('thead th'));
        this.tbody = this.table.querySelector('tbody');
        this.rows = Array.prototype.slice.call(this.tbody.querySelectorAll('tr')).map(function(tr) {
            var cells = Array.prototype.slice.call(tr.children).map(function(td) {
                return {
                    el: td,
                    text: (td.textContent || '').trim(),
                    lower: (td.textContent || '').trim().toLowerCase(),
                    sort: td.getAttribute('data-sort')
                };
            });
            return {tr: tr, cells: cells, blob: cells.map(function(c) {
                return c.lower;
            }).join('  ')};
        });
        this.query = '';
        this.filters = {}; // Index -> array of selected values
        this.hiddenCols = {}; // Index -> true when hidden
        this.pinned = false;
        this.selected = new Set(); // Selected row objects
        this.sortIndex = -1;
        this.sortDir = 0; // 1 asc, -1 desc
        this.openMenu = null;
        this.injectSelectColumn();
        this.markDrillCells();
        this.bind();
        this.apply();
    };

    Table.prototype.bind = function() {
        var self = this;

        var search = this.root.querySelector('[data-role="search"]');
        if (search) {
            search.addEventListener('input', function() {
                self.query = search.value.trim().toLowerCase();
                self.apply();
            });
        }

        this.headers.forEach(function(th) {
            var sortBtn = th.querySelector('[data-role="sort"]');
            if (sortBtn) {
                sortBtn.addEventListener('click', function() {
                    self.toggleSort(parseInt(th.getAttribute('data-index'), 10), th);
                });
            }
            var filterBtn = th.querySelector('[data-role="filter"]');
            if (filterBtn) {
                filterBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    self.showFilter(parseInt(th.getAttribute('data-index'), 10), th, filterBtn);
                });
            }
        });

        var clear = this.root.querySelector('[data-role="clear"]');
        if (clear) {
            clear.addEventListener('click', function() {
                self.filters = {};
                self.query = '';
                if (search) {
                    search.value = '';
                }
                self.apply();
            });
        }

        var exportBtn = this.root.querySelector('[data-role="export"]');
        if (exportBtn) {
            exportBtn.addEventListener('click', function() {
                self.exportCsv();
            });
        }

        var colsBtn = this.root.querySelector('[data-role="columns"]');
        if (colsBtn) {
            colsBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                self.showColumns(colsBtn);
            });
        }

        var expSel = this.root.querySelector('[data-role="exportselected"]');
        if (expSel) {
            expSel.addEventListener('click', function() {
                self.exportSelected();
            });
        }
        var mailSel = this.root.querySelector('[data-role="emailselected"]');
        if (mailSel) {
            mailSel.addEventListener('click', function() {
                self.emailSelected();
            });
        }
        var clearSel = this.root.querySelector('[data-role="clearselection"]');
        if (clearSel) {
            clearSel.addEventListener('click', function() {
                self.clearSelection();
            });
        }

        document.addEventListener('click', function() {
            self.closeMenu();
        });
    };

    Table.prototype.toggleSort = function(index, th) {
        if (this.sortIndex === index) {
            this.sortDir = this.sortDir === 1 ? -1 : (this.sortDir === -1 ? 0 : 1); // eslint-disable-line no-nested-ternary
        } else {
            this.sortIndex = index;
            this.sortDir = 1;
        }
        this.headers.forEach(function(h) {
            h.removeAttribute('data-sortdir');
        });
        if (this.sortDir !== 0) {
            th.setAttribute('data-sortdir', this.sortDir === 1 ? 'asc' : 'desc');
        } else {
            this.sortIndex = -1;
        }
        this.apply();
    };

    Table.prototype.rowPasses = function(row) {
        if (this.query && row.blob.indexOf(this.query) === -1) {
            return false;
        }
        for (var idx in this.filters) {
            if (!this.filters.hasOwnProperty(idx)) {
                continue;
            }
            var selected = this.filters[idx];
            if (selected.length && selected.indexOf(row.cells[idx].text) === -1) {
                return false;
            }
        }
        return true;
    };

    Table.prototype.apply = function() {
        var self = this;
        var visible = this.rows.filter(function(r) {
            return self.rowPasses(r);
        });

        if (this.sortIndex >= 0 && this.sortDir !== 0) {
            var idx = this.sortIndex, dir = this.sortDir; // eslint-disable-line one-var-declaration-per-line
            var numeric = this.headers[idx] &&
                (this.headers[idx].getAttribute('data-type') === 'number' ||
                 this.headers[idx].getAttribute('data-type') === 'date');
            visible.sort(function(a, b) {
                var av = a.cells[idx].sort, bv = b.cells[idx].sort; // eslint-disable-line one-var-declaration-per-line
                if (numeric) {
                    return (parseFloat(av || 0) - parseFloat(bv || 0)) * dir;
                }
                av = (av || a.cells[idx].lower);
                bv = (bv || b.cells[idx].lower);
                return av < bv ? -dir : (av > bv ? dir : 0); // eslint-disable-line no-nested-ternary
            });
        }

        // Re-order the DOM: detach then append the visible rows in order.
        this.rows.forEach(function(r) {
            r.tr.style.display = 'none';
        });
        var frag = document.createDocumentFragment();
        visible.forEach(function(r) {
            r.tr.style.display = '';
            frag.appendChild(r.tr);
        });
        this.tbody.appendChild(frag);

        this.visibleRows = visible;
        this.updateCount(visible.length);
        this.renderChips();
        this.renderDist();
        this.syncSelectAll();

        var empty = this.root.querySelector('[data-role="empty"]');
        if (empty) {
            empty.hidden = visible.length !== 0;
        }
    };

    Table.prototype.updateCount = function(shown) {
        var el = this.root.querySelector('[data-role="rowcount"]');
        if (el) {
            var parts = el.textContent.split(' ');
            var total = parts[parts.length - 1];
            el.textContent = shown + ' / ' + total;
        }
        var clear = this.root.querySelector('[data-role="clear"]');
        if (clear) {
            clear.hidden = !(this.query || Object.keys(this.filters).some(function(k) {
                return this.filters[k].length;
            }, this));
        }
    };

    Table.prototype.renderChips = function() {
        var wrap = this.root.querySelector('[data-role="chips"]');
        if (!wrap) {
            return;
        }
        var self = this;
        wrap.innerHTML = '';
        Object.keys(this.filters).forEach(function(idx) {
            self.filters[idx].forEach(function(val) {
                var chip = document.createElement('span');
                chip.className = 'bc-chip';
                chip.innerHTML = esc(val) + '<button type="button" aria-label="' + esc(STR.removefilter) + '">' +
                    '<svg viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" ' +
                    'stroke-width="2.4" stroke-linecap="round"/></svg></button>';
                chip.querySelector('button').addEventListener('click', function() {
                    self.filters[idx] = self.filters[idx].filter(function(v) {
                        return v !== val;
                    });
                    if (!self.filters[idx].length) {
                        delete self.filters[idx];
                    }
                    self.markFilterButtons();
                    self.apply();
                });
                wrap.appendChild(chip);
            });
        });
    };

    Table.prototype.markFilterButtons = function() {
        var self = this;
        this.headers.forEach(function(th) {
            var i = th.getAttribute('data-index');
            var btn = th.querySelector('[data-role="filter"]');
            if (btn) {
                btn.classList.toggle('active', !!(self.filters[i] && self.filters[i].length));
            }
        });
    };

    Table.prototype.showFilter = function(index, th, btn) {
        this.closeMenu();
        var self = this;

        // Distinct values with counts, computed over rows passing OTHER filters.
        var counts = {};
        this.rows.forEach(function(r) {
            var ok = true;
            for (var idx in self.filters) {
                if (self.filters.hasOwnProperty(idx) && parseInt(idx, 10) !== index) {
                    var sel = self.filters[idx];
                    if (sel.length && sel.indexOf(r.cells[idx].text) === -1) {
                        ok = false;
                        break;
                    }
                }
            }
            if (ok) {
                var v = r.cells[index].text;
                counts[v] = (counts[v] || 0) + 1;
            }
        });
        var values = Object.keys(counts).sort();
        var selected = this.filters[index] || [];

        var menu = document.createElement('div');
        menu.className = 'bc-filter-menu';
        var sortbtn = th.querySelector('[data-role="sort"]');
        var collabel = sortbtn ? sortbtn.textContent.trim() : '';
        var html = '<div class="bc-filter-title">' + esc(collabel) + '</div>';
        if (values.length > 8) {
            html += '<input type="text" class="bc-filter-search" placeholder="Filter values…">';
        }
        html += '<div class="bc-facets">';
        values.forEach(function(v) {
            var on = selected.indexOf(v) !== -1;
            html += '<label class="bc-facet"><input type="checkbox" value="' + esc(v) + '"' +
                (on ? ' checked' : '') + '><span class="bc-facet-v">' + (esc(v) || '&mdash;') +
                '</span><span class="bc-facet-c">' + counts[v] + '</span></label>';
        });
        html += '</div><div class="bc-filter-foot">' +
            '<button type="button" class="bc-filter-clear" data-act="none">' + esc(STR.clearselection) + '</button>' +
            '<button type="button" class="bc-filter-apply" data-act="apply">' + esc(STR.applyfilters) + '</button></div>';
        menu.innerHTML = html;

        // Anchor inside .bc-root so the menu inherits Beacon's design tokens and
        // is covered by the button firewall (appending to <body> loses both).
        (self.root.closest('.bc-root') || document.body).appendChild(menu);
        var rect = btn.getBoundingClientRect();
        menu.style.position = 'fixed';
        menu.style.top = (rect.bottom + 6) + 'px';
        menu.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 320)) + 'px';
        menu.addEventListener('click', function(e) {
            e.stopPropagation();
        });

        var fsearch = menu.querySelector('.bc-filter-search');
        if (fsearch) {
            fsearch.addEventListener('input', function() {
                var q = fsearch.value.toLowerCase();
                menu.querySelectorAll('.bc-facet').forEach(function(f) {
                    f.style.display = f.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
                });
            });
            fsearch.focus();
        }

        var applyFilter = function() {
            var chosen = [];
            menu.querySelectorAll('.bc-facet input:checked').forEach(function(cb) {
                chosen.push(cb.value);
            });
            if (chosen.length) {
                self.filters[index] = chosen;
            } else {
                delete self.filters[index];
            }
            self.markFilterButtons();
            self.closeMenu();
            self.apply();
        };

        menu.querySelector('[data-act="apply"]').addEventListener('click', applyFilter);
        menu.querySelector('[data-act="none"]').addEventListener('click', function() {
            menu.querySelectorAll('.bc-facet input').forEach(function(cb) {
                cb.checked = false;
            });
            applyFilter();
        });

        this.openMenu = menu;
    };

    Table.prototype.setCol = function(index, visible) {
        this.hiddenCols[index] = !visible;
        var disp = visible ? '' : 'none';
        if (this.headers[index]) {
            this.headers[index].style.display = disp;
        }
        this.rows.forEach(function(r) {
            if (r.cells[index] && r.cells[index].el) {
                r.cells[index].el.style.display = disp;
            }
        });
    };

    Table.prototype.setPinned = function(on) {
        this.pinned = on;
        this.table.classList.toggle('bc-pinned', on);
    };

    Table.prototype.showColumns = function(btn) {
        this.closeMenu();
        var self = this;
        var menu = document.createElement('div');
        menu.className = 'bc-filter-menu';
        var html = '<div class="bc-filter-title">Columns</div>';
        html += '<label class="bc-facet"><input type="checkbox" data-pin' +
            (this.pinned ? ' checked' : '') + '><span class="bc-facet-v">Pin first column</span></label>';
        html += '<div class="bc-facets">';
        this.headers.forEach(function(th, i) {
            var b = th.querySelector('[data-role="sort"]');
            var label = (b ? b.textContent : th.textContent).trim();
            html += '<label class="bc-facet"><input type="checkbox" data-col="' + i + '"' +
                (self.hiddenCols[i] ? '' : ' checked') + '><span class="bc-facet-v">' +
                esc(label) + '</span></label>';
        });
        html += '</div>';
        menu.innerHTML = html;
        (self.root.closest('.bc-root') || document.body).appendChild(menu);
        var rect = btn.getBoundingClientRect();
        menu.style.position = 'fixed';
        menu.style.top = (rect.bottom + 6) + 'px';
        menu.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 300)) + 'px';
        menu.addEventListener('click', function(e) {
            e.stopPropagation();
        });
        menu.querySelectorAll('[data-col]').forEach(function(cb) {
            cb.addEventListener('change', function() {
                self.setCol(parseInt(cb.getAttribute('data-col'), 10), cb.checked);
            });
        });
        var pin = menu.querySelector('[data-pin]');
        if (pin) {
            pin.addEventListener('change', function() {
                self.setPinned(pin.checked);
            });
        }
        this.openMenu = menu;
    };

    // ---- Row selection & bulk actions ----

    Table.prototype.injectSelectColumn = function() {
        var self = this;
        var headRow = this.table.querySelector('thead tr');
        if (!headRow || !this.rows.length) {
            return;
        }
        var th = document.createElement('th');
        th.className = 'bc-selcol';
        var sa = document.createElement('input');
        sa.type = 'checkbox';
        sa.setAttribute('data-selall', '');
        sa.setAttribute('aria-label', 'Select all');
        th.appendChild(sa);
        headRow.insertBefore(th, headRow.firstChild);
        this.selectAllBox = sa;
        sa.addEventListener('change', function() {
            self.selectVisible(sa.checked);
        });
        this.rows.forEach(function(r) {
            var td = document.createElement('td');
            td.className = 'bc-selcol';
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            td.appendChild(cb);
            r.tr.insertBefore(td, r.tr.firstChild);
            r.check = cb;
            cb.addEventListener('change', function() {
                self.setRowSelected(r, cb.checked);
                self.updateBulk();
            });
        });
    };

    Table.prototype.setRowSelected = function(r, on) {
        if (on) {
            this.selected.add(r);
        } else {
            this.selected.delete(r);
        }
        if (r.check) {
            r.check.checked = on;
        }
        r.tr.classList.toggle('bc-selrow', on);
    };

    Table.prototype.selectVisible = function(on) {
        var self = this;
        (this.visibleRows || []).forEach(function(r) {
            self.setRowSelected(r, on);
        });
        this.updateBulk();
    };

    Table.prototype.clearSelection = function() {
        var self = this;
        Array.prototype.slice.call(this.selected).forEach(function(r) {
            self.setRowSelected(r, false);
        });
        this.updateBulk();
    };

    Table.prototype.syncSelectAll = function() {
        if (!this.selectAllBox) {
            return;
        }
        var vis = this.visibleRows || [];
        var selVis = vis.filter(function(r) {
            return r.check && r.check.checked;
        }).length;
        this.selectAllBox.checked = vis.length > 0 && selVis === vis.length;
        this.selectAllBox.indeterminate = selVis > 0 && selVis < vis.length;
    };

    Table.prototype.updateBulk = function() {
        var bar = this.root.querySelector('[data-role="bulkbar"]');
        if (!bar) {
            return;
        }
        var n = this.selected.size;
        bar.hidden = n === 0;
        var count = bar.querySelector('[data-role="selcount"]');
        if (count) {
            count.textContent = n;
        }
        this.syncSelectAll();
    };

    Table.prototype.emailColumnIndex = function() {
        // The column whose header says "email", else one whose values look like addresses.
        for (var i = 0; i < this.headers.length; i++) {
            var b = this.headers[i].querySelector('[data-role="sort"]');
            var label = (b ? b.textContent : this.headers[i].textContent).toLowerCase();
            if (label.indexOf('email') !== -1) {
                return i;
            }
        }
        for (var j = 0; j < this.headers.length; j++) {
            var hit = this.rows.some(function(r) { // eslint-disable-line no-loop-func
                return r.cells[j] && /@/.test(r.cells[j].text);
            });
            if (hit) {
                return j;
            }
        }
        return -1;
    };

    Table.prototype.selectedRows = function() {
        var self = this;
        // Preserve on-screen order.
        return (this.visibleRows || this.rows).filter(function(r) {
            return self.selected.has(r);
        });
    };

    Table.prototype.exportSelected = function() {
        var rows = this.selectedRows();
        if (!rows.length) {
            return;
        }
        var head = this.headers.map(function(th) {
            var b = th.querySelector('[data-role="sort"]');
            return (b ? b.textContent : th.textContent).trim();
        });
        var lines = [head];
        rows.forEach(function(r) {
            lines.push(r.cells.map(function(c) {
                return c.text;
            }));
        });
        var csv = lines.map(function(row) {
            return row.map(function(v) {
                v = String(v).replace(/"/g, '""');
                return /[",\n]/.test(v) ? '"' + v + '"' : v;
            }).join(',');
        }).join('\n');
        var blob = new Blob(['﻿' + csv], {type: 'text/csv;charset=utf-8;'});
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'beacon-selected.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.setTimeout(function() {
            URL.revokeObjectURL(a.href);
        }, 1000);
    };

    Table.prototype.emailSelected = function() {
        var rows = this.selectedRows();
        if (!rows.length) {
            return;
        }
        var idx = this.emailColumnIndex();
        if (idx === -1) {
            window.alert('This report has no email column to build a message from.'); // eslint-disable-line no-alert
            return;
        }
        var emails = [];
        rows.forEach(function(r) {
            var v = r.cells[idx] ? r.cells[idx].text : '';
            if (/@/.test(v) && emails.indexOf(v) === -1) {
                emails.push(v);
            }
        });
        if (!emails.length) {
            return;
        }
        // Opens the user's own mail client with recipients in BCC — Beacon never
        // sends mass mail itself, so the admin stays fully in control.
        window.location.href = 'mailto:?bcc=' + encodeURIComponent(emails.join(','));
    };

    // ---- Click-to-filter (drill) ----

    Table.prototype.markDrillCells = function() {
        var self = this;
        this.headers.forEach(function(th, i) {
            if (!th.classList.contains('bc-filterable')) {
                return;
            }
            self.rows.forEach(function(r) {
                var c = r.cells[i];
                if (!c || !c.el) {
                    return;
                }
                c.el.classList.add('bc-drill');
                c.el.title = STR.drilltofilter;
                c.el.addEventListener('click', function() {
                    if (window.getSelection && String(window.getSelection()).length) {
                        return; // Don't hijack a text selection.
                    }
                    self.filters[i] = [c.text];
                    self.markFilterButtons();
                    self.apply();
                });
            });
        });
    };

    // ---- Distribution mini-bar ----

    Table.prototype.renderDist = function() {
        var host = this.root.querySelector('[data-role="dist"]');
        if (!host) {
            return;
        }
        // Use the first status column (badged cells) for a meaningful breakdown.
        var col = -1;
        for (var i = 0; i < this.headers.length; i++) {
            if (this.headers[i].getAttribute('data-type') === 'status') {
                col = i;
                break;
            }
        }
        var rows = this.visibleRows || [];
        if (col === -1 || !rows.length) {
            host.hidden = true;
            return;
        }
        var order = [];
        var counts = {};
        var tones = {};
        rows.forEach(function(r) {
            var cell = r.cells[col];
            var key = cell ? cell.text : '';
            if (!(key in counts)) {
                counts[key] = 0;
                order.push(key);
                var badge = cell && cell.el ? cell.el.querySelector('.bc-badge') : null;
                var tone = 'n';
                if (badge) {
                    if (badge.classList.contains('bc-badge-g')) {
                        tone = 'g';
                    } else if (badge.classList.contains('bc-badge-w')) {
                        tone = 'w';
                    } else if (badge.classList.contains('bc-badge-b')) {
                        tone = 'b';
                    }
                }
                tones[key] = tone;
            }
            counts[key]++;
        });
        var total = rows.length;
        var seg = order.map(function(k) {
            var pct = Math.round(1000 * counts[k] / total) / 10;
            return '<span class="bc-dist-seg bc-dist-' + tones[k] + '" style="width:' + pct +
                '%" title="' + esc(k) + ': ' + counts[k] + '"></span>';
        }).join('');
        var legend = order.map(function(k) {
            return '<span class="bc-dist-leg"><span class="bc-dist-dot bc-dist-' + tones[k] +
                '"></span>' + esc(k) + ' ' + counts[k] + '</span>';
        }).join('');
        host.innerHTML = '<div class="bc-dist-bar">' + seg + '</div><div class="bc-dist-legend">' +
            legend + '</div>';
        host.hidden = false;
    };

    Table.prototype.closeMenu = function() {
        if (this.openMenu && this.openMenu.parentNode) {
            this.openMenu.parentNode.removeChild(this.openMenu);
        }
        this.openMenu = null;
    };

    Table.prototype.exportCsv = function() {
        var self = this;
        var head = this.headers.map(function(th) {
            var b = th.querySelector('[data-role="sort"]');
            return (b ? b.textContent : th.textContent).trim();
        });
        var lines = [head];
        (this.visibleRows || this.rows).forEach(function(r) {
            lines.push(r.cells.map(function(c) {
                return c.text;
            }));
        });
        var csv = lines.map(function(row) {
            return row.map(function(v) {
                v = String(v).replace(/"/g, '""');
                return /[",\n]/.test(v) ? '"' + v + '"' : v;
            }).join(',');
        }).join('\n');

        var blob = new Blob(['﻿' + csv], {type: 'text/csv;charset=utf-8;'});
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'beacon-report.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.setTimeout(function() {
            URL.revokeObjectURL(a.href);
        }, 1000);
        self.closeMenu();
    };

    var init = function() {
        Str.get_strings([
            {key: 'drilltofilter',  component: 'local_beacon'},
            {key: 'removefilter',   component: 'local_beacon'},
            {key: 'clearselection', component: 'local_beacon'},
            {key: 'applyfilters',   component: 'local_beacon'},
        ]).then(function(s) {
            STR.drilltofilter  = s[0];
            STR.removefilter   = s[1];
            STR.clearselection = s[2];
            STR.applyfilters   = s[3];
            document.querySelectorAll('[data-region="beacon-table"]').forEach(function(root) {
                if (root.querySelector('[data-role="table"]')) {
                    new Table(root);
                }
            });
        }).catch(function() {
            // If string loading fails, fall back to English defaults and still init.
            document.querySelectorAll('[data-region="beacon-table"]').forEach(function(root) {
                if (root.querySelector('[data-role="table"]')) {
                    new Table(root);
                }
            });
        });
    };

    return {init: init};
});
