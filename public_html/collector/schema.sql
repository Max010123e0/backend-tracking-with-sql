-- Database schema for analytics collector
-- Run this file to set up your database

CREATE DATABASE IF NOT EXISTS analytics_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE analytics_db;

-- Main events table to store all analytics events
CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(100) NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    url VARCHAR(2048),
    timestamp DATETIME NOT NULL,
    data JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session (session_id),
    INDEX idx_event_type (event_type),
    INDEX idx_timestamp (timestamp),
    INDEX idx_url (url(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional: Separate table for sessions to track user sessions
CREATE TABLE IF NOT EXISTS sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(100) UNIQUE NOT NULL,
    first_seen DATETIME NOT NULL,
    last_seen DATETIME NOT NULL,
    page_count INT DEFAULT 0,
    INDEX idx_session_id (session_id),
    INDEX idx_first_seen (first_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create a view for easy querying of pageviews (includes both JS and no-JS pageviews)
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

-- Create a view for errors
CREATE OR REPLACE VIEW errors AS
SELECT 
    id,
    session_id,
    url,
    timestamp,
    JSON_EXTRACT(data, '$.error.type') as error_type,
    JSON_EXTRACT(data, '$.error.message') as error_message,
    JSON_EXTRACT(data, '$.error.source') as error_source,
    JSON_EXTRACT(data, '$.error.line') as error_line,
    JSON_EXTRACT(data, '$.error.stack') as error_stack
FROM events
WHERE event_type = 'error';

-- Create a view for vitals
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
