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
 * Language strings.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Beacon reports';
$string['beacon:view'] = 'View the Beacon reports dashboard';
$string['beacon:request'] = 'Request a new Beacon report';
$string['beacon:viewmine'] = 'View your own Beacon learning reports';
$string['itemnotfound'] = 'That report or metric is not available.';

// Learner self-view ("My reports").
$string['myreports'] = 'My reports';
$string['my_greeting'] = 'Hi {$a}';
$string['my_sub'] = 'Your own learning at a glance — only you can see this.';
$string['my_sec'] = 'YOUR LEARNING';
$string['my_sec_h'] = 'My reports';
$string['my_empty_h'] = 'Nothing to show yet';
$string['my_empty'] = 'Once you are enrolled in courses, your progress, grades and achievements will appear here.';
$string['my_enrolled'] = 'Enrolled courses';
$string['my_completed'] = 'Completed';
$string['my_inprogress'] = 'In progress';
$string['my_avggrade'] = 'Average grade';
$string['r_my_courses'] = 'My courses';
$string['r_my_courses_desc'] = 'Every course you are enrolled in, with your progress, status and when you were last there.';
$string['r_my_completions'] = 'My completions';
$string['r_my_completions_desc'] = 'The courses you have completed, most recent first.';
$string['r_my_grades'] = 'My grades';
$string['r_my_grades_desc'] = 'Your final grade in each course that has one.';
$string['r_my_certificates'] = 'My certificates';
$string['r_my_certificates_desc'] = 'The certificates you have been issued, and whether they are current.';
$string['r_my_badges'] = 'My badges';
$string['r_my_badges_desc'] = 'The badges you have earned, newest first.';

// Navigation placement + settings.
$string['settings_menu'] = 'Beacon settings';
$string['navplacement'] = 'Show Beacon in navigation';
$string['navplacement_desc'] = 'Choose where the Beacon link appears. Staff see the reports library; '
    . 'learners see “My reports”. Beacon is always available under Site administration → Reports.';
$string['nav_main'] = 'Main navigation';
$string['nav_home'] = 'Site home';
$string['nav_dashboard'] = 'Dashboard';
$string['nav_mycourses'] = 'My courses page';

// Library page.
$string['libraryheading'] = 'Reports';
$string['librarysub'] = 'Everything at a glance. Your stat cards, KPI gauges and ready-made reports — live from your Moodle data, with no set-up and no query building.';
$string['choosewhatshows'] = 'Choose what shows';
$string['requestareport'] = 'Request a report';
$string['sec_stats'] = 'Stat cards';
$string['sec_stats_h'] = 'At a glance';
$string['sec_kpis'] = 'KPI gauges';
$string['sec_kpis_h'] = 'Against target';
$string['sec_reports'] = 'Reports';
$string['sec_reports_h'] = 'Ready-made reports';
$string['newmetric'] = 'New';
$string['awaitingdata'] = 'Awaiting data';
$string['target'] = 'Target';
$string['openreport'] = 'Open report';
$string['needdifferent'] = 'Need a different report?';
$string['needdifferent_desc'] = 'Can’t find the stat, gauge or report you need? Tell us what you’re after and we’ll build it into your library within 48 hours.';
$string['emptyheading'] = 'Your library is ready to set up';
$string['emptybody'] = 'Choose the stat cards, KPI gauges and reports you want to see. You can change your selection at any time.';

// Families.
$string['family_people'] = 'People';
$string['family_progress'] = 'Progress';
$string['family_assessment'] = 'Assessment';
$string['family_engagement'] = 'Engagement';
$string['family_compliance'] = 'Compliance';
$string['family_operations'] = 'Operations';

// KPI status.
$string['status_on'] = 'On target';
$string['status_near'] = 'Watch';
$string['status_off'] = 'Off target';
$string['status_nodata'] = 'No data yet';

