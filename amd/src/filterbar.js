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
        } catch (e) { /* ignore */ }

        var save = function() {
            try {
                window.sessionStorage.setItem('bc-scroll',
                    String(window.pageYOffset || window.scrollY || 0));
            } catch (e) { /* ignore */ }
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
