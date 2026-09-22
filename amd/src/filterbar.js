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
 * Progressive enhancement for the server-side report filter bar.
 *
 * The bar is built from native <details> pills inside one <form method="get">,
 * so it fully works with no JavaScript: open a pill, tick values, press Apply,
 * the page reloads filtered. This module only adds polish — close other pills
 * when one opens, close on outside-click / Escape, live-search long value lists,
 * and auto-submit the instant a date preset is chosen.
 *
 * @module     local_beacon/filterbar
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('local_beacon/filterbar', [], function() {

    var Bar = function(form) {
        this.form = form;
        this.pills = Array.prototype.slice.call(form.querySelectorAll('details[data-fpill]'));
        this.bind();
        this.bindDependents();
    };

    /**
     * Dependent dropdowns: a pill carrying data-depends-param only shows the
     * facets whose data-parent is among the values ticked in the parent pill
     * (Course narrows to the chosen Category, Group to the chosen Course). With
     * nothing ticked in the parent, everything shows. A facet hidden this way is
     * also unchecked, so it can't apply a stale filter. Pure enhancement — with
     * no JS every option shows and the server still constrains the results.
     */
    Bar.prototype.bindDependents = function() {
        var self = this;
        var dependents = this.pills.filter(function(p) {
            return p.getAttribute('data-depends-param');
        });
        if (!dependents.length) {
            return;
        }
        var parentPill = function(param) {
            for (var i = 0; i < self.pills.length; i++) {
                if (self.pills[i].querySelector('input[name="' + param + '[]"]')) {
                    return self.pills[i];
                }
            }
            return null;
        };
        var refresh = function(pill) {
            var parent = parentPill(pill.getAttribute('data-depends-param'));
            var chosen = [];
            if (parent) {
                parent.querySelectorAll('input[type="checkbox"]:checked').forEach(function(cb) {
                    chosen.push(cb.value);
                });
            }
            pill.querySelectorAll('.bc-ffacet').forEach(function(facet) {
                var par = facet.getAttribute('data-parent');
                var show = (chosen.length === 0) || (par !== null && chosen.indexOf(par) !== -1);
                facet.style.display = show ? '' : 'none';
                if (!show) {
                    var cb = facet.querySelector('input[type="checkbox"]');
                    if (cb) {
                        cb.checked = false;
                    }
                }
            });
        };
        // Refresh in declared order (Category → Course → Group), so a change high
        // in the chain cascades: hiding a course also drops it from the Group
        // parent set before the Group list refreshes.
        var refreshAll = function() {
            dependents.forEach(refresh);
        };
        this.form.addEventListener('change', refreshAll);
        refreshAll();
    };

    Bar.prototype.bind = function() {
        var self = this;

        this.pills.forEach(function(pill) {
            // Only one pill open at a time.
            pill.addEventListener('toggle', function() {
                if (pill.open) {
                    self.pills.forEach(function(other) {
                        if (other !== pill) {
                            other.open = false;
                        }
                    });
                    var search = pill.querySelector('[data-fsearch]');
                    if (search) {
                        search.focus();
                    }
                }
            });

            // Live-filter the value list.
            var search = pill.querySelector('[data-fsearch]');
            if (search) {
                search.addEventListener('input', function() {
                    var q = search.value.toLowerCase();
                    pill.querySelectorAll('.bc-ffacet').forEach(function(facet) {
                        var hit = facet.textContent.toLowerCase().indexOf(q) !== -1;
                        facet.style.display = hit ? '' : 'none';
                    });
                });
            }

            // Choosing a concrete date preset applies immediately.
            pill.querySelectorAll('[data-fpreset]').forEach(function(radio) {
                radio.addEventListener('change', function() {
                    if (radio.value !== '') {
                        self.form.submit();
                    }
                });
            });

            // Select all / Clear all for a multi-value pill. Only toggles the
            // currently-visible options (so it respects an active search filter).
            var setAll = function(state) {
                pill.querySelectorAll('.bc-ffacet').forEach(function(facet) {
                    if (facet.style.display === 'none') {
                        return;
                    }
                    var cb = facet.querySelector('input[type="checkbox"]');
                    if (cb) {
                        cb.checked = state;
                    }
                });
                // Let dependent-dropdown wiring react to the change.
                self.form.dispatchEvent(new Event('change'));
            };
            var all = pill.querySelector('[data-facetall]');
            if (all) {
                all.addEventListener('click', function() {
                    setAll(true);
                });
            }
            var none = pill.querySelector('[data-facetnone]');
            if (none) {
                none.addEventListener('click', function() {
                    setAll(false);
                });
            }
        });

        // Close on outside click.
        document.addEventListener('click', function(e) {
            self.pills.forEach(function(pill) {
                if (pill.open && !pill.contains(e.target)) {
                    pill.open = false;
                }
            });
        });

        // Close on Escape.
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                self.pills.forEach(function(pill) {
                    pill.open = false;
                });
            }
        });
    };

    /**
     * Keep the page from jumping to the top when a filter is applied.
     *
     * Applying a filter reloads the page (a rock-solid GET), and browsers reset
     * the scroll to the very top on a fresh load — so the report appears to
     * "jump" up above the site header. We stash the scroll position the instant
     * a filter navigation starts and restore it once the reloaded page arrives,
     * so you stay exactly where you were. Normal scrolling is untouched.
     */
    var preserveScroll = function() {
        try {
            var y = window.sessionStorage.getItem('bc-scroll');
            if (y !== null) {
                window.sessionStorage.removeItem('bc-scroll');
                window.scrollTo(0, parseInt(y, 10) || 0);
            }
        } catch (e) { /* Ignore */ }

        var save = function() {
            try {
                window.sessionStorage.setItem('bc-scroll',
                    String(window.pageYOffset || window.scrollY || 0));
            } catch (e) { /* Ignore */ }
        };

        // Save on every filter navigation: Apply / preset submits, chip-removal
        // and Clear-all links, and saved-view / schedule form posts.
        document.querySelectorAll('[data-region="beacon-filterbar"], [data-region="beacon-actions"]')
            .forEach(function(region) {
                region.addEventListener('submit', save, true);
                region.querySelectorAll('a[href]').forEach(function(a) {
                    a.addEventListener('click', save);
                });
            });
    };

    var init = function() {
        preserveScroll();
        document.querySelectorAll('[data-region="beacon-filterbar"], [data-region="beacon-actions"]')
            .forEach(function(region) {
                new Bar(region);
            });
    };

    return {init: init};
});
