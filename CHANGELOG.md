# Changelog

All notable changes to the Beacon (local_beacon) plugin are documented here.

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