// Detail page.
$string['status'] = 'Status';
$string['backtoreports'] = 'Back to reports';
$string['mini_current'] = 'Current';
$string['mini_target'] = 'Target';
$string['mini_gap'] = 'Gap to target';
$string['mini_trend'] = 'Since last check';
$string['mini_now'] = 'Now';
$string['mini_peak'] = 'Peak';
$string['mini_low'] = 'Low';
$string['mini_change'] = 'Change';
$string['pp'] = 'pp';
$string['scale_you'] = 'You are here';
$string['scale_target'] = 'Target';
$string['viewunderlying'] = 'View the {$a} report';
$string['kpi_meaning_on'] = 'Comfortably on target — keep it here.';
$string['kpi_meaning_near'] = 'In the watch zone, just short of the target. A small push gets you there.';
$string['kpi_meaning_off'] = '{$a} percentage points below target — the biggest opportunity on this dashboard.';
$string['kpi_meaning_nodata'] = 'There is nothing to measure yet, so this gauge is waiting for data.';
$string['howmeasured'] = 'How it is measured';
$string['refreshedhourly'] = 'Refreshed hourly · read from cache, never computed on load';
$string['trend'] = 'Trend';
$string['trendtitle'] = 'Trend';
$string['trendhint'] = 'Recorded once a day — the line grows as history builds.';
$string['healthydirection'] = 'Healthy direction';
$string['better_higher'] = 'higher is better';
$string['better_lower'] = 'lower is better';
$string['better_neutral'] = 'neither';
$string['wherestands'] = 'Where it stands';
$string['amberbelow'] = 'amber below';
$string['greenfrom'] = 'green from';

// Metric names, notes and explanations.
$string['m_total_learners'] = 'Total learners';
$string['m_total_learners_note'] = 'accounts that could be learning here';
$string['m_total_learners_expl'] = 'Every active, non-deleted learner account on your site — the true headcount your rates are measured against.';
$string['m_live_enrolments'] = 'Live enrolments';
$string['m_live_enrolments_note'] = 'active enrolments right now';
$string['m_live_enrolments_expl'] = 'Distinct learner-and-course enrolments that are currently live, counted once even where a learner is enrolled by more than one method.';
$string['m_active_learners'] = 'Active learners';
$string['m_active_learners_note'] = 'used the site in the last 30 days';
$string['m_active_learners_expl'] = 'Distinct learners who accessed the site in the last 30 days — your real engaged audience, not just sign-ups.';
$string['m_new_enrolments'] = 'New enrolments';
$string['m_new_enrolments_note'] = 'created in the last 30 days';
$string['m_new_enrolments_expl'] = 'Fresh course enrolments in the last 30 days — the intake feeding your pipeline.';
$string['m_completions'] = 'Completions';
$string['m_completions_note'] = 'courses finished in the last 30 days';
$string['m_completions_expl'] = 'Courses marked complete in the last 30 days, counting only courses that track completion.';
$string['m_in_progress'] = 'In progress';
$string['m_in_progress_note'] = 'started and not yet finished';
$string['m_in_progress_expl'] = 'Live enrolments on completion-tracking courses that have not been completed yet — the work currently in flight.';
$string['m_awaiting_marking'] = 'Awaiting marking';
$string['m_awaiting_marking_note'] = 'submitted and not yet graded';
$string['m_awaiting_marking_expl'] = 'The latest assignment submissions still waiting for a grade — your teachers’ live marking queue.';
$string['m_average_grade'] = 'Average grade';
$string['m_average_grade_note'] = 'course totals, normalised to a percentage';
$string['m_average_grade_expl'] = 'The mean course-total grade across the site, each course normalised to a percentage so they’re comparable.';
$string['m_dormant_learners'] = 'Dormant learners';
$string['m_dormant_learners_note'] = 'not seen for 90 days';
$string['m_dormant_learners_expl'] = 'Learners who have logged in before but not for 90+ days — the at-risk group to re-engage before they lapse.';
$string['m_expiring_soon'] = 'Expiring soon';
$string['m_expiring_soon_note'] = 'certificates lapsing within 30 days';
$string['m_expiring_soon_expl'] = 'Certifications due to lapse in the next 30 days — renew before they expire, not after.';
$string['m_course_completion_rate'] = 'Course completion';
$string['m_course_completion_rate_note'] = 'of live enrolments that track completion';
$string['m_course_completion_rate_expl'] = 'The share of live enrolments that have been completed, '
    . 'counting only courses that actually track completion — the single most-watched L&D number.';
