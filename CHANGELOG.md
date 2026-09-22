# Changelog

All notable changes to the Beacon (local_beacon) plugin are documented here.

## [v2.1.0] - 2026-09-22
- **Teachers are now automatically scoped to their own learners on every report —
  closing a leak.** Previously a teacher who could open the site dashboard saw
  every course on Funding participation (and other reports) until they manually
  picked a filter. Now a non-admin viewer is hard-limited, in the SQL, to the
  learners they teach on every report and every export, before touching a filter.
  A visible banner names exactly which course(s) they're seeing, so the scope is
  auditable. Site-wide reports (people directories, role and policy lists) that
  can't be scoped to a teacher are hidden from them and blocked by URL.
- **Group filter: your groups are pre-ticked, with Select all / Clear all.** On a
  fresh open the teacher's own groups are all selected; quick buttons let them tick
  all or clear all and drill to one group. "Clear all" is respected and not
  re-defaulted.
- **Student activity drill-down now names the student and course** — shown as pill
  boxes on the page, and included at the top of both the CSV and PDF export, so an
  exported file stands on its own.
- Managers keep full site-wide access via the new `local/beacon:viewall`
  capability; only holders of that capability are unscoped.

## [v2.0.0] - 2026-09-22
- **Fix: add the missing language string for the `local/beacon:viewall`
  capability.** Every Moodle capability must have a matching language string; the
  one added in 1.11.0 did not, which failed the Moodle plugin directory's automated
  validation (`validate` / `phplint` reported as failed). Added
  `$string['beacon:viewall']`. No functional or schema change — this is the same
  1.11.0 feature set, now passing the marketplace checks.

## [v1.11.0] - 2026-09-22
- **Teacher scoping now works with custom roles.** Previously Beacon only
  recognised Moodle's built-in Teacher / Non-editing teacher archetypes, so a
  site with a custom role (e.g. "Trainer") saw empty reports — including a blank
  Student-activity drill-down. A new **Teacher roles** section on the Beacon
  settings page lets an admin choose, from the site's real roles, which count as
  **course-level** teachers (see every learner in their course) and which are
  **group-level** teachers (see only their group members). Reads the live role
  list, so custom roles appear.
