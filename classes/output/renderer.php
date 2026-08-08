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
 * Renderer.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\output;

use plugin_renderer_base;

/**
 * Renders Beacon pages from Mustache templates.
 */
class renderer extends plugin_renderer_base {
    /**
     * The library page.
     *
     * @param library $page Page.
     * @return string
     */
    public function render_library(library $page): string {
        return $this->render_from_template('local_beacon/library', $page->export_for_template($this));
    }

    /**
     * A detail page.
     *
     * @param detail $page Page.
     * @return string
     */
    public function render_detail(detail $page): string {
        return $this->render_from_template('local_beacon/detail', $page->export_for_template($this));
    }

    /**
     * The learner self-view ("My reports") page.
     *
     * @param mylibrary $page Page.
     * @return string
     */
    public function render_mylibrary(mylibrary $page): string {
        return $this->render_from_template('local_beacon/mylibrary', $page->export_for_template($this));
    }
}