$string['m_activity_completion_rate'] = 'Activity engagement';
$string['m_activity_completion_rate_note'] = 'enrolments with at least one activity done';
$string['m_activity_completion_rate_expl'] = 'The share of live enrolments where the learner has '
    . 'completed at least one activity — an early signal that they have actually started.';
$string['m_monthly_active_rate'] = 'Monthly active';
$string['m_monthly_active_rate_note'] = 'learners active in the last 30 days';
$string['m_monthly_active_rate_expl'] = 'Active learners as a share of all learners — a rolling health check on engagement across the whole site.';
$string['m_pass_rate'] = 'Pass rate';
$string['m_pass_rate_note'] = 'reaching the course pass mark';
$string['m_pass_rate_expl'] = 'The proportion of graded learners at or above the course pass mark. '
    . 'Needs a pass mark set on the course total; where none is set, it waits for data.';
$string['m_feedback_rate'] = 'Feedback rate';
$string['m_feedback_rate_note'] = 'submissions that received a grade';
$string['m_feedback_rate_expl'] = 'The share of submitted assignments that have been graded — a service-level view of how completely learners are getting feedback.';
$string['m_certification_currency'] = 'Certification currency';
$string['m_certification_currency_note'] = 'certificates that have not lapsed';
$string['m_certification_currency_expl'] = 'The share of issued certificates that are still in date — a live compliance figure across everything you have awarded.';

// Report names and descriptions.
$string['r_learner_roster'] = 'Learner roster';
$string['r_learner_roster_desc'] = 'Every learner with contact details and where they sit in the organisation — the master list you keep coming back to.';
$string['r_enrolment_details'] = 'Enrolment details';
$string['r_enrolment_details_desc'] = 'Which course each learner joined, when, and by which method — anchored to distinct live enrolments so nothing is inflated.';
$string['r_course_completion'] = 'Course completion';
$string['r_course_completion_desc'] = 'Who has finished each course and exactly when — the report auditors and managers ask for first.';
$string['r_activity_completion'] = 'Activity completion';
$string['r_activity_completion_desc'] = 'How many activities inside a course each learner has ticked off — counting passed and failed as complete, as Moodle does.';
$string['r_not_started'] = 'Not started';
$string['r_not_started_desc'] = 'Enrolled learners who have never opened the course — the easiest win for a nudge before they drift.';
$string['r_login_activity'] = 'Login activity';
$string['r_login_activity_desc'] = 'When each learner first arrived and was last seen — recency read from last-access, never a costly log join.';
$string['r_grade_summary'] = 'Grade summary';
$string['r_grade_summary_desc'] = 'The course-total grade exactly as the gradebook holds it — the right number, filtered to course totals only.';
$string['r_quiz_performance'] = 'Quiz performance';
$string['r_quiz_performance_desc'] = 'Scores across every quiz, with previews excluded and grades normalised to a percentage so nothing is double-counted.';
$string['r_marking_queue'] = 'Marking queue';
$string['r_marking_queue_desc'] = 'Latest submissions still waiting for a grade, oldest first — hand your teachers a clean, de-duplicated to-mark list.';
$string['r_certification_status'] = 'Certification status';
$string['r_certification_status_desc'] = 'Every certificate held and the date it lapses — see who is current, who is expiring and who has already fallen out.';
$string['r_forum_engagement'] = 'Forum engagement';
$string['r_forum_engagement_desc'] = 'Who is contributing to discussion and when they last posted — distinct contributors, not raw post counts.';
$string['r_course_health'] = 'Course catalogue health';
$string['r_course_health_desc'] = 'Each course with its enrolments, completion tracking and last update — spot dormant, untracked or neglected courses fast.';

// Report grains.
$string['grain_learner'] = 'One row per learner';
$string['grain_enrolment'] = 'One row per enrolment';
$string['grain_course'] = 'One row per course';
$string['grain_submission'] = 'One row per submission';
$string['grain_certificate'] = 'One row per certificate';

