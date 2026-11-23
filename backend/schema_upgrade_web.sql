-- DayFX: upgrade an EXISTING database for the web app, Premium and affiliates.
-- New installs: just import schema.sql (it already includes this).
SET NAMES utf8mb4;

-- ---------------------------------------------------------------- web app, Premium, affiliates
ALTER TABLE users
  ADD COLUMN role ENUM('user','admin') NOT NULL DEFAULT 'user' AFTER password_hash,
  ADD COLUMN status ENUM('active','banned') NOT NULL DEFAULT 'active' AFTER role,
  ADD COLUMN premium_until DATETIME NULL AFTER elite_only,
  ADD COLUMN ref_code VARCHAR(16) NULL UNIQUE AFTER premium_until,
  ADD COLUMN referred_by INT UNSIGNED NULL AFTER ref_code,
  ADD COLUMN payout_details TEXT NULL AFTER referred_by,
  ADD COLUMN signup_source VARCHAR(10) NOT NULL DEFAULT 'app' AFTER payout_details,
  ADD COLUMN last_seen DATETIME NULL AFTER signup_source,
  MODIFY reset_code VARCHAR(255) NULL,
  ADD INDEX idx_referred (referred_by),
  ADD INDEX idx_premium (premium_until);

CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  reference VARCHAR(64) NOT NULL UNIQUE,
  plan VARCHAR(20) NOT NULL,               -- monthly | yearly | manual
  days SMALLINT UNSIGNED NOT NULL,
  amount INT UNSIGNED NOT NULL,            -- minor units (kobo / cents)
  currency CHAR(3) NOT NULL,
  status ENUM('pending','success','failed','refunded') NOT NULL DEFAULT 'pending',
  channel VARCHAR(30) NULL,
  gateway_response VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL,
  INDEX idx_user (user_id),
  INDEX idx_status (status, paid_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS commissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  affiliate_id INT UNSIGNED NOT NULL,
  payment_id INT UNSIGNED NOT NULL UNIQUE,
  referred_user_id INT UNSIGNED NULL,
  amount INT UNSIGNED NOT NULL,            -- minor units
  currency CHAR(3) NOT NULL,
  rate DECIMAL(5,2) NOT NULL,
  status ENUM('earned','void') NOT NULL DEFAULT 'earned',
  available_at DATETIME NOT NULL,          -- end of the holding period
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_aff (affiliate_id, status),
  FOREIGN KEY (affiliate_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payouts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  affiliate_id INT UNSIGNED NOT NULL,
  amount INT UNSIGNED NOT NULL,            -- minor units
  currency CHAR(3) NOT NULL,
  details TEXT NULL,                       -- bank details at the time of the request
  status ENUM('requested','paid','rejected') NOT NULL DEFAULT 'requested',
  admin_note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  INDEX idx_aff (affiliate_id, status),
  FOREIGN KEY (affiliate_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS referral_clicks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  referrer_id INT UNSIGNED NOT NULL,
  ip_hash CHAR(40) NOT NULL,
  click_date DATE NOT NULL,
  UNIQUE KEY uq_click (referrer_id, ip_hash, click_date),
  FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