- **Group-level teachers no longer see nothing on group-less courses.** If a
  course has no groups there is no boundary to apply, so a group-level teacher now
  sees the whole course (matching Moodle's own no-groups behaviour) instead of an
  empty report. All teacher-scoping paths — the Student-activity drill-down, the
  Teacher filter, the marking queues and the scoped filter option lists — use the
  configurable roles and this fallback. Validated on a second SQL engine.
- **New capability `local/beacon:viewall`** (granted to Manager by default)
  distinguishes "sees all learners site-wide" from a teacher who is scoped to
  their own learners.
- **Student activity page now has two back-links:** "Back to reports" (the
  library) and "Back to Funding participation" (the report it was opened from).

## [v1.10.0] - 2026-09-22
- **Teacher / Non-Editing Teacher filter is now on every learner-in-course
  report.** Previously it appeared only on the two Marking queue reports; it is now
  available (admin only) on Funding participation, Course access, Course
  completion, Activity completion, Course progress, Enrolment details, Not started,
  Grade summary, Quiz performance and SCORM attempts. Pick a teacher and the report
  narrows to the learners that teacher is responsible for — every learner in a
  course where they are an editing teacher, or the learners sharing their groups in
  a course where they are a non-editing teacher. Reports that have no learner-in-a-
  course dimension (e.g. Course health, Quiz grades, Assignment status, site-wide
  people lists) deliberately do not offer it. Query-level only; no schema change.
  Logic validated against seeded data on a second SQL engine.

## [v1.9.9] - 2026-09-22
- **Fixed: deep-link cells now render as links again.** Report-table cells that
  carry a link target — the **Activities** count on Funding participation (which
  opens the Student-activity drill-down) and every deep link on the Marking queue
  reports (learner, course, assignment, submission-date-to-grading-screen) — were
  being stripped of their URL when the table was built, so they showed as plain
  text and could not be clicked. The URL is now carried through to the template,
  so these cells are clickable hyperlinks. Presentation-only; no schema change.

## [v1.9.8] - 2026-09-22
- **Participation table now backfills itself right after upgrade/install.** The
  durable participation table starts empty and used to stay empty — every
  Activities count showing 0 and the Student-activity drill-down showing nothing
  — until the hourly harvest task first ran (up to an hour later). A one-off
  background harvest is now queued the moment the table exists, so the evidence
  appears at the next cron pass with no admin action. It re-queues itself while a
  large historic backlog remains, and never double-counts (per-source high-water
  marks). Admins can still force it immediately via **Site administration →
  Server → Tasks → Scheduled tasks → Harvest activity participation → Run now**.
- **Number and date columns are now centre-aligned** across every report table
  (text columns stay left-aligned). Headers follow their column's data, so header
  and cell alignment always match — even where a date column was declared with the
  generic text type.
- **The "Trainer" filter is now labelled "Teacher / Non-Editing Teacher"** so it
  is recognisable on the Marking queue and My marking queue reports (it remains a
  site-admin-only picker of the teachers/non-editing teachers involved).

## [v1.9.7] - 2026-09-22
- Coding-style only (no behaviour change): reformatted a few multi-line function
  calls so the first argument starts on its own line, and capitalised one comment
  block, to satisfy the Moodle CodeSniffer (phpcs) checks.

## [v1.9.6] - 2026-09-22
- **Legacy H5P ("Interactive content", mod_hvp) now covered.** Its durable xAPI
  event store (`hvp_events`) is harvested for participation dates, so historic
  engagement with old H5P activities appears alongside everything else. (New H5P
  via mod_h5pactivity was already covered.) Validated against PostgreSQL.

## [v1.9.5] - 2026-09-22
- **More robust auto-discovery of custom activity tables.** The attempt harvester
  now finds a module's instance column by testing which column actually resolves
  to a course module, instead of guessing from the name — so activities whose
  attempt table uses a non-standard key (e.g. `scenarioid`, `workbookid`) are now
  picked up correctly. It also harvests tables that store the course-module id
  directly (a `cmid` column). Both join modes validated against PostgreSQL, and a
  column that does not resolve is never used, so wrong data can't be produced.

## [v1.9.4] - 2026-09-22
- **Deep history for activities that don't track completion, too.** The harvester
  now also reads each activity module's own durable attempt/submission table
  (assignment submissions, quiz attempts, lesson timers, H5P attempts, and more).
  Core modules are covered by a curated map; any other installed activity —
  including third-party/custom ones — is discovered automatically at runtime (its
  per-user attempt table, timestamp and instance column), so the plugin needs no
  knowledge of a site's custom activities. Each source has its own high-water
  mark, so records are counted exactly once. Instance→module mapping validated
  against PostgreSQL (a wrong guess yields no rows, never wrong rows).
- **Funding "Activities" count now matches the drill-down.** The Activities figure
  on the Funding participation report is now the count of distinct activities in
  the durable participation table, so it lines up exactly with the number of rows
  in the Student activity report you reach by clicking it. Falls back safely to
  the raw record count if the participation table is absent.

## [v1.9.3] - 2026-09-22
- **Historic participation now shows, not just from install onward.** The
  participation harvester now also reads `course_modules_completion`, which Moodle
  never purges — so an activity a learner completed years ago still appears as
  participation evidence, with its real completion date. This works for every
  completion-tracked activity type (core and contrib), keyed directly by the
  course module, and merges with the log-harvested data (extending the
  first-participation date backwards where an older completion exists). Join and
  backward-date-extension validated against PostgreSQL. On upgrade, run the
  harvest task once (or wait for the hourly schedule) to pull in the back history.

## [v1.9.2] - 2026-09-22
- **Fix (scope safety):** the Student activity drill-down report is now never
  served from the shared short-lived result cache. Because it is keyed by request
  parameters (the learner and course) and scoped to the viewer rather than by the
  filter set, a cached result could otherwise have been shown to a different
  viewer within the cache window. Request-scoped reports now always run fresh, so
  the per-viewer scope check always applies.
- **Fix:** the PDF export of the Student activity report now carries the learner
  and course selectors, so the exported PDF matches the on-screen table instead of
  coming back empty.

## [v1.9.1] - 2026-09-22
- **Consistent filter order across every report.** Wherever a report offers the
  Category, Course and Group filters, they now appear in that order (Category →
  Course → Group), with the date range last — matching the dependent-dropdown
  chain so the filters read top-to-bottom the way you drill. No behaviour change
  beyond ordering; the dynamic dependency and full-set search already applied to
  every report.

## [v1.9.0] - 2026-09-22
- **Durable activity-participation evidence.** A new hourly harvester copies each
  student's activity participation out of the standard log into a permanent Beacon
  table (`local_beacon_participation`) *before* Moodle purges the log — so the
  evidence survives well beyond the log-retention window. It is universal: because
  it reads the log at module context, it captures participation in **every**
  activity type, core and contrib alike (quizzes, assignments, H5P, SCORM, and any
  custom activity), with no per-plugin configuration. On a fresh install it
  backfills from whatever logs still exist, then keeps pace hourly from a
  high-water mark (no double-counting). Aggregation and incremental-merge SQL
  validated against PostgreSQL.
- **New "Student activity" drill-down report.** Clicking the Activities count on
  the Funding participation report opens a per-learner, per-course table of every
  activity they participated in — activity (deep-linked into Moodle), type, first
  participated, last participated and interaction count — sortable, searchable and
  CSV/PDF-exportable like every other report. Scope-enforced: a teacher can only
  open it for learners they teach; the report is hidden from the library and
  reachable only via the drill-down link.

## [v1.8.8] - 2026-09-22
- **Plain-English hover text on every report column header.** Hovering any column
  header (or its small "?" marker) now shows a one-line description of exactly what
  that column is counting or measuring — e.g. "Course views (12 mo)" explains that
  older views aren't counted because Moodle only keeps detailed logs for a limited
  time. Every column across every report has one.

## [v1.8.7] - 2026-09-22
- **Dynamic dependent filters on every report.** The Course filter now narrows to
  the categories you've picked, and the Group filter narrows to the courses you've
  picked (Category → Course → Group). Choosing a parent hides — and unticks —
  options that no longer apply. Pure enhancement: with no JavaScript every option
  still shows and the server still constrains the results.
- **In-table search now covers the whole result set.** The "Search this report"
  box previously only searched the first 200 rows, so a learner further down the
  list wouldn't appear until you filtered first. The table now loads the full
  working set (the same cap the CSV/PDF export uses), so typing a learner, course
  or assignment name finds them directly — no need to filter first.
- **Marking queue filters expanded.** Both the "My marking queue" and site-wide
  "Marking queue" reports gain Category, Course, Group, Cohort and (admins only) a
  **Trainer** filter, ordered Category → Course → Group. The Trainer filter narrows
  to the submissions a chosen trainer is responsible for (editing teacher → whole
  course; non-editing teacher → learners in their groups); its scope SQL is
  validated against PostgreSQL.
- **Filter options are scoped to the viewer on the marking queue.** A non-editing
  teacher sees only the categories, courses and groups they teach in the filter
  dropdowns (and no Trainer filter) — matching the rows they can already see. Site
  admins see everything.

## [v1.8.6] - 2026-09-22
- **KPI cut-offs are now editable by a site admin.** A new **Beacon KPI targets**
  settings page (Site administration → Plugins → Local plugins) lists every KPI
  gauge with three editable fields — target, on-target cut-off (green) and
  near-target cut-off (amber) — each pre-filled with the shipped default. The
  gauge reads the admin's value if set and falls back to the default otherwise.
  Because only the colour banding depends on the cut-offs (never the stored
  numbers), changes take effect immediately with no cache rebuild. Override
  resolution unit-tested (unset/empty/non-numeric fall back to default; zero is a
  valid override; stat cards without a target are never affected).

## [v1.8.5] - 2026-09-22
- **Marking queue rows now deep-link into Moodle.** On both the "My marking queue"
  and site-wide "Marking queue" reports, the learner name opens their profile, the
  course name opens the course, the assignment opens its page, and the submission
  date opens the grading screen for that learner — so a trainer can click a date
  and go straight to marking that submission. Previously clicking a value only
  applied a table column filter. Column header filtering is unchanged. Link joins
  validated against PostgreSQL (correct module id, no row multiplication).
- **Removed the dashboard marking badge and its show/hide setting.** Sites link to
  the marking queue from their own quick-links dashboard instead. The daily marking
  digest email (and its on/off setting) is unchanged.

## [v1.8.4] - 2026-09-22
- New **Longest marking wait** stat card (Assessment): how many days the oldest
  still-unmarked submission has been waiting — an early warning that marking is
  falling behind. Lower is better; reads 0 when nothing is waiting.
- New **Marked within 7 days** KPI gauge (Assessment): the share of graded
  submissions that were marked within a week of being submitted — a turnaround
  service level, distinct from the existing "Feedback rate" (which measures whether
  work is eventually graded, not how quickly). Both link through to the marking
  queue report and are enabled on existing sites by the upgrade step. Stat and KPI
  SQL validated against PostgreSQL.

## [v1.8.3] - 2026-09-22
- **Marking-queue dashboard badge**: when enabled, each trainer's Beacon navigation
  link shows a count of submitted assessments still waiting for them to mark (e.g.
  "Beacon (7)"). The count is read from an hourly precomputed cache
  (`local_beacon_marking`) — one indexed row per user — so it adds no cost to page
  loads, unlike the core Grade Me block. New admin setting **Show marking-queue
  badge** turns it on or off (on by default).
- **Daily marking digest email**: an optional per-trainer daily email with the
  waiting count and a link straight to their My marking queue report. Contains only
  the count and a link — never learner data — so no per-row capability check is
  needed. New admin setting **Send daily marking digest email** (on by default).
- Both are driven by a scheduled task that recomputes each trainer's count with
  that trainer's own scope (editing teachers → whole course; non-editing teachers →
  their groups), so a cached figure can only ever reflect what that trainer may
  mark. Scope and count SQL validated against PostgreSQL.