// Report columns.
$string['col_learner'] = 'Learner';
$string['col_email'] = 'Email';
$string['col_department'] = 'Department';
$string['col_lastaccess'] = 'Last access';
$string['col_course'] = 'Course';
$string['col_method'] = 'Method';
$string['col_joined'] = 'Joined';
$string['col_status'] = 'Status';
$string['col_completed'] = 'Completed';
$string['col_done'] = 'Done';
$string['col_oftotal'] = 'Of total';
$string['col_enrolled'] = 'Enrolled';
$string['col_firstseen'] = 'First seen';
$string['col_idle'] = 'Idle';
$string['col_grade'] = 'Grade';
$string['col_attempts'] = 'Attempts';
$string['col_best'] = 'Best';
$string['col_avg'] = 'Average';
$string['col_assignment'] = 'Assignment';
$string['col_submitted'] = 'Submitted';
$string['col_waiting'] = 'Waiting';
$string['col_certificate'] = 'Certificate';
$string['col_issued'] = 'Issued';
$string['col_expires'] = 'Expires';
$string['col_posts'] = 'Posts';
$string['col_lastpost'] = 'Last post';
$string['col_tracks'] = 'Tracks completion';
$string['col_updated'] = 'Updated';

// Cell values.
$string['status_complete'] = 'Complete';
$string['status_inprogress'] = 'In progress';
$string['status_neveropened'] = 'Never opened';
$string['cert_current'] = 'Current';
$string['cert_expiring'] = 'Expiring';
$string['cert_lapsed'] = 'Lapsed';
$string['cert_noexpiry'] = 'No expiry';
$string['days'] = 'days';

// Interactive table.
$string['search'] = 'Search';
$string['searchplaceholder'] = 'Search this report…';
$string['of'] = 'of';
$string['clearfilters'] = 'Clear filters';
$string['exportcsv'] = 'CSV';
$string['downloadpdf'] = 'PDF';
$string['sortby'] = 'Sort by {$a}';
$string['filterby'] = 'Filter by {$a}';
$string['nomatches'] = 'No rows match your search and filters.';
$string['reportempty'] = 'This report has no rows yet.';
$string['showingcapped'] = 'Showing the first {$a} rows';
$string['total'] = 'total';
$string['reportfailed'] = 'That report did not finish';
$string['reportfailed_desc'] = 'The query could not be completed on this site. Nothing was changed and no data was lost.';
$string['filterall'] = 'All';
$string['filterselected'] = '{$a} selected';

// Report filter bar (server-side).
$string['filters'] = 'Filters';
$string['applyfilters'] = 'Apply';
$string['clearallfilters'] = 'Clear all';
$string['filtervalues'] = 'Search values…';
$string['datefrom'] = 'From';
$string['dateto'] = 'To';
$string['filter_cohort'] = 'Cohort';
$string['filter_group'] = 'Group';
$string['filter_course'] = 'Course';
$string['filter_category'] = 'Category';
$string['filter_role'] = 'Role';
$string['filter_auth'] = 'Sign-in method';
$string['filter_enrolmethod'] = 'Enrolment method';
$string['filter_idle'] = 'Idle for';
$string['filter_certstatus'] = 'Certificate status';
$string['filter_policystatus'] = 'Acceptance';
$string['filter_proficiency'] = 'Proficiency';
$string['filter_contextlevel'] = 'Assigned at';
$string['filter_daterange'] = 'Date range';
$string['filter_gradeband'] = 'Grade';
$string['filter_progressband'] = 'Progress';
$string['band_gradeband_high'] = '80% and above';
$string['band_gradeband_mid'] = '50–79%';
$string['band_gradeband_low'] = 'Below 50%';
$string['band_progressband_complete'] = 'Complete';
$string['band_progressband_inprogress'] = 'In progress';
$string['band_progressband_notstarted'] = 'Not started';
$string['vsperiod'] = 'vs 30 days ago';
$string['toggletheme'] = 'Toggle light / dark';
$string['columns'] = 'Columns';
$string['pinfirst'] = 'Pin first column';
$string['nselected'] = 'selected';
$string['exportselected'] = 'Export selected';
$string['emailselected'] = 'Email selected';
$string['clearselection'] = 'Clear';
$string['coursescope'] = 'Showing this course only';
$string['coursereports'] = 'Course reports';

