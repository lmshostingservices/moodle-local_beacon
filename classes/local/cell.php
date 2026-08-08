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
     * @return array
     */
    public static function text(string $value): array {
        return ['v' => $value, 'badge' => '', 'sort' => \core_text::strtolower($value)];
    }

    /**
     * A numeric cell, sorted by its number.
     *
     * @param float|int $number Number.
     * @param string|null $display Display string (defaults to the number).
     * @return array
     */
    public static function number($number, ?string $display = null): array {
        return ['v' => $display ?? (string) $number, 'badge' => '', 'sort' => (float) $number, 'numeric' => true];
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
     * @return array
     */
    public static function when(?int $timestamp): array {
        if (empty($timestamp)) {
            return ['v' => '—', 'badge' => '', 'sort' => 0];
        }
        return [
            'v'    => userdate($timestamp, get_string('strftimedate', 'langconfig')),
            'badge' => '',
            'sort' => (int) $timestamp,
        ];
    }
}