## [v1.8.2] - 2026-09-22
- New **My marking queue** report: submitted assessments still awaiting marking, scoped to
  the viewer — administrators see all, editing teachers see their whole course, and
  non-editing teachers see only learners in the groups they belong to. Independent of due
  dates, so it works for rolling intakes (where the core Grade Me block does not). Scope
  SQL validated against PostgreSQL. Optimised: it filters to the small unmarked set first,
  then applies scope.

## [v1.8.1] - 2026-09-17
- New participation feature (this is the released form of the work drafted as the
  unreleased 1.8.0): **Funding participation evidence** report (durable — completion,
  submission, quiz-attempt and forum records, works beyond log retention), **Course
  access (recent)** report (standard log), plus **Participating learners** stat card and
  **Participation rate** KPI gauge. Report/stat/KPI SQL validated against PostgreSQL.
- Documentation aligned: README version and report count (28) updated to match the
  release.

## [v1.8.0] - 2026-09-17
- New **Funding participation evidence** report (Compliance): one row per enrolled learner
  per course showing first/last activity, distinct active days and activities engaged —
  built from durable completion, submission, quiz-attempt and forum records, so it works
  for the full life of a course, beyond the site log-retention window. Where the standard
  log is still present it also shows recent course views. Full feature parity (filters,
  saved views, scheduled email, CSV/PDF, search/sort).
