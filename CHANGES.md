# Changelog

## 1.7.8 — Report correctness fixes (11 Aug 2026)
- Fixed developer debugging warnings ("missing name fields") that appeared once per
  row on every report: all fullname() calls now receive a user object carrying every
  Moodle name field.
- Fixed silent under-reporting in the per-enrolment reports (course completion,
  activity completion, course progress): each row now has a unique key, so learners
  enrolled in more than one course are no longer collapsed into a single row.


## 1.7.3 — Marketplace-ready polish (31 Jul 2026)
- Wrapped all PHP lines to ≤180 characters for a clean Moodle CodeSniffer (phpcs) run.
- Removed a stray, non-standard build-metadata file from the package.
- Brought this changelog up to date through the 1.3–1.7 series.

## 1.7.0 — Roles, saved views & scheduled delivery (31 Jul 2026)
- Full role-aware access: administrators and managers see the site-wide dashboard;
  teachers opening Beacon inside a course see only that course's data (hard-locked —
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

## 1.3.0 — Server-side filters (31 Jul 2026)
- Every report gained a server-side filter engine (entity, date and band filters)
  with active-filter chips and per-facet counts; totals always match the active
  filters via a shared query body.
- Added dark mode, per-column show/hide controls, in-cell distribution bars,
  drill-to-filter on click, and bulk export/email of selected rows.

## 1.2.0 — Detail pages (30 Jul 2026)
- Rebuilt the stat/KPI detail pages so they earn their space: a plain-English
  verdict, four stat tiles (current/target/gap/trend), and a real "Where it
  stands" value-on-scale bar that plots your figure and target against the
  red/amber/green zones with a legend — replacing the static three-bar block.
- Each stat/KPI now links straight to the report that lists the learners behind
  the number (e.g. Course completion rate to the Course completion report).


## 1.1.2 — Fixes (30 Jul 2026)
- Fixed the "Choose what shows" setup page erroring (missing adminlib include).
- Interactive-controls theme firewall: buttons, sort/filter controls, the filter
  dropdown and chips now keep Beacon's own colours, backgrounds and hover/focus
  states — no more black text on the green buttons or dark fills from the theme.
- Filter dropdown anchored inside Beacon so it has a solid white background and
  styled Apply/Clear buttons and facet rows.
- Fixed the chip remove (x) vertical alignment and added the missing "Status"
  string ([[status]]).
- All 26 reports (and every stat card and KPI gauge) are now enabled by default;
  existing installs are switched to the full set on upgrade.


## 1.1.1 — Visual (30 Jul 2026)
- Beacon now paints its own soft-grey canvas (with the subtle colour wash from
  the design), so the white stat/KPI/report cards lift off it whatever the
  Moodle theme's content background is. Display-only change.


## 1.1.0 — Performance (30 Jul 2026)
- Stat cards and KPI gauges are now served from a precomputed cache table
  (local_beacon_metric_cache), refreshed hourly by the scheduled task — the
  library reads one indexed query instead of running ~16 live queries per load.
  Cold values self-heal (computed once, then cached).
- The five heaviest reports (learner progress, course summary, course progress,
  activity completion, course catalogue health) were rewritten from per-row
  correlated subqueries to single pre-aggregated joins — from O(rows x lookups)
  to O(rows) at any site size.
- Report results are cached briefly (MUC, 120s) so re-opening, paging or
  exporting the same view does not re-run the query.


## 1.0.0 — First stable release (30 Jul 2026)
- 26 ready-made reports across People, Progress, Assessment, Engagement,
  Compliance and Operations, each backed by one concrete, validated SQL query
  and gated to the subsystems the site actually has.
- 10 stat cards and 6 KPI gauges, auto-displayed in a premium library.
- World-class interactive report tables: instant search, type-aware column
  sorting, faceted per-column filters, active-filter chips, CSV export and a
  branded PDF download for every report.
- Set-up checklist (real examples from live data), request-a-report form
  (emails support, 48-hour turnaround).
- Premium teal/emerald UI with a theme "firewall" and full mobile support.
- SCORM report uses the Moodle 4.3+ tracking schema (scorm_attempt).
- Version format aligned to the ecosystem (YYYYMMDDNNN); db/upgrade.php retires
  the obsolete 0.2.x recipe-builder tables on upgrade.


## 0.4.0
- Expanded the report catalogue to 26 ready-made reports, based on the reports
  Moodle admins most commonly request: learner progress, course summary, course
  progress, quiz results, assignment status, SCORM attempts, inactive learners,
  never-logged-in, new users, cohort membership, staff & roles, competency
  progress, badges awarded and policy acceptance — each gated to the subsystems
  the site actually has.


## 0.3.0
- Complete rebuild. Curated catalogue of stat cards, KPI gauges and ready-made
  reports, each backed by one concrete, validated SQL query.
- New auto-displaying library, per-item detail pages with back navigation.
- World-class interactive report tables: search, sort, faceted filters, chips,
  CSV export and branded PDF download.
- Set-up checklist with live examples; "request a report" form.
- Premium teal/emerald UI with a theme-firewall and full mobile support.
- Removed the old recipe/report-builder engine and its broken query planner.
