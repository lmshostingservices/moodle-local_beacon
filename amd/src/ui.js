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
 * Progressive enhancement: count-up numbers, gauge draw, setup tabs, request form.
 *
 * Everything here is enhancement only. With JavaScript off, the page already
 * shows final values, drawn gauges and working forms.
 *
 * @module     local_beacon/ui
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('local_beacon/ui', [], function() {

    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var fmt = function(n) {
        return Math.round(n).toLocaleString();
    };

    var countUp = function(node) {
        var target = parseFloat(node.getAttribute('data-count'));
        if (isNaN(target)) {
            return;
        }
        var decimals = (node.textContent.indexOf('.') !== -1) ? 1 : 0;
        if (reduce) {
            return;
        }
        var dur = 1100, start = null; // eslint-disable-line one-var-declaration-per-line
        var step = function(ts) {
            if (!start) {
                start = ts;
            }
            var p = Math.min(1, (ts - start) / dur);
            var e = 1 - Math.pow(1 - p, 3);
            var cur = target * e;
            node.firstChild.nodeValue = decimals ? cur.toFixed(1) : fmt(cur);
            if (p < 1) {
                window.requestAnimationFrame(step);
            } else {
                node.firstChild.nodeValue = decimals ? target.toFixed(1) : fmt(target);
            }
        };
        // Reset to zero, keep the unit span intact.
        node.firstChild.nodeValue = decimals ? '0.0' : '0';
        window.requestAnimationFrame(step);
    };

    var drawGauge = function(fill) {
        var off = fill.getAttribute('data-off');
        var len = fill.getAttribute('data-len');
        if (off === null || len === null || reduce) {
            return;
        }
        fill.style.strokeDashoffset = len;
        window.requestAnimationFrame(function() {
            window.requestAnimationFrame(function() {
                fill.style.strokeDashoffset = off;
            });
        });
    };

    var animateNode = function(n) {
        if (n.getAttribute('data-done')) {
            return;
        }
        n.setAttribute('data-done', '1');
        if (n.hasAttribute('data-count')) {
            countUp(n);
        } else if (n.classList.contains('bc-gfill')) {
            drawGauge(n);
        }
    };

    var initAnimations = function() {
        // Cards are made visible by CSS animation, never by JS, so nothing can
        // stay hidden. JS only enriches with count-up numbers and gauge draw.
        var targets = Array.prototype.slice.call(
            document.querySelectorAll('[data-count], .bc-gfill'));

        if (!('IntersectionObserver' in window) || reduce) {
            targets.forEach(animateNode);
            return;
        }
        var io = new IntersectionObserver(function(entries) {
            entries.forEach(function(en) {
                if (en.isIntersecting) {
                    animateNode(en.target);
                    io.unobserve(en.target);
                }
            });
        }, {threshold: 0.2});
        targets.forEach(function(t) {
            io.observe(t);
        });
        // Guarantee every number/gauge animates even if the observer never fires.
        window.setTimeout(function() {
            targets.forEach(animateNode);
        }, 700);
    };

    /**
     * Light / dark theme. Default follows the OS via a CSS media query; an
     * explicit user choice is remembered and applied to every Beacon surface.
     *
     * @param {string} key Storage key.
     * @param {*} val Value to store.
     */
    var store = function(key, val) {
        try {
            window.localStorage.setItem(key, val);
        } catch (e) {
            return;
        }
    };
    var recall = function(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (e) {
            return null;
        }
    };

    var applyTheme = function(theme) {
        document.querySelectorAll('.bc-root').forEach(function(root) {
            if (theme === 'dark' || theme === 'light') {
                root.setAttribute('data-bc-theme', theme);
            } else {
                root.removeAttribute('data-bc-theme');
            }
        });
    };

    var initTheme = function() {
        var saved = recall('bc-theme');
        if (saved) {
            applyTheme(saved);
        }
        document.querySelectorAll('[data-role="theme-toggle"]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var prefersDark = window.matchMedia &&
                    window.matchMedia('(prefers-color-scheme: dark)').matches;
                var current = recall('bc-theme') || (prefersDark ? 'dark' : 'light');
                var next = current === 'dark' ? 'light' : 'dark';
                store('bc-theme', next);
                applyTheme(next);
            });
        });
    };

    var init = function() {
        initTheme();
        initAnimations();
    };

    /**
     * The setup checklist: tabs, live counts, select-all/clear and summary.
     */
    var initSetup = function() {
        var root = document.querySelector('[data-region="beacon-setup"]');
        if (!root) {
            return;
        }

        var tabs = root.querySelector('[data-role="tabs"]');
        if (tabs) {
            tabs.addEventListener('click', function(e) {
                var t = e.target.closest('.bc-tab');
                if (!t) {
                    return;
                }
                root.querySelectorAll('.bc-tab').forEach(function(x) {
                    x.classList.remove('on');
                });
                t.classList.add('on');
                root.querySelectorAll('.bc-tab-panel').forEach(function(p) {
                    p.classList.toggle('on', p.getAttribute('data-panel') === t.getAttribute('data-tab'));
                });
            });
        }

        var groups = ['stats', 'kpis', 'reports'];

        var refresh = function() {
            var counts = {};
            groups.forEach(function(g) {
                counts[g] = root.querySelectorAll('input[data-group="' + g + '"]:checked').length;
                root.querySelectorAll('[data-count="' + g + '"], [data-role="cnt"][data-for="' + g + '"]')
                    .forEach(function(n) {
                        n.textContent = counts[g];
                    });
            });
            var sum = root.querySelector('[data-role="summary"]');
            if (sum) {
                sum.innerHTML = 'Showing <b>' + counts.stats + '</b> stat cards, <b>' + counts.kpis +
                    '</b> KPI gauges and <b>' + counts.reports + '</b> reports on your dashboard.';
            }
        };

        root.querySelectorAll('.bc-check input[type="checkbox"]').forEach(function(cb) {
            cb.addEventListener('change', function() {
                cb.closest('.bc-check').classList.toggle('sel', cb.checked);
                refresh();
            });
        });

        root.querySelectorAll('[data-role="all"], [data-role="none"]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var g = btn.getAttribute('data-for');
                var on = btn.getAttribute('data-role') === 'all';
                root.querySelectorAll('input[data-group="' + g + '"]').forEach(function(cb) {
                    if (cb.disabled) {
                        return;
                    }
                    cb.checked = on;
                    cb.closest('.bc-check').classList.toggle('sel', on);
                });
                refresh();
            });
        });

        refresh();
    };

    /**
     * The request form: the visual state of the three type choices.
     */
    var initRequestForm = function() {
        var grid = document.querySelector('[data-role="typegrid"]');
        if (!grid) {
            return;
        }
        grid.addEventListener('change', function() {
            grid.querySelectorAll('.bc-type-opt').forEach(function(opt) {
                var input = opt.querySelector('input');
                opt.classList.toggle('on', input && input.checked);
            });
        });
    };

    return {
        init: init,
        initSetup: initSetup,
        initRequestForm: initRequestForm
    };
});
