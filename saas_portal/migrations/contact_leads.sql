-- Adds public website contact submissions to the existing SaaS prospect register.
-- Run once in the target database before publishing the contact form.
ALTER TABLE `saas_companies_contact`
    MODIFY COLUMN `org_nr` VARCHAR(9) NULL DEFAULT NULL,
    MODIFY COLUMN `company_name` VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN `contact_first_name` VARCHAR(100) NULL AFTER `contact_person`,
    ADD COLUMN `contact_last_name` VARCHAR(100) NULL AFTER `contact_first_name`,
    ADD COLUMN `logo_url` VARCHAR(2048) NULL AFTER `website`;
