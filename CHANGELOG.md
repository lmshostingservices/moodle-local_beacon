# Changelog

All notable changes to the Beacon (local_beacon) plugin are documented here.

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