- New **Course access (recent)** report (Engagement): recent access from the standard log
  (first/last access, active days, course views, total events) — captures passive viewing
  the durable report does not; requires the standard log store.
- New **Participating learners** stat card and **Participation rate** KPI gauge, both drawn
  from the same durable activity records (log-independent). All new items are enabled on
  existing sites by the upgrade step. Report/stat/KPI SQL validated against PostgreSQL.

## [v1.7.9] - 2026-08-11
- Release version bump to resolve an immutable v1.7.8 Git tag conflict. Plugin code is
  identical to 1.7.8: the fullname() name-fields fix (no per-row debugging) and the
  portable unique row key on the enrolment reports (no dropped rows).

## [v1.7.8] - 2026-08-11
- Fixed developer debugging warnings ("missing name fields") that appeared once per
  row on every report: all fullname() calls now receive a user object carrying every
  Moodle name field (firstname, lastname, firstnamephonetic, lastnamephonetic,
  middlename, alternatename).
- Fixed silent under-reporting in the per-enrolment reports (course completion,
  activity completion, course progress): each row now uses a database-portable unique
  key, so learners enrolled in more than one course are no longer collapsed into a
  single row by get_records_sql().

## [v1.7.7] - 2026-08-11
- Adopted the canonical 10-digit YYYYMMDDXX version format and renumbered the
  db/upgrade.php savepoints to match, so the plugin version is always greater than or
  equal to the highest upgrade savepoint (resolves the Marketplace savepoints check).

