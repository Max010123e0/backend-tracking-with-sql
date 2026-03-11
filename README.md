# CSE 135 Analytics Platform — Final Project

**Max Kim**

---

## Links

- **Deployed site**: https://reporting.maxk.site/
- **Data collection site**: https://maxk.site/
- **Source repository**: *(add GitHub/GitLab URL here)*

---

## Technical Overview

A server-side analytics platform built with PHP 8.3 + MySQL on Apache2, with no heavy JavaScript frameworks — all data rendering is server-side with Chart.js 4.4 for visualizations only.

### Stack

| Layer | Choice | Reason |
|-------|--------|--------|
| Server language | PHP 8.3 | Runs natively on the host; no build step |
| Database | MySQL `analytics_db` | Matches the existing event collection schema |
| Charts | Chart.js 4.4 (CDN) | Lightweight; loaded from fast CDN |
| PDF export | TCPDF (Composer) | Pure PHP; no headless browser overhead |
| Auth | PHP sessions + bcrypt cost-12 | Simple, secure, no external dependencies |
| CSS | Custom (no framework) | Keeps the payload small |

### Architecture

```
/public_html
  login.php              ← entry point
  dashboard.php          ← analyst/superadmin home
  saved-reports.php      ← viewer home + all saved report views
  auth.php               ← central auth guard (requireRole, requireSection)
  includes/nav.php       ← shared navigation partial
  reports/
    traffic.php          ← Section 1: pageviews, top pages, referrers
    performance.php      ← Section 2: Core Web Vitals (LCP, CLS, INP)
    errors.php           ← Section 3: JS errors by type and frequency
  export/
    pdf.php              ← TCPDF PDF generator (streamed download)
  admin/
    users.php            ← superadmin user management (CRUD + section grants)
  403.php / 404.php      ← role-aware error pages
```

### Role Model

| Role | Access |
|------|--------|
| `superadmin` | Everything, including user management |
| `analyst` | Dashboard + assigned report sections only + PDF export + save reports |
| `viewer` | Saved reports only (read-only) |

Analysts are assigned per-section permissions (traffic, performance, errors, behavioral) individually in the admin panel.

---

## Use of AI

GitHub Copilot (Claude Sonnet) was used throughout this project for scaffolding, debugging, and boilerplate generation.

It was most useful for:
- Generating the DB schema and PHP auth logic scaffolding, then verifying and refining it
- Debugging PHP/MySQL mismatches (e.g., JSON column queries, missing PHP extensions)
- Writing TCPDF boilerplate for the PDF export feature

Key observations:
- AI is effective at the "typing" part of coding — connecting known interfaces and translating intent to syntax quickly
- It made errors requiring human judgment: using MySQL functions not available in the version (PERCENTILE_CONT), referencing wrong column names, assuming PHP extensions were installed (mbstring, curl) when they were not
- The biggest value was development speed; the biggest risk was over-trusting output that compiled fine but had wrong SQL logic

---

## Roadmap / Future Work

Given more time:

1. **Behavioral report** — the section exists in the permission system but `/reports/behavioral.php` is not implemented. Data is collected in the `events` table (clicks, scroll depth, rage-clicks).
2. **PDF saved to a URL** — currently PDFs are streamed as one-time downloads. A better UX would save the PDF to disk, attach its URL to the saved report record, and let viewers download it.
3. **Email delivery** — send a saved report PDF to a configured address via PHPMailer + SMTP.
4. **CSV export** — complement the PDF with a raw CSV download for analysts who want to process data independently.
5. **Date range picker** — replace the fixed 7/14/30/60/90-day dropdown with a true date range selector.
