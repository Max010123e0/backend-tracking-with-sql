-- Update the pageviews view to include both pageview and pageview_nojs events
USE analytics_db;

CREATE OR REPLACE VIEW pageviews AS
SELECT 
    id,
    session_id,
    url,
    timestamp,
    event_type,
    JSON_EXTRACT(data, '$.title') as title,
    JSON_EXTRACT(data, '$.referrer') as referrer,
    JSON_EXTRACT(data, '$.technographics') as technographics,
    JSON_EXTRACT(data, '$.timing') as timing,
    JSON_EXTRACT(data, '$.resources') as resources,
    JSON_EXTRACT(data, '$.javascriptEnabled') as js_enabled
FROM events
WHERE event_type IN ('pageview', 'pageview_nojs');

CREATE OR REPLACE VIEW vitals AS
SELECT
    id,
    session_id,
    url,
    timestamp,
    JSON_EXTRACT(data, '$.vitals.lcp.value') as lcp,
    JSON_EXTRACT(data, '$.vitals.cls.value') as cls,
    JSON_EXTRACT(data, '$.vitals.inp.value') as inp,
    JSON_UNQUOTE(JSON_EXTRACT(data, '$.vitals.lcp.score')) as lcp_score,
    JSON_UNQUOTE(JSON_EXTRACT(data, '$.vitals.cls.score')) as cls_score,
    JSON_UNQUOTE(JSON_EXTRACT(data, '$.vitals.inp.score')) as inp_score
FROM events
WHERE event_type = 'vitals';
