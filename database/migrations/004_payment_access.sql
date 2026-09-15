CREATE TABLE IF NOT EXISTS payment_methods (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    account_title TEXT NOT NULL DEFAULT '',
    account_number TEXT NOT NULL DEFAULT '',
    iban TEXT NOT NULL DEFAULT '',
    instructions TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    enabled INTEGER NOT NULL DEFAULT 0 CHECK (enabled IN (0, 1)),
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS payment_submissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    course_id TEXT NOT NULL,
    offer_id TEXT NOT NULL,
    duration TEXT NOT NULL,
    payment_method TEXT NOT NULL,
    payer_name TEXT NOT NULL,
    payer_account TEXT NOT NULL,
    transaction_reference TEXT NOT NULL,
    amount_cents INTEGER NOT NULL CHECK (amount_cents >= 0),
    currency TEXT NOT NULL,
    paid_at TEXT NOT NULL,
    proof_path TEXT,
    note TEXT,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
    admin_note TEXT,
    reviewed_by INTEGER,
    reviewed_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_payment_submissions_status
    ON payment_submissions(status, created_at);

CREATE TABLE IF NOT EXISTS course_access (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    course_id TEXT NOT NULL,
    offer_id TEXT NOT NULL,
    source TEXT NOT NULL CHECK (source IN ('purchase', 'manual')),
    payment_submission_id INTEGER,
    starts_at TEXT NOT NULL,
    expires_at TEXT,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'revoked')),
    revoked_at TEXT,
    revoked_by INTEGER,
    granted_by INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_submission_id) REFERENCES payment_submissions(id) ON DELETE SET NULL,
    FOREIGN KEY (revoked_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_course_access_lookup
    ON course_access(user_id, course_id, offer_id, status, expires_at);

CREATE TABLE IF NOT EXISTS course_access_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    course_access_id INTEGER NOT NULL,
    actor_user_id INTEGER,
    event TEXT NOT NULL,
    note TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (course_access_id) REFERENCES course_access(id) ON DELETE CASCADE,
    FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
);

INSERT OR IGNORE INTO payment_methods
    (slug, name, instructions, notes, enabled, sort_order, created_at, updated_at)
VALUES
    ('bank_transfer', 'Bank transfer', 'Development payment method. Configure account details in the admin panel before production.', 'Development placeholder only.', 1, 10, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'), strftime('%Y-%m-%dT%H:%M:%fZ', 'now'));