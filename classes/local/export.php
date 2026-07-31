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
 * Report exporters: a branded PDF and a plain CSV.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local;

/**
 * Turns a report's rows into a downloadable file.
 */
class export {

    /** Beacon brand teal, used on the PDF header and table. */
    private const TEAL = '#0E9C7B';
    private const TEAL_DEEP = '#0B7C63';
    private const WASH = '#E4F5EF';

    /**
     * A clean filename stem for a report at the current time.
     *
     * @param report $report Report.
     * @return string
     */
    private static function filename(report $report): string {
        $name = clean_filename(strtolower(str_replace(' ', '-', $report->name())));
        return 'beacon-' . $name . '-' . userdate(time(), '%Y%m%d');
    }

    /**
     * The translated column labels.
     *
     * @param report $report Report.
     * @return string[]
     */
    private static function headers(report $report): array {
        return array_map(fn($c) => get_string($c[1], 'local_beacon'), $report->columns);
    }

    /**
     * Stream a branded PDF and stop.
     *
     * @param report $report Report.
     * @param array $rows Rows of cells.
     * @param \context $context Context.
     * @return void
     */
    private static function build_pdf(report $report, array $rows, \context $context): \pdf {
        global $CFG, $SITE;
        require_once($CFG->libdir . '/pdflib.php');

        $wide = count($report->columns) >= 5;
        $pdf = new \pdf($wide ? 'L' : 'P');
        $pdf->SetCreator('Beacon');
        $pdf->SetAuthor(format_string($SITE->fullname));
        $pdf->SetTitle($report->name());
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->SetMargins(14, 16, 14);
        $pdf->SetAutoPageBreak(true, 16);
        $pdf->AddPage();

        $headers = self::headers($report);
        $generated = userdate(time());
        $site = format_string($SITE->fullname);
        $count = count($rows);

        // Header band + title block. The right side is left clear for the
        // site logo, which is overlaid top-right after the header is written.
        $head = '<table cellpadding="6" cellspacing="0"><tr>'
            . '<td width="60%" style="color:' . self::TEAL_DEEP . ';font-size:20px;font-weight:bold;">Beacon</td>'
            . '<td width="40%">&nbsp;</td>'
            . '</tr></table>';
        $head .= '<div style="border-bottom:2px solid ' . self::TEAL . ';">&nbsp;</div>';
        $head .= '<h1 style="font-size:17px;color:#111826;margin-top:8px;">' . s($report->name()) . '</h1>';
        $head .= '<p style="font-size:10px;color:#667283;">' . s($report->description()) . '</p>';
        $head .= '<p style="font-size:9px;color:#8A95A4;">'
            . s(get_string('pdf_generated', 'local_beacon', $generated))
            . ' &nbsp;·&nbsp; ' . s(get_string('pdf_rows', 'local_beacon', $count))
            . ' &nbsp;·&nbsp; ' . s($site) . '</p>';
        $pdf->writeHTML($head, true, false, true, false, '');

        // Overlay the site's main logo top-right (best-effort — a missing or
        // unfetchable logo must never break the export).
        $logo = self::site_logo_src();
        if ($logo !== null) {
            $savey = $pdf->GetY();
            try {
                // Right-aligned, ~14mm tall, at the very top of the page.
                $pdf->Image($logo, '', 11, 0, 14, '', '', 'T', false, 300, 'R',
                    false, false, 0, false, false, false);
            } catch (\Throwable $e) {
                debugging('Beacon PDF logo skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            $pdf->SetXY(14, $savey);
        }

        // The data table.
        $colw = floor(100 / max(1, count($headers)));
        $html = '<table border="0" cellpadding="5" cellspacing="0" style="font-size:9px;">';
        $html .= '<thead><tr style="background-color:' . self::TEAL . ';color:#ffffff;">';
        foreach ($headers as $h) {
            $html .= '<th width="' . $colw . '%" style="font-weight:bold;">' . s($h) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        $i = 0;
        foreach ($rows as $cells) {
            $bg = ($i % 2) ? '#F4F6F8' : '#FFFFFF';
            $html .= '<tr style="background-color:' . $bg . ';">';
            foreach ($cells as $cell) {
                $html .= '<td style="color:#38424F;">' . s((string) $cell['v']) . '</td>';
            }
            $html .= '</tr>';
            $i++;
        }
        $html .= '</tbody></table>';
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf;
    }

    /**
     * Resolve the site's main logo to something TCPDF can embed.
     *
     * Prefers a local temp copy of the admin site logo (no network fetch, and
     * works from cron); falls back to whatever the theme exposes as its logo
     * URL. Returns null when no logo is configured or anything goes wrong, so
     * the caller simply omits it.
     *
     * @return string|null Local file path or URL, or null.
     */
    private static function site_logo_src(): ?string {
        global $OUTPUT;

        // Fast, reliable path: a local copy of the Site admin > Logos logo.
        try {
            $fs = get_file_storage();
            $sys = \context_system::instance();
            foreach (['logo', 'logocompact'] as $area) {
                $files = $fs->get_area_files($sys->id, 'core_admin', $area, 0, 'filename', false);
                foreach ($files as $f) {
                    if ($f->is_valid_image()) {
                        $ext = strtolower(pathinfo($f->get_filename(), PATHINFO_EXTENSION)) ?: 'png';
                        $tmp = make_temp_directory('local_beacon') . '/sitelogo_' . $f->get_contenthash() . '.' . $ext;
                        if (!file_exists($tmp)) {
                            $f->copy_content_to($tmp);
                        }
                        return $tmp;
                    }
                }
            }
        } catch (\Throwable $e) {
            debugging('Beacon logo (file) skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // Fallback: whatever the theme/site exposes as its logo URL.
        try {
            if (isset($OUTPUT) && method_exists($OUTPUT, 'get_logo_url')) {
                $url = $OUTPUT->get_logo_url(null, 200);
                if ($url) {
                    return $url->out(false);
                }
            }
        } catch (\Throwable $e) {
            debugging('Beacon logo (url) skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return null;
    }

    /**
     * Stream a branded PDF to the browser and stop.
     *
     * @param report $report Report.
     * @param array $rows Rows of cells.
     * @param \context $context Context.
     * @return void
     */
    public static function pdf(report $report, array $rows, \context $context): void {
        $pdf = self::build_pdf($report, $rows, $context);
        $pdf->Output(self::filename($report) . '.pdf', 'D');
        exit;
    }

    /**
     * The branded PDF as a binary string, for emailing.
     *
     * @param report $report Report.
     * @param array $rows Rows of cells.
     * @param \context $context Context.
     * @return string
     */
    public static function pdf_string(report $report, array $rows, \context $context): string {
        $pdf = self::build_pdf($report, $rows, $context);
        return $pdf->Output(self::filename($report) . '.pdf', 'S');
    }

    /**
     * Stream a CSV and stop.
     *
     * @param report $report Report.
     * @param array $rows Rows of cells.
     * @return void
     */
    public static function csv(report $report, array $rows): void {
        global $CFG;
        require_once($CFG->libdir . '/csvlib.class.php');

        $csv = new \csv_export_writer();
        $csv->set_filename(self::filename($report));
        $csv->add_data(self::headers($report));
        foreach ($rows as $cells) {
            $csv->add_data(array_map(fn($c) => (string) $c['v'], $cells));
        }
        $csv->download_file();
        exit;
    }

    /**
     * The report as a CSV string (UTF-8 BOM, CRLF), for emailing.
     *
     * @param report $report Report.
     * @param array $rows Rows of cells.
     * @return string
     */
    public static function csv_string(report $report, array $rows): string {
        $line = function(array $cells): string {
            return implode(',', array_map(function($v) {
                $v = str_replace('"', '""', (string) $v);
                return preg_match('/[",\n\r]/', $v) ? '"' . $v . '"' : $v;
            }, $cells));
        };
        $out = [$line(self::headers($report))];
        foreach ($rows as $cells) {
            $out[] = $line(array_map(fn($c) => (string) $c['v'], $cells));
        }
        return "\xEF\xBB\xBF" . implode("\r\n", $out) . "\r\n";
    }

    /**
     * A clean download filename for a report (no extension).
     *
     * @param report $report Report.
     * @return string
     */
    public static function filename_for(report $report): string {
        return self::filename($report);
    }
}