// Saved views.
$string['savedviews'] = 'Saved views';
$string['savethisview'] = 'Name this view';
$string['savecurrent'] = 'Save current filters';
$string['view_saved'] = 'View saved.';
$string['view_deleted'] = 'View deleted.';

// Scheduled email delivery.
$string['emailschedule'] = 'Email schedule';
$string['schedule_name'] = 'Schedule name';
$string['schedule_name_ph'] = 'e.g. Weekly completions to the team';
$string['schedule_recipients'] = 'Recipients';
$string['schedule_format'] = 'Format';
$string['schedule_frequency'] = 'Frequency';
$string['schedule_create'] = 'Schedule email';
$string['freq_daily'] = 'Daily';
$string['freq_weekly'] = 'Weekly';
$string['freq_monthly'] = 'Monthly';
$string['delivery_saved'] = 'Scheduled — it will email {$a} recipient(s) on the chosen cadence.';
$string['delivery_deleted'] = 'Schedule removed.';
$string['delivery_norecipients'] = 'Add at least one valid email address.';
$string['delivery_email_subject'] = 'Beacon report: {$a->report}';
$string['delivery_email_body'] = 'Your scheduled Beacon report "{$a->name}" from {$a->site} is attached.

This report was sent automatically by Beacon. To change or stop it, open the report and edit its email schedule.';
$string['task_deliveries'] = 'Send Beacon report email deliveries';

// Privacy — saved views and deliveries.
$string['privacy:metadata:local_beacon_savedview'] = 'Report views a user has saved for themselves.';
$string['privacy:metadata:local_beacon_savedview:userid'] = 'The user who owns the saved view.';
$string['privacy:metadata:local_beacon_savedview:name'] = 'The name the user gave the view.';
$string['privacy:metadata:local_beacon_savedview:params'] = 'The saved filter selection.';
$string['privacy:metadata:local_beacon_savedview:timecreated'] = 'When the view was saved.';
$string['privacy:metadata:local_beacon_delivery'] = 'Scheduled report email deliveries a user has set up.';
$string['privacy:metadata:local_beacon_delivery:userid'] = 'The user who set up the delivery.';
$string['privacy:metadata:local_beacon_delivery:name'] = 'The delivery name.';
$string['privacy:metadata:local_beacon_delivery:recipients'] = 'The email addresses the report is sent to.';
$string['privacy:metadata:local_beacon_delivery:timecreated'] = 'When the delivery was created.';
$string['preset_custom'] = 'Custom';
$string['preset_7'] = 'Last 7 days';
$string['preset_30'] = 'Last 30 days';
$string['preset_90'] = 'Last 90 days';
$string['preset_365'] = 'Last 12 months';
$string['preset_ytd'] = 'This year';
$string['band_idle_30'] = '30+ days';
$string['band_idle_60'] = '60+ days';
$string['band_idle_90'] = '90+ days';
$string['band_certstatus_current'] = 'Current';
$string['band_certstatus_expiring'] = 'Expiring soon';
$string['band_certstatus_lapsed'] = 'Lapsed';
$string['band_policystatus_accepted'] = 'Accepted';
$string['band_policystatus_declined'] = 'Not accepted';
$string['band_proficiency_proficient'] = 'Proficient';
$string['band_proficiency_notyet'] = 'Not yet';
$string['band_contextlevel_10'] = 'System';
$string['band_contextlevel_40'] = 'Category';
$string['band_contextlevel_50'] = 'Course';
$string['band_contextlevel_70'] = 'Activity';

