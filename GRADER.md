# Grader Guide
## Credentials

| Role | Username | Password | Access |
|------|----------|----------|--------|
| Super Admin | `grader` | `cse135hw3grader` | Everything |
| Super Admin | `admin` | `admin2026` | Everything |
| Analyst (all sections) | `analyst` | `analyst2026` | Dashboard + all 4 sections + PDF export + save reports |
| Analyst (limited) | `sam` | `sam2026` | Traffic + Performance only|
| Viewer | `viewer` | `viewer2026` | Saved reports only |

---

## Grader Walkthrough Scenario

### Part 1 — Viewer experience (viewer / viewer2026)

1. Go to https://reporting.maxk.site/ — you should see the login page.
2. Log in as `viewer` / `viewer2026`.
3. You are redirected to `/saved-reports.php` — this is the viewer's home (they cannot reach the dashboard).
4. Browse the list of saved reports. Click any card to read a full saved report with analyst comments.
5. Try to force-browse to https://reporting.maxk.site/dashboard.php — expect a **403 Forbidden** page with a role-aware message.
6. Click **Sign Out**.

### Part 2 — Analyst with section restrictions (sam / sam2026)

1. Log in as `sam` / `sam2026`.
2. You land on the dashboard. The **Errors** section card is greyed out ("No access").
3. Click **Traffic** — you reach `/reports/traffic.php`. Explore the 30-day line chart, top pages table, referrers, and the analyst comment box.
4. Save a report: fill in a title and comment in the "Save Report" form, then click **Save**. You should see a success banner.
5. Click **Performance** — you reach `/reports/performance.php`. Browse Core Web Vitals (LCP/CLS/INP) with color-coded Good/Needs Work/Poor indicators.
6. Try to force-browse to https://reporting.maxk.site/reports/errors.php — expect a **403 Forbidden** page because sam doesn't have errors access.
7. Click **Sign Out**.

### Part 3 — Full analyst (analyst / analyst2026)

1. Log in as `analyst` / `analyst2026`.
2. All three section cards are active on the dashboard.
3. Visit `/reports/errors.php` — see the error type doughnut chart, daily counts bar chart, top error messages table, and full paginated error log.
4. Click **↓ Export PDF** on any report page — a PDF file downloads. Open it and verify it contains a summary stats section and a data table.
5. Go to `/saved-reports.php` — you can see all saved reports.
6. Go to `/saved.php` — you can see all saved PDFs.
7. Click **Sign Out**.

### Part 4 — Super admin (grader / cse135hw3grader)

1. Log in as `grader` / `cse135hw3grader`.
2. The nav shows an extra **Users** link. Click it → `/admin/users.php`.
3. Create a new analyst user, assign only the "traffic" section, save.
4. Verify the new user appears in the table with correct role and sections.
5. Log in with the created user information. You will see that only traffic data is enabled.

---

## Known Limitations & Concerns

### 1. Performance Report — Poor LCP on `/product.html`

The Performance report shows a **Poor LCP** metric for `/product.html`. This is not a limitation of the analytics platform itself, but rather reflects the actual performance characteristics of the tracked website. The `/product.html` page is sourced from the course-provided test site (`test.maxk.site`), which was already suboptimal for performance. The analytics platform correctly measures and reports this real-world data.

**Accountability**: The analytics system is working as intended—it faithfully captures actual performance metrics. No correction is needed.

### 2. PDF Export — Visual Charts Not Included

The **PDF export** currently includes:
- Summary statistics tables (counts, metrics, percentages)
- Data tables with detailed breakdowns

However, it **does not render visual charts** (line graphs, doughnut charts, bar charts). Instead, users receive the underlying data in tabular form, which maintains full information integrity but lacks the visual polish of the dashboard.

**Rationale**: PDF generation for interactive charts requires additional dependencies (chart rendering to image format). The current implementation prioritizes data accuracy and delivery time.

**With more time**: I would integrate a chart-to-image library (e.g., Canvas/Playwright-based rendering) to embed charts directly into PDFs, creating a more visually representative export.

---

## Extra Credit Documentation

- **Saved Reports System** — Analysts can save reports with titles and comments, so data and decisions don't disappear after viewing. This keeps a permanent record of findings and explanations like real business-world

- **Saved PDFs with Persistent History** — Exported PDFs are stored with accessible URLs at `/saved.php`, not discarded after download. 

- **Color-coded Core Web Vitals** — Performance metrics use visual status (Good/Needs Work/Poor) instead of raw numbers, enabling users to scan health at a glance.