## [v1.7.4] - 2026-08-11
- Security: replaced all PARAM_RAW/PARAM_RAW_TRIMMED request parameters with strict
  types (PARAM_TEXT for dates and validated email/recipient fields).
- Language file: reverted long strings to single-line assignments (no concatenation).
- Internationalisation: moved user-facing JavaScript text into language strings loaded
  via core/str.
- Added the required cachedef_reports language string for the db/caches.php definition.

## [v1.7.3] - 2026-07-31
- Wrapped all PHP lines to 180 characters or fewer for a clean Moodle CodeSniffer run.
- Removed a stray, non-standard build-metadata file from the package.
- Brought the changelog up to date through the 1.3-1.7 series.

## [v1.7.0] - 2026-07-31
- Full role-aware access: administrators and managers see the site-wide dashboard;
  teachers opening Beacon inside a course see only that course's data (hard-locked -
  they cannot reach another course); learners get a personal "My reports" view bound
  to their own account and can never see anyone else's data.
- Per-user cache isolation for personal reports (no cross-user data bleed).
- Saved views, and scheduled email delivery of reports using the site's existing
  Moodle SMTP configuration.
- "Request a report" restricted to site administrators.
- Reports link placement is configurable: main navigation, site home, dashboard or
  my courses.
- Full Moodle Privacy (GDPR) provider; PHPUnit tests; moodle-plugin-ci workflow;
  thirdpartylibs.xml; retired the obsolete setup.php (now configure.php).

## [v1.3.0] - 2026-07-31
- Every report gained a server-side filter engine (entity, date and band filters)
  with active-filter chips and per-facet counts; totals always match the active
  filters via a shared query body.
- Added dark mode, per-column show/hide controls, in-cell distribution bars,
  drill-to-filter on click, and bulk export/email of selected rows.

## [v1.2.0] - 2026-07-30
- Rebuilt the stat/KPI detail pages: a plain-English verdict, four stat tiles
  (current/target/gap/trend), and a "Where it stands" value-on-scale bar.
- Each stat/KPI links straight to the report that lists the learners behind the number.

## [v1.1.2] - 2026-07-30
- Fixed the "Choose what shows" setup page erroring (missing adminlib include).
- Interactive-controls theme firewall: buttons, sort/filter controls, the filter
  dropdown and chips keep Beacon's own colours, backgrounds and hover/focus states.
- Filter dropdown anchored inside Beacon with a solid background and styled controls.
- Fixed the chip remove alignment and added the missing "Status" string.
- All reports, stat cards and KPI gauges are enabled by default; existing installs are
  switched to the full set on upgrade.

## [v1.1.1] - 2026-07-30
- Beacon paints its own soft-grey canvas so the white cards lift off it whatever the
  Moodle theme's content background is. Display-only change.

## [v1.1.0] - 2026-07-30
- Stat cards and KPI gauges are served from a precomputed cache table
  (local_beacon_metric_cache), refreshed hourly by the scheduled task.
- The five heaviest reports were rewritten from per-row correlated subqueries to
  single pre-aggregated joins.
- Report results are cached briefly (MUC, 120s) so re-opening, paging or exporting the
  same view does not re-run the query.

## [v1.0.0] - 2026-07-30
- 26 ready-made reports across People, Progress, Assessment, Engagement, Compliance and
  Operations, each backed by one concrete, validated SQL query and gated to the
  subsystems the site actually has.
- 10 stat cards and 6 KPI gauges, auto-displayed in a premium library.
- Interactive report tables: instant search, type-aware column sorting, faceted
  per-column filters, active-filter chips, CSV export and a branded PDF download.
- Set-up checklist, request-a-report form.
- SCORM report uses the Moodle 4.3+ tracking schema (scorm_attempt).

## [v0.4.0] - 2026-07-29
- Expanded the report catalogue to 26 ready-made reports covering the reports Moodle
  admins most commonly request.

## [v0.3.0] - 2026-07-29
- Complete rebuild. Curated catalogue of stat cards, KPI gauges and ready-made reports,
  each backed by one concrete, validated SQL query.
- Removed the old recipe/report-builder engine and its broken query planner.