// Request a report.
$string['request_intro'] = 'Tell us the stat card, KPI gauge or report you wish Beacon had. We build it for you — and it lands in your library within 48 hours.';
$string['request_what'] = 'What would you like us to build?';
$string['kind_stat'] = 'Stat card';
$string['kind_stat_hint'] = 'A headline number';
$string['kind_kpi'] = 'KPI gauge';
$string['kind_kpi_hint'] = 'A number vs target';
$string['kind_report'] = 'Report';
$string['kind_report_hint'] = 'A full table';
$string['request_name'] = 'Give it a name';
$string['request_name_help'] = 'A short title, e.g. “Learners inactive 14+ days” or “SCORM pass rate by department”.';
$string['request_name_ph'] = 'What should we call it?';
$string['request_detail'] = 'Describe what it should show';
$string['request_detail_help'] = 'What should each row or number represent? Any filters, groupings or targets? The more detail, the better the match.';
$string['request_detail_ph'] = 'e.g. One row per learner who hasn’t logged in for 14+ days, showing their last access, their manager, and the mandatory courses they still owe.';
$string['request_email'] = 'Your email';
$string['request_email_help'] = 'So we can confirm and let you know the moment it’s live.';
$string['request_goesto'] = 'Goes to {$a} · built within 48 hours';
$string['request_send'] = 'Send request';
$string['thanks_heading'] = 'Request sent — thank you!';
$string['thanks_body'] = 'Your request “{$a}” is on its way to our team. We’ll confirm by email and build it straight into your library.';
$string['thanks_eta'] = 'We’ll have it built within 48 hours';
$string['error_required'] = 'Please fill this in.';
$string['error_email'] = 'Please enter a valid email address.';
$string['email_subject'] = '[Beacon request] {$a->kind}: {$a->title}';
$string['email_body'] = 'A new Beacon report request has been submitted.

Type: {$a->kind}
Name: {$a->title}

What it should show:
{$a->detail}

Requested by: {$a->name} ({$a->email})
Site: {$a->site} ({$a->siteurl})';

// Setup / checklist.
$string['setup_menu'] = 'Beacon: set up library';
$string['setup_heading'] = 'Set up your library';
$string['setup_intro'] = 'Tick what you want on your dashboard. Every item shows what it measures '
    . 'and a real example from your own site, so you choose with confidence. You can change this at any time.';
$string['setup_saved'] = 'Your library has been updated.';
$string['setup_selected'] = 'selected';
$string['setup_selectall'] = 'Select all';
$string['setup_clearall'] = 'Clear all';
$string['setup_example'] = 'On your site';
$string['setup_example_report'] = '{$a->grain} · {$a->rows} rows';
$string['setup_example_none'] = 'No data to measure yet';
$string['setup_unavailable'] = 'Not available on this site';
$string['setup_preview'] = 'Preview library';
$string['setup_save'] = 'Save changes';
$string['setup_summary'] = 'Showing {$a->stats} stat cards, {$a->kpis} KPI gauges and {$a->reports} reports.';

// PDF export.
$string['pdf_generated'] = 'Generated {$a}';
$string['pdf_rows'] = '{$a} rows';

// Tasks.
$string['task_snapshots'] = 'Record daily metric snapshots';

// Privacy.
$string['privacy:metadata:local_beacon_request'] = 'Information about requests submitted to have a new report built.';
$string['privacy:metadata:local_beacon_request:userid'] = 'The user who submitted the request.';
$string['privacy:metadata:local_beacon_request:requesteremail'] = 'The reply-to email address given for the request.';
$string['privacy:metadata:local_beacon_request:detail'] = 'The description of the requested report.';
$string['privacy:metadata:local_beacon_request:timecreated'] = 'When the request was submitted.';
$string['privacy:metadata:support'] = 'Report requests are emailed to LMS Hosting Services support so the report can be built.';
$string['privacy:metadata:support:title'] = 'The name of the requested report.';
$string['privacy:metadata:support:detail'] = 'The description of the requested report.';
$string['privacy:metadata:support:email'] = 'The reply-to email address of the requester.';

// Additional reports (v0.4).
$string['r_learner_progress'] = 'Learner progress';
$string['r_learner_progress_desc'] = 'One line per learner: how many courses they are enrolled on, '
    . 'how many they have completed, and their average grade — the manager’s overview.';
