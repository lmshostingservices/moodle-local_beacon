<?php
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
 * Inline SVG icon paths.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local;

/**
 * A tiny inline icon set, so cards never depend on a webfont loading.
 */
class icons {

    /** @var array<string,string> */
    private const PATHS = [
        'users' => '<path d="M17 20v-2a4 4 0 00-4-4H7a4 4 0 00-4 4v2" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>'
            . '<circle cx="10" cy="7" r="3.2" stroke="currentColor" stroke-width="1.9"/>'
            . '<path d="M21 20v-2a4 4 0 00-3-3.8" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>',
        'pulse' => '<path d="M3 12h4l2-6 4 12 2-6h6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>',
        'plus' => '<circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.8"/>'
            . '<path d="M12 8.5v7M8.5 12h7" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>',
        'flag' => '<path d="M6 21V4M6 4h11l-2 3 2 3H6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>',
        'play' => '<circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.8"/><path d="M10 8.5l5 3.5-5 3.5z" fill="currentColor"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.8"/>'
            . '<path d="M12 7.5V12l3 2" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>',
        'star' => '<path d="M12 4l2.2 4.8L19 9.4l-3.6 3.4.9 5.2L12 15.6 7.7 18l.9-5.2L5 9.4l4.8-.6L12 4z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>',
        'moon' => '<path d="M20 14.5A8 8 0 019.5 4 8 8 0 1020 14.5z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
        'shield' => '<path d="M12 3l7 3v5c0 4.4-3 8-7 10-4-2-7-5.6-7-10V6l7-3z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
        'cert' => '<circle cx="12" cy="9" r="5" stroke="currentColor" stroke-width="1.8"/>'
            . '<path d="M9 13l-1 7 4-2 4 2-1-7" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
        'check' => '<circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.8"/>'
            . '<path d="M8.5 12l2.5 2.5 4.5-5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>',
        'grid' => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.6" stroke="currentColor" stroke-width="1.8"/>'
            . '<rect x="13.5" y="3.5" width="7" height="7" rx="1.6" stroke="currentColor" stroke-width="1.8"/>'
            . '<rect x="3.5" y="13.5" width="7" height="7" rx="1.6" stroke="currentColor" stroke-width="1.8"/>'
            . '<rect x="13.5" y="13.5" width="7" height="7" rx="1.6" stroke="currentColor" stroke-width="1.8"/>',
        'doc' => '<rect x="4" y="3" width="16" height="18" rx="2.5" stroke="currentColor" stroke-width="1.8"/>'
            . '<path d="M8 8h8M8 12h8M8 16h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
        'login' => '<path d="M14 4h4a1 1 0 011 1v14a1 1 0 01-1 1h-4M10 12H3m0 0l3-3m-3 3l3 3" '
            . 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
        'pen' => '<path d="M4 20l4-1L19 8a2.1 2.1 0 00-3-3L5 16l-1 4z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
        'gauge' => '<path d="M4 15a8 8 0 0116 0" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>'
            . '<path d="M12 15l4-3" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>',
        'chart' => '<path d="M4 19V5M4 19h16M8 16v-4M12 16V8M16 16v-6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>',
    ];

    /**
     * The inner SVG markup for an icon key.
     *
     * @param string $key Icon key.
     * @return string
     */
    public static function svg(string $key): string {
        return self::PATHS[$key] ?? self::PATHS['chart'];
    }
}
