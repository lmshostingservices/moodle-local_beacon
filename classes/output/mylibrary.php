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
 * The learner self-view ("My reports") page renderable.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\output;

use local_beacon\local\catalogue;
use local_beacon\local\icons;
use renderable;
use templatable;
use renderer_base;

/**
 * A learner's personal dashboard: their own headline figures and reports.
 */
class mylibrary implements renderable, templatable {
    /**
     * Export for template.
     *
     * @param renderer_base $output Renderer.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        global $USER;

        $tiles = catalogue::personal_stats();

        $cards = [];
        foreach (catalogue::personal_reports() as $r) {
            if (!$r->is_available()) {
                continue;
            }
            $cards[] = [
                'name'        => $r->name(),
                'desc'        => $r->description(),
                'family'      => $r->family,
                'familylabel' => get_string('family_' . $r->family, 'local_beacon'),
                'icon'        => icons::svg($r->icon),
                'grain'       => $r->grainlabel(),
                'url'         => (new \moodle_url(
                    '/local/beacon/view.php',
                    ['type' => 'mine', 'id' => $r->id]
                ))->out(false),
            ];
        }

        return [
            'greeting'   => get_string('my_greeting', 'local_beacon', $USER->firstname),
            'sub'        => get_string('my_sub', 'local_beacon'),
            'tiles'      => $tiles,
            'hastiles'   => !empty($tiles),
            'reports'    => $cards,
            'hasreports' => !empty($cards),
            'isempty'    => empty($cards),
        ];
    }
}
