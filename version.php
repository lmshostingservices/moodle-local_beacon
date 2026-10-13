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
 * Version details.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_beacon';
$plugin->version   = 2026101305;      // YYYYMMDDXX — 13 Oct 2026, sequence 05.
$plugin->requires  = 2024042200;      // Moodle 4.4.0.
$plugin->supported = [404, 501];      // Moodle 4.4 through 5.1.
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '2.5.4';         // Fix: Student absence alert query failing on MySQL (GROUP BY concat); hardened for cross-DB.
$plugin->release_prev = '2.5.3';      // Previous release.