$string['r_inactive_learners'] = 'Inactive learners';
$string['r_inactive_learners_desc'] = 'Learners who have logged in before but not in the last 30 days, sorted by how long they have been away — your re-engagement list.';
$string['r_never_logged_in'] = 'Never logged in';
$string['r_never_logged_in_desc'] = 'Accounts that were created but have never once signed in — often a provisioning or welcome-email gap worth closing.';
$string['r_new_users'] = 'New users';
$string['r_new_users_desc'] = 'Accounts created in the last 30 days, newest first — a quick view of who has just joined and how they were created.';
$string['r_cohort_membership'] = 'Cohort membership';
$string['r_cohort_membership_desc'] = 'Which cohort each learner belongs to and when they were added — the audience behind your cohort enrolments and rules.';
$string['r_role_assignments'] = 'Staff & roles';
$string['r_role_assignments_desc'] = 'Who holds a teacher, manager or course-creator role and at what level — a permissions overview for governance and reviews.';
$string['r_course_progress'] = 'Course progress';
$string['r_course_progress_desc'] = 'Each live enrolment with a completion percentage across the course’s tracked activities — see exactly how far through everyone is.';
$string['r_competency_progress'] = 'Competency progress';
$string['r_competency_progress_desc'] = 'Every competency recorded against a learner and whether they have been signed off as proficient — the framework view.';
$string['r_quiz_grades'] = 'Quiz results';
$string['r_quiz_grades_desc'] = 'One row per quiz: finished attempts, distinct learners and the average '
    . 'score as a percentage, previews excluded — spot the quizzes that are too hard or too easy.';
$string['r_assignment_status'] = 'Assignment status';
$string['r_assignment_status_desc'] = 'One row per assignment: how many have submitted, how many are '
    . 'graded and how many are still pending — the teacher’s workload at a glance.';
$string['r_scorm_attempts'] = 'SCORM attempts';
$string['r_scorm_attempts_desc'] = 'Each learner’s SCORM package attempts and latest status — the completion picture for packaged e-learning content.';
$string['r_course_summary'] = 'Course summary';
$string['r_course_summary_desc'] = 'One row per course: enrolments, completions and average grade — compare course performance side by side.';
$string['r_badges_awarded'] = 'Badges awarded';
$string['r_badges_awarded_desc'] = 'Every badge issued and to whom, newest first — a record of recognition and achievement across the site.';
$string['r_policy_acceptance'] = 'Policy acceptance';
$string['r_policy_acceptance_desc'] = 'Who has accepted each site policy and when — the audit trail compliance teams need for consent and data-protection sign-off.';

// Additional grains.
$string['grain_cohort'] = 'One row per member';
$string['grain_role'] = 'One row per assignment';
$string['grain_competency'] = 'One row per competency';
$string['grain_quiz'] = 'One row per quiz';
$string['grain_assignment'] = 'One row per assignment';
$string['grain_badge'] = 'One row per badge';
$string['grain_policy'] = 'One row per acceptance';

// Additional columns.
$string['col_enrolledn'] = 'Enrolled';
$string['col_completedn'] = 'Completed';
$string['col_avggrade'] = 'Avg grade';
$string['col_created'] = 'Created';
$string['col_auth'] = 'Auth method';
$string['col_cohort'] = 'Cohort';
$string['col_added'] = 'Added';
$string['col_role'] = 'Role';
$string['col_scope'] = 'Scope';
$string['col_progress'] = 'Progress';
$string['col_competency'] = 'Competency';
$string['col_quiz'] = 'Quiz';
$string['col_learners'] = 'Learners';
$string['col_submitted_n'] = 'Submitted';
$string['col_graded'] = 'Graded';
$string['col_pending'] = 'Pending';
$string['col_scorm'] = 'SCORM package';
$string['col_badge'] = 'Badge';
$string['col_policy'] = 'Policy';
$string['col_agreed'] = 'When';

// Additional cell values.
$string['comp_proficient'] = 'Proficient';
$string['comp_notyet'] = 'Not yet';
$string['policy_accepted'] = 'Accepted';
$string['policy_declined'] = 'Declined';
