CREATE TABLE IF NOT EXISTS oauth_apps (
    instance      TEXT PRIMARY KEY,
    client_id     TEXT NOT NULL,
    client_secret TEXT NOT NULL,
    created_at    INTEGER NOT NULL
);
