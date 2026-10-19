-- Home: database tables. dev/install.php applies this (safe to run again).
-- All DATETIME columns are UTC. DATE columns are the person's local date.

CREATE TABLE IF NOT EXISTS user_level_settings (
    Id_user_level_settings INT NOT NULL PRIMARY KEY,
    display_name VARCHAR(32) NOT NULL,
    folder VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
    Id_admin_users INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(128) NOT NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(20) NULL,
    phone_verified_at DATETIME NULL,
    password VARCHAR(255) NULL,
    auth_level INT NOT NULL DEFAULT 10,
    first_name VARCHAR(64) NULL,
    last_name VARCHAR(64) NULL,
    title VARCHAR(64) NULL,
    profile_image VARCHAR(128) NULL,
    site_theme VARCHAR(16) NULL,
    timezone VARCHAR(64) NULL,
    hourly_rate DECIMAL(10,2) NULL,
    weekly_hours_goal DECIMAL(5,2) NULL,
    reset_password TINYINT(1) NOT NULL DEFAULT 0,
    reset_token VARCHAR(128) NULL,
    reset_expires DATETIME NULL,
    failed_logins INT NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    totp_secret VARCHAR(64) NULL,
    totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    notify_reminders TINYINT(1) NOT NULL DEFAULT 1,
    notify_digest TINYINT(1) NOT NULL DEFAULT 1,
    sms_reminders TINYINT(1) NOT NULL DEFAULT 0,
    sms_digest TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_login DATETIME NULL,
    created_at DATETIME NULL,
    UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    k VARCHAR(64) NOT NULL PRIMARY KEY,
    v TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
    k CHAR(64) NOT NULL PRIMARY KEY,
    hits INT NOT NULL DEFAULT 0,
    window_start INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    module VARCHAR(16) NOT NULL,
    text VARCHAR(255) NOT NULL,
    link VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_queue (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    to_email VARCHAR(190) NOT NULL,
    to_name VARCHAR(128) NULL,
    subject VARCHAR(255) NOT NULL,
    template VARCHAR(64) NOT NULL,
    data MEDIUMTEXT NULL,
    attempts INT NOT NULL DEFAULT 0,
    last_error VARCHAR(255) NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY idx_unsent (sent_at, attempts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Text messages waiting to go out (dev/cron.php sends them)
CREATE TABLE IF NOT EXISTS sms_queue (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    to_phone VARCHAR(20) NOT NULL,
    body VARCHAR(480) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    last_error VARCHAR(255) NULL,
    provider_id VARCHAR(64) NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY idx_unsent (sent_at, attempts),
    KEY idx_user_sent (user_id, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS migrations (
    name VARCHAR(128) NOT NULL PRIMARY KEY,
    ran_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== Vault =====================

-- One per person. The data key is random; it is stored only wrapped (encrypted) by a key
-- derived from the master password, and by a key derived from the recovery code.
CREATE TABLE IF NOT EXISTS vault_keys (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    salt VARBINARY(16) NOT NULL,
    opslimit INT UNSIGNED NOT NULL,
    memlimit BIGINT UNSIGNED NOT NULL,
    wrapped_key VARBINARY(128) NOT NULL,
    recovery_salt VARBINARY(16) NULL,
    recovery_wrapped_key VARBINARY(128) NULL,
    hint VARCHAR(128) NULL,
    auto_lock_minutes INT NOT NULL DEFAULT 15,
    created_at DATETIME NOT NULL,
    changed_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vault_folders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(64) NOT NULL,
    icon VARCHAR(16) NULL,
    sort INT NOT NULL DEFAULT 0,
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Everything a person would call secret (name, username, password, URL, notes, card numbers, 2FA secret)
-- lives encrypted in `data`. Only the item type, folder and dates are stored in the clear.
CREATE TABLE IF NOT EXISTS vault_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    folder_id INT UNSIGNED NULL,
    type VARCHAR(16) NOT NULL DEFAULT 'login',
    data MEDIUMBLOB NOT NULL,
    favorite TINYINT(1) NOT NULL DEFAULT 0,
    source VARCHAR(32) NULL,
    password_changed_at DATETIME NULL,
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_user (user_id, folder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Previous passwords, encrypted
CREATE TABLE IF NOT EXISTS vault_history (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_id INT UNSIGNED NOT NULL,
    data BLOB NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== Money =====================

CREATE TABLE IF NOT EXISTS fin_accounts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    type VARCHAR(16) NOT NULL DEFAULT 'checking',
    institution VARCHAR(80) NULL,
    last4 VARCHAR(4) NULL,
    opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    opening_date DATE NULL,
    credit_limit DECIMAL(12,2) NULL,
    color VARCHAR(9) NULL,
    in_net_worth TINYINT(1) NOT NULL DEFAULT 1,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    sort INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fin_categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    parent_id INT UNSIGNED NULL,
    name VARCHAR(64) NOT NULL,
    kind VARCHAR(8) NOT NULL DEFAULT 'expense',
    icon VARCHAR(16) NULL,
    color VARCHAR(9) NULL,
    budget DECIMAL(12,2) NULL,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    sort INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- amount: money in is positive, money out is negative. Transfers are two rows sharing transfer_group.
CREATE TABLE IF NOT EXISTS fin_transactions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    payee VARCHAR(128) NULL,
    memo VARCHAR(255) NULL,
    amount DECIMAL(12,2) NOT NULL,
    category_id INT UNSIGNED NULL,
    kind VARCHAR(10) NOT NULL DEFAULT 'expense',
    transfer_group VARCHAR(24) NULL,
    client_id INT UNSIGNED NULL,
    project_id INT UNSIGNED NULL,
    billable TINYINT(1) NOT NULL DEFAULT 0,
    invoice_id INT UNSIGNED NULL,
    invoice_payment_id INT UNSIGNED NULL,
    bill_id INT UNSIGNED NULL,
    receipt VARCHAR(128) NULL,
    tags VARCHAR(255) NULL,
    cleared TINYINT(1) NOT NULL DEFAULT 1,
    import_hash CHAR(40) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY idx_date (date),
    KEY idx_account (account_id, date),
    KEY idx_category (category_id, date),
    KEY idx_client (client_id),
    KEY idx_import (import_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fin_bills (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    variable_amount TINYINT(1) NOT NULL DEFAULT 0,
    frequency VARCHAR(12) NOT NULL DEFAULT 'monthly',
    next_due DATE NOT NULL,
    account_id INT UNSIGNED NULL,
    category_id INT UNSIGNED NULL,
    is_subscription TINYINT(1) NOT NULL DEFAULT 0,
    autopay TINYINT(1) NOT NULL DEFAULT 0,
    remind_days INT NOT NULL DEFAULT 3,
    url VARCHAR(255) NULL,
    notes VARCHAR(255) NULL,
    color VARCHAR(9) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_paid DATE NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fin_rules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    match_text VARCHAR(128) NOT NULL,
    category_id INT UNSIGNED NULL,
    rename_to VARCHAR(128) NULL,
    sort INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fin_goals (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    emoji VARCHAR(16) NULL,
    target DECIMAL(12,2) NOT NULL,
    saved DECIMAL(12,2) NOT NULL DEFAULT 0,
    account_id INT UNSIGNED NULL,
    target_date DATE NULL,
    color VARCHAR(9) NULL,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== Reminders =====================

CREATE TABLE IF NOT EXISTS reminder_lists (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    color VARCHAR(9) NULL,
    icon VARCHAR(16) NULL,
    sort INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reminders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    created_by INT UNSIGNED NULL,
    assigned_to INT UNSIGNED NULL,
    list_id INT UNSIGNED NULL,
    title VARCHAR(255) NOT NULL,
    notes TEXT NULL,
    due_date DATE NULL,
    due_time TIME NULL,
    due_at DATETIME NULL,
    repeat_unit VARCHAR(8) NULL,
    repeat_interval INT NOT NULL DEFAULT 1,
    repeat_days VARCHAR(32) NULL,
    repeat_from VARCHAR(10) NOT NULL DEFAULT 'due',
    repeat_until DATE NULL,
    priority TINYINT NOT NULL DEFAULT 0,
    status VARCHAR(8) NOT NULL DEFAULT 'open',
    snoozed_until DATETIME NULL,
    notify TINYINT(1) NOT NULL DEFAULT 1,
    notify_before INT NOT NULL DEFAULT 0,
    notified_at DATETIME NULL,
    link_type VARCHAR(16) NULL,
    link_id INT UNSIGNED NULL,
    completed_at DATETIME NULL,
    completed_count INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    KEY idx_open (status, due_date),
    KEY idx_notify (status, notify, notified_at, due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reminder_completions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reminder_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    due_date DATE NULL,
    completed_by INT UNSIGNED NULL,
    completed_at DATETIME NOT NULL,
    KEY idx_reminder (reminder_id),
    KEY idx_completed (completed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== Time tracking =====================

CREATE TABLE IF NOT EXISTS clients (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    contact_first VARCHAR(64) NULL,
    contact_last VARCHAR(64) NULL,
    email VARCHAR(190) NULL,
    cc_emails VARCHAR(255) NULL,
    phone VARCHAR(40) NULL,
    website VARCHAR(190) NULL,
    address1 VARCHAR(128) NULL,
    address2 VARCHAR(128) NULL,
    city VARCHAR(64) NULL,
    state VARCHAR(64) NULL,
    zip VARCHAR(16) NULL,
    country VARCHAR(64) NULL,
    currency VARCHAR(3) NULL,
    default_rate DECIMAL(10,2) NULL,
    payment_terms INT NULL,
    color VARCHAR(9) NULL,
    notes TEXT NULL,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- billing: hourly_project (one rate), hourly_service (rate per service), hourly_user (each person's rate),
--          flat_fee (fixed price, time is tracked but not billed per hour), non_billable
CREATE TABLE IF NOT EXISTS projects (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    name VARCHAR(128) NOT NULL,
    description TEXT NULL,
    billing VARCHAR(16) NOT NULL DEFAULT 'hourly_project',
    rate DECIMAL(10,2) NULL,
    flat_fee DECIMAL(12,2) NULL,
    flat_fee_invoice_id INT UNSIGNED NULL,
    budget_hours DECIMAL(8,2) NULL,
    budget_amount DECIMAL(12,2) NULL,
    due_date DATE NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'active',
    color VARCHAR(9) NULL,
    created_at DATETIME NOT NULL,
    KEY idx_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS services (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    rate DECIMAL(10,2) NULL,
    billable TINYINT(1) NOT NULL DEFAULT 1,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    sort INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A running timer is a row with started_at set and duration NULL.
CREATE TABLE IF NOT EXISTS time_entries (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    client_id INT UNSIGNED NULL,
    project_id INT UNSIGNED NULL,
    service_id INT UNSIGNED NULL,
    date DATE NOT NULL,
    started_at DATETIME NULL,
    duration INT NULL,
    notes TEXT NULL,
    billable TINYINT(1) NOT NULL DEFAULT 1,
    rate DECIMAL(10,2) NULL,
    invoice_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    KEY idx_user_date (user_id, date),
    KEY idx_project (project_id, date),
    KEY idx_client (client_id, date),
    KEY idx_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoices (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    number VARCHAR(32) NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'draft',
    issue_date DATE NOT NULL,
    due_date DATE NULL,
    po_number VARCHAR(64) NULL,
    title VARCHAR(128) NULL,
    notes TEXT NULL,
    terms TEXT NULL,
    currency VARCHAR(3) NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
    discount DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_name VARCHAR(32) NULL,
    tax_rate DECIMAL(6,3) NOT NULL DEFAULT 0,
    tax DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    public_token VARCHAR(48) NOT NULL,
    sent_at DATETIME NULL,
    viewed_at DATETIME NULL,
    paid_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_number (number),
    UNIQUE KEY uq_token (public_token),
    KEY idx_client (client_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_lines (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    sort INT NOT NULL DEFAULT 0,
    kind VARCHAR(8) NOT NULL DEFAULT 'item',
    description TEXT NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    rate DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    project_id INT UNSIGNED NULL,
    KEY idx_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_payments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    method VARCHAR(32) NULL,
    notes VARCHAR(255) NULL,
    transaction_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY idx_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
