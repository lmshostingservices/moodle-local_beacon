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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * A single rendered table cell.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local;

/**
 * Small value object so report queries return display-ready cells with an
 * optional status colour and a machine sort key that the interactive table
 * uses for correct numeric and chronological sorting.
 */
class cell {
    /**
     * A plain text cell. The sort key is the lower-cased text.
     *
     * @param string $value Text.
     * @param string|null $url Optional link target; when set the cell renders as
     *                         a hyperlink instead of a click-to-filter value.
     * @return array
     */
    public static function text(string $value, ?string $url = null): array {
        $cell = ['v' => $value, 'badge' => '', 'sort' => \core_text::strtolower($value)];
        if ($url !== null && $url !== '') {
            $cell['url'] = $url;
        }
        return $cell;
    }

    /**
     * A numeric cell, sorted by its number.
     *
     * @param float|int $number Number.
     * @param string|null $display Display string (defaults to the number).
     * @return array
     */
    public static function number($number, ?string $display = null, ?string $url = null): array {
        $cell = ['v' => $display ?? (string) $number, 'badge' => '', 'sort' => (float) $number, 'numeric' => true];
        if ($url !== null && $url !== '') {
            $cell['url'] = $url;
        }
        return $cell;
    }

    /**
     * A coloured status pill cell.
     *
     * @param string $value Text.
     * @param string $badge g (good), w (warn) or b (bad).
     * @return array
     */
    public static function status(string $value, string $badge): array {
        return ['v' => $value, 'badge' => $badge, 'isstatus' => true, 'sort' => \core_text::strtolower($value)];
    }

    /**
     * A timestamp rendered as a short date, sorted by the raw time.
     *
     * @param int|null $timestamp Unix time.
     * @param string|null $url Optional link target; when set the date renders as
     *                         a hyperlink instead of a click-to-filter value.
     * @return array
     */
    public static function when(?int $timestamp, ?string $url = null): array {
        if (empty($timestamp)) {
            return ['v' => '—', 'badge' => '', 'sort' => 0];
        }
        $cell = [
            'v'    => userdate($timestamp, get_string('strftimedate', 'langconfig')),
            'badge' => '',
            'sort' => (int) $timestamp,
        ];
        if ($url !== null && $url !== '') {
            $cell['url'] = $url;
        }
        return $cell;
    }
}
