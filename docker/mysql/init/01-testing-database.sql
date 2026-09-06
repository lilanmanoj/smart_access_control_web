-- The feature suite runs against a separate schema so a test run never
-- truncates the development data.
CREATE DATABASE IF NOT EXISTS `smart_access_testing`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON `smart_access_testing`.* TO 'smart_access'@'%';
FLUSH PRIVILEGES;
