# Analytic Platform

**Max Kim**

---

## Links

- **Deployed site**: https://reporting.maxk.site/
- **Data collection site**: https://test.maxk.site/
- **Source repository**: https://github.com/Max010123e0/backend-tracking-with-sql

---

### Architecture

Data flow:
  test.maxk.site  →  collector.maxk.site/api/log.php  →  MySQL  →  reporting.maxk.site
  (tracked site)      (ingestion endpoint)               (storage)   (dashboard)

---

## Use of AI

The initial structure of the PHP, JavaScript, and SQL files were initially built from the cse135.site tutorials and then adapted for this project. AI was used mainly as a development assistant for CSS styling, layout consistency, and implementation support during debugging of performance chart.

The collector stored vitals as nested JSON objects, but the SQL view was extracting the wrong paths, so the reporting query was reading 0 instead of the numeric values. AI helped catch a timing issue where vitals could be sent before LCP was recorded, which led to fixes in both the SQL view and collector logic.

---
