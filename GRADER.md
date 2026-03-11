# Grader Guide — CSE 135 Analytics Platform

**URL**: https://reporting.maxk.site/

---

## Credentials

| Role | Username | Password | Access |
|------|----------|----------|--------|
| Super Admin | `grader` | `cse135hw3grader` | Everything |
| Super Admin | `admin` | `admin2026` | Everything |
| Analyst (all sections) | `analyst` | `analyst2026` | Dashboard + all 4 sections + PDF export + save reports |
| Analyst (limited) | `sam` | `sam2026` | Traffic + Performance only (errors → 403) |
| Viewer | `viewer` | `viewer2026` | Saved reports only |

---

## Grader Walkthrough Scenario

This scenario demonstrates all major features in a logical order. Estimated time: ~8 minutes.

### Part 1 — Viewer experience (viewer / viewer2026)

1. Go to https://reporting.maxk.site/ — you should see the login page.
2. Log in as `viewer` / `viewer2026`.
3. You are redirected to `/saved-reports.php` — this is the viewer's home (they cannot reach the dashboard).
4. Browse the list of saved reports. Click any card to read a full saved report with analyst comments.
5. Try to force-browse to https://reporting.maxk.site/dashboard.php — expect a **403 Forbidden** page with a role-aware message.
6. Click **Sign Out**.

### Part 2 — Analyst with section restrictions (sam / sam2026)

1. Log in as `sam` / `sam2026`.
2. You land on the dashboard. The **Errors** and **Behavioral** section cards are greyed out ("No access").
3. Click **Traffic** — you reach `/reports/traffic.php`. Explore the 30-day line chart, top pages table, referrers, and the analyst comment box.
4. Save a report: fill in a title and comment in the "Save Report" form, then click **Save**. You should see a success banner.
5. Click **Performance** — you reach `/reports/performance.php`. Browse Core Web Vitals (LCP/CLS/INP) with color-coded Good/Needs Work/Poor indicators.
6. Try to force-browse to https://reporting.maxk.site/reports/errors.php — expect a **403 Forbidden** page because sam doesn't have errors access.
7. Click **Sign Out**.

### Part 3 — Full analyst (analyst / analyst2026)

1. Log in as `analyst` / `analyst2026`.
2. All four section cards are active on the dashboard.
3. Visit `/reports/errors.php` — see the error type doughnut chart, daily counts bar chart, top error messages table, and full paginated error log.
4. Click **↓ Export PDF** on any report page — a PDF file downloads. Open it and verify it contains a summary stats section and a data table.
5. Go to `/saved-reports.php` — you can see all saved reports, edit your own, and delete them.
6. Click **Sign Out**.

### Part 4 — Super admin (grader / cse135hw3grader)

1. Log in as `grader` / `cse135hw3grader`.
2. The nav shows an extra **Users** link. Click it → `/admin/users.php`.
3. Create a new analyst user, assign only the "traffic" section, save.
4. Verify the new user appears in the table with correct role and sections.
5. Delete the test user you just created.
6. Try the PDF export on all three reports (Traffic, Performance, Errors) — all should download successfully.
7. Browse to `/saved-reports.php` and verify the grader can edit/delete any saved report (not just their own).

---

## Known Issues & Architecture Concerns

### Things I'm confident about
- Auth (`requireRole` / `requireSection`) is enforced server-side on every protected page. There is no client-side-only gate.
- SQL queries use prepared statements with bound parameters throughout — no raw interpolation into queries.
- Passwords are bcrypt-hashed at cost 12.

### Things I'm uncertain or concerned about

1. **Behavioral section is not implemented.**  
   The permission system supports it (you can grant an analyst the "behavioral" section), and the dashboard card exists, but `/reports/behavioral.php` does not exist. A grader navigating there will see a 404. I acknowledge this is incomplete — the other three sections are fully built.

2. **PDF export delivers a download, not a saved URL.**  
   The spec mentions "save it (accessible URL) or send it (email)." Our PDFs are streamed as `Content-Disposition: attachment` — they are not saved to disk with a shareable link. The viewer cannot download a PDF independently; they can only see the static saved-report page. A proper fix would store the PDF file and attach its path to the saved report record.

3. **No email delivery.**  
   Sending reports by email was not implemented. Would require SMTP configuration + PHPMailer.

4. **Chart.js fails with JavaScript disabled.**  
   The report pages include `<noscript>` warnings with a fallback message and an HTML data table. The tables are always rendered server-side, so the data is always visible — but the charts will not render without JS.

5. **Performance data may be sparse.**  
   Core Web Vitals (LCP, CLS, INP) are collected only from browsers that support the Web Vitals API and only for pages on `maxk.site`. If the grader visits while data is thin, the performance report may show few or zero rows.

6. **Session lifetime is PHP's default (~24 min idle).**  
   There is no explicit session expiry or "remember me" feature. If the grader leaves a tab open and returns later, they may be silently logged out and redirected to the login page.

7. **No CSRF tokens on forms.**  
   The save-report and analyst-comment forms do not include CSRF tokens. This is an acknowledged security gap appropriate for a course project but not for production.
