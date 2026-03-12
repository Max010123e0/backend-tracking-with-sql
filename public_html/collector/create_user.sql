-- Create dedicated analytics user with limited privileges
-- Run this with: mysql -u root -p < create_user.sql

-- Create user (change the password!)
CREATE USER IF NOT EXISTS 'analytics_user'@'localhost' IDENTIFIED BY 'AnalyticsPass2026!';

-- Grant privileges ONLY on analytics_db
GRANT SELECT, INSERT, UPDATE, DELETE ON analytics_db.* TO 'analytics_user'@'localhost';

-- Apply changes
FLUSH PRIVILEGES;

-- Verify the user was created
SELECT User, Host FROM mysql.user WHERE User = 'analytics_user';
