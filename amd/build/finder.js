// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Finder: live search + type chips over the cards on a Beacon page.
 *
 * Cards carry data-finder-item, data-family and data-search (lower-cased
 * text). Groups (data-finder-group) and sections (data-finder-section) hide
 * when nothing inside them matches. The query and chosen types are remembered
 * for the page in sessionStorage (per viewer, per browser tab).
 *
 * @module     local_beacon/finder
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('local_beacon/finder', [], function() {

    var OUT = 'bc-f-out';

    var store = function(key, value) {
        try {
            window.sessionStorage.setItem(key, JSON.stringify(value));
        } catch (e) {
            // Storage unavailable (private mode etc.) — the finder still works.
        }
    };
    var recall = function(key) {
        try {
            return JSON.parse(window.sessionStorage.getItem(key) || 'null');
        } catch (e) {
            return null;
        }
    };

    var init = function(rootselector) {
        var root = document.querySelector(rootselector);
        if (!root) {
            return;
        }
        var bar = root.querySelector('[data-finder]');
        if (!bar) {
            return;
        }
        var input = bar.querySelector('[data-finder-input]');
        var chips = Array.prototype.slice.call(bar.querySelectorAll('[data-finder-chip]'));
        var status = bar.querySelector('[data-finder-status]');
        var clear = bar.querySelector('[data-finder-clear]');
        var none = root.querySelector('[data-finder-none]');
        var items = Array.prototype.slice.call(root.querySelectorAll('[data-finder-item]'));
        var groups = Array.prototype.slice.call(root.querySelectorAll('[data-finder-group]'));
        var sections = Array.prototype.slice.call(root.querySelectorAll('[data-finder-section]'));
        var key = 'bc-finder:' + window.location.pathname + window.location.search;
        var families = {};

        var matches = function(item, terms) {
            var text = item.getAttribute('data-search') || '';
            for (var i = 0; i < terms.length; i++) {
                if (text.indexOf(terms[i]) === -1) {
                    return false;
                }
            }
            return true;
        };

        var apply = function() {
            var query = (input.value || '').toLowerCase().trim();
            var terms = query ? query.split(/\s+/) : [];
            var anyfam = Object.keys(families).length === 0;
            var shown = 0;
            var perfam = {'': 0};

            items.forEach(function(item) {
                var fam = item.getAttribute('data-family') || '';
                var textok = matches(item, terms);
                if (textok) {
                    perfam[''] += 1;
                    perfam[fam] = (perfam[fam] || 0) + 1;
                }
                var visible = textok && (anyfam || families[fam]);
                item.classList.toggle(OUT, !visible);
                if (visible) {
                    shown++;
                }
            });

            var visiblein = function(el) {
                return el.querySelectorAll('[data-finder-item]:not(.' + OUT + ')').length;
            };
            groups.forEach(function(g) {
                var n = visiblein(g);
                g.classList.toggle(OUT, n === 0);
                var c = g.querySelector('[data-finder-groupcount]');
                if (c) {
                    c.textContent = n;
                }
            });
            sections.forEach(function(s) {
                s.classList.toggle(OUT, visiblein(s) === 0);
            });

            // Chip counts reflect the search text, so you can see where matches are.
            chips.forEach(function(chip) {
                var fam = chip.getAttribute('data-finder-chip');
                var on = fam === '' ? anyfam : !!families[fam];
                chip.classList.toggle('on', on);
                chip.setAttribute('aria-pressed', on ? 'true' : 'false');
                var cnt = chip.querySelector('[data-finder-chipcount]');
                if (cnt) {
                    cnt.textContent = perfam[fam] || 0;
                }
            });

            var filtered = terms.length > 0 || !anyfam;
            if (status) {
                status.textContent = filtered
                    ? (status.getAttribute('data-tpl') || '').replace('{shown}', shown).replace('{total}', items.length)
                    : '';
            }
            if (clear) {
                clear.hidden = !filtered;
            }
            if (none) {
                none.hidden = shown > 0;
            }
            store(key, {q: input.value || '', f: Object.keys(families)});
        };

        // Restore this page's last search.
        var saved = recall(key);
        if (saved) {
            input.value = saved.q || '';
            (saved.f || []).forEach(function(f) {
                if (bar.querySelector('[data-finder-chip="' + f + '"]')) {
                    families[f] = true;
                }
            });
        }

        input.addEventListener('input', apply);
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                input.value = '';
                apply();
            }
        });
        chips.forEach(function(chip) {
            chip.addEventListener('click', function() {
                var fam = chip.getAttribute('data-finder-chip');
                if (fam === '') {
                    families = {};
                } else if (families[fam]) {
                    delete families[fam];
                } else {
                    families[fam] = true;
                }
                apply();
            });
        });
        if (clear) {
            clear.addEventListener('click', function() {
                input.value = '';
                families = {};
                apply();
                input.focus();
            });
        }
        // "/" jumps to the search box (unless typing in a field already).
        document.addEventListener('keydown', function(e) {
            var tag = (e.target && e.target.tagName) || '';
            if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test(tag) && !e.target.isContentEditable) {
                e.preventDefault();
                input.focus();
            }
        });

        apply();
    };

    return {
        init: init
    };
});
