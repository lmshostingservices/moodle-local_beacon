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
 * Report Studio page.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\output;

use local_beacon\local\studio\engine;
use renderable;
use templatable;
use renderer_base;

/**
 * The Report Studio: a plain-language box that maps onto Beacon's curated
 * building blocks and renders a scoped, read-only report plus an auto-chosen
 * chart — with no AI anywhere in the pipeline.
 */
class studio implements renderable, templatable {

    /** @var \context Page context. */
    protected \context $context;

    /** @var string The current request text (may be empty). */
    protected string $query;

    /**
     * Constructor.
     *
     * @param \context $context
     * @param string $query
     */
    public function __construct(\context $context, string $query) {
        $this->context = $context;
        $this->query = trim($query);
    }

    /**
     * The curated example prompts shown as one-tap chips.
     *
     * @return string[]
     */
    public static function examples(): array {
        return [
            'Course completion rate by course',
            'Enrolments per month',
            'How many learners in each course',
            'Quiz pass rate by course',
            'Activity completions by type',
            'Average score by quiz',
            'Enrolments by status',
            'Learners not logged in for 30 days',
        ];
    }

    /**
     * Build the template context, running the engine when a request is present.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $self = new \moodle_url('/local/beacon/studio.php', ['contextid' => $this->context->id]);

        $examples = [];
        foreach (self::examples() as $ex) {
            $examples[] = [
                'label' => $ex,
                'url' => (new \moodle_url($self, ['q' => $ex]))->out(false),
            ];
        }

        $tabs = \local_beacon\output\library::tabs($this->context, 'studio');
        $ctx = [
            'heading' => get_string('studio', 'local_beacon'),
            'tabs' => $tabs,
            'hastabs' => !empty($tabs),
            'tagline' => get_string('studio_tagline', 'local_beacon'),
            'intro' => get_string('studio_intro', 'local_beacon'),
            'ask' => get_string('studio_ask', 'local_beacon'),
            'placeholder' => get_string('studio_placeholder', 'local_beacon'),
            'buildlabel' => get_string('studio_build', 'local_beacon'),
            'exampleslabel' => get_string('studio_examples', 'local_beacon'),
            'noai' => get_string('studio_noai', 'local_beacon'),
            'formaction' => $self->out(false),
            'contextid' => $this->context->id,
            'query' => $this->query,
            'examples' => $examples,
            'hasresult' => false,
            'uniqid' => \html_writer::random_id('bcstudio'),
        ];

        if ($this->query === '') {
            return $ctx;
        }

        $spec = engine::parse($this->query);
        $result = engine::run($spec, $this->context);

        $ctx['hasresult'] = true;
        $ctx['readaslabel'] = get_string('studio_readas', 'local_beacon');
        $ctx['explanation'] = $result->explanation;
        $ctx['grain'] = get_string('studio_grain', 'local_beacon', $result->grain);
        $ctx['notes'] = array_map(fn($n) => ['text' => $n], $result->notes);
        $ctx['hasnotes'] = !empty($result->notes);
        $ctx['resultslabel'] = get_string('studio_results', 'local_beacon');
        $ctx['sqllabel'] = get_string('studio_thequery', 'local_beacon');
        $ctx['showsql'] = get_string('studio_showsql', 'local_beacon');
        $ctx['sqlpreview'] = $result->sqlpreview;

        // The recipe: how Beacon read the request, one building block per row.
        $filters = array_map(fn($f) => ['label' => $f->label], $spec->filters ?? []);
        $ctx['recipe'] = [
            'subject' => $spec->subject['label'] ?? '',
            'measure' => $result->measurelabel,
            'dimension' => $result->dimensionlabel ?: get_string('studio_nogroup', 'local_beacon'),
            'grouped' => !empty($result->dimensionlabel),
            'filters' => $filters,
            'hasfilters' => !empty($filters),
            'grain' => $result->grain,
        ];

        if ($result->error) {
            $ctx['error'] = get_string('studio_error', 'local_beacon');
            return $ctx;
        }

        // Headline (single total) or chart + table.
        if ($result->headline !== null) {
            $ctx['hasheadline'] = true;
            $ctx['headline'] = $result->headline;
            $ctx['headlinelabel'] = $result->measurelabel;
            $ctx['charttype'] = get_string('studio_single', 'local_beacon');
        } else {
            if ($result->chart) {
                $ctx['haschart'] = true;
                $ctx['charttype'] = get_string('chart_type_' . $result->chart->type, 'local_beacon');
                $ctx['charthtml'] = $output->render_chart($result->chart->chart, false);
            } else {
                $ctx['charttype'] = get_string('studio_tableonly', 'local_beacon');
            }
            $cols = [];
            foreach ($result->columns as $c) {
                $cols[] = ['label' => $c];
            }
            $rows = [];
            foreach ($result->rows as $r) {
                $cells = [];
                foreach ($r as $i => $v) {
                    $cells[] = ['v' => $v, 'first' => ($i === 0)];
                }
                $rows[] = ['cells' => $cells];
            }
            $ctx['table'] = ['columns' => $cols, 'rows' => $rows];
            $ctx['hasrows'] = !empty($rows);
            $ctx['rowcount'] = count($rows);
            $ctx['rowcountlabel'] = get_string('studio_rowcount', 'local_beacon', count($rows));
            $ctx['csvname'] = clean_filename(\core_text::strtolower($this->query)) . '.csv';
        }

        return $ctx;
    }
}
