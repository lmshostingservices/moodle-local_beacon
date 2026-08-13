<p align="center">
  <a href="https://lmshostingservices.com">
    <img src="https://raw.githubusercontent.com/lmshostingservices/lms-labs/main/attached_assets/lms-hosting-logo.png" alt="LMS Hosting Services" height="60">
  </a>
</p>

> **LMS Labs** is the Moodle plugin division of [LMS Hosting Services](https://lmshostingservices.com) — Australia's Moodle™ Certified Partner.

---

# Beacon — Reports & Analytics for Moodle

**Version:** 1.7.6 · **Component:** `local_beacon` · **Maturity:** Stable  
**Price:** $50 USD one-time per site · lifetime updates · no subscription  
**Compatibility:** Moodle™ 4.4 – 5.1 · PHP 8.2+

---

## What is Beacon?

Beacon is a curated reporting and analytics plugin for Moodle. It ships with 26 hand-written, grain-aware SQL reports covering learner progress, course completion, quiz performance, attendance, grades, and enrolment — all rendered in a clean dashboard with server-side filters, CSV/PDF export, and scheduled email delivery.

**Key features:**
- 26 pre-built reports covering the full Moodle data model
- Role-aware views: admins see site-wide data; teachers are hard-locked to their course; learners see only their own data
- Server-side filter engine with active-filter chips and per-facet counts
- Scheduled report delivery via Moodle's built-in SMTP (no external service)
- Saved views — bookmark a filtered report for quick access
- CSV and PDF export; bulk email selected learner rows
- Stat cards and KPI gauges served from a precomputed cache (no per-request heavy queries)
- Click-to-filter: click any filterable column value to instantly narrow the table
- Distribution bar: live green/amber/red breakdown for status columns
- Full Moodle Privacy API (GDPR) provider
- PHPUnit tests included; moodle-plugin-ci workflow included

**What Beacon is not:**
- Not a custom report builder (it is a curated set — you cannot write your own SQL)
- Not a data warehouse or BI tool
- Not a replacement for Moodle's core report builder (it complements it with pre-built, grain-correct queries)

---

## Installation

1. Download the ZIP from [lms-labs.com](https://lms-labs.com/docs/beacon)
2. In Moodle: **Site administration → Plugins → Install plugins → Upload ZIP**
3. Complete the on-screen upgrade steps
4. Go to **Site administration → Plugins → Local plugins → Beacon** to configure

---

## Roles & Privacy

| Role | What they see |
|------|---------------|
| Admin / Manager | Full site-wide reports and dashboard |
| Teacher (inside a course) | That course's data only — hard-locked, cannot reach other courses |
| Learner | Personal "My reports" view bound to their own account |

Personal report cache keys include `_u{userid}` — no cross-user data bleed is possible.

All user data stored by Beacon (requests, snapshots, deliveries, saved views) is covered by the full Moodle Privacy (GDPR) provider included in `classes/privacy/provider.php`.

---

## Source, licence & distribution

Beacon is free software licensed under the **GNU General Public Licence v3 or later**.  
Source code: [github.com/lmshostingservices/moodle-local_beacon](https://github.com/lmshostingservices/moodle-local_beacon)  
Bug tracker: [github.com/lmshostingservices/moodle-local_beacon/issues](https://github.com/lmshostingservices/moodle-local_beacon/issues)

You receive the source under the GPL and may redistribute it freely. The $50 USD purchase covers the packaged, tested, kept-current download plus support — not an exclusive or non-redistributable licence.

---


## ⭐ Why this plugin is unlike anything else available

**Grain-aware SQL that cannot produce wrong percentages**

- Every other Moodle reporting plugin runs JOIN-heavy queries that multiply rows silently. A learner with 3 quiz attempts appears 3 times, inflating completion rates to 200%+ with no warning. Beacon's grain engine is different: it knows whether your question is about people (learner grain), learner×course pairs (enrolment grain), or course-level rollups (course grain), and writes the SQL accordingly. Row-multiplication is architecturally impossible.
- Hard role isolation enforced inside every SQL query — not just in the UI. Teachers are hard-locked to their course via filterset::lock_course() baked into the WHERE clause. A teacher cannot reach another course's data even by constructing a URL manually.
- Per-user cache key (‌_u{userid}) prevents cross-user data bleed from shared application caches. Personal report caches are always user-scoped; user A can never receive user B's cached result.
- 26 hand-written, audited SQL reports — not a drag-and-drop query builder generating arbitrary SQL. Every report was designed for a specific question a Moodle administrator or RTO actually asks.

## Support

- **Portal:** [lms-labs.com](https://lms-labs.com)
- **Email:** support@lmshostingservices.com
- **Website:** [lmshostingservices.com](https://lmshostingservices.com)

LMS Labs is the plugin division of LMS Hosting Services, Australia's Moodle™ Certified Partner.
