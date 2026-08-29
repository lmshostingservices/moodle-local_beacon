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
$plugin->version   = 2026082901;      // YYYYMMDDXX — 11 Aug 2026, sequence 00. 10-digit Marketplace format. > highest savepoint 2026073114.
$plugin->requires  = 2024042200;      // Moodle 4.4.0.
$plugin->supported = [404, 501];      // Moodle 4.4 through 5.1.
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.7.8'; // RELEASE RECOVERY: Republished the reviewed authoritative source under a new immutable tag because the historical tag contained a different source tree. No functional changes.
$plugin->release_prev = '1.7.6';      // Previous release (submitted to Moodle Marketplace).
