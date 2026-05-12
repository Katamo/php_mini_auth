# Upgrade guide

Steps to update an existing php_mini_auth installation to the current version.

---

## 1. Copy updated files to the project

From the php_mini_auth repo, copy these files over the existing ones:

```bash
cp php_mini_auth/auth/check.php      my-project/auth/check.php
cp php_mini_auth/auth/toggle.php     my-project/auth/toggle.php
cp php_mini_auth/login.example.html  my-project/login.example.html
```

`login.php`, `logout.php`, and `add-user.php` have not changed — skip them.

---

## 2. Update auth.config.json on the server

Add the `enabled` key to the live config file. SSH into the server and edit
`/var/www/name/auth/auth.config.json`:

```json
{
  "project_name": "...",
  "subtitle":     "...",
  "session_key":  "...",
  "users_file":   "...",
  "theme":        "...",
  "enabled":      true
}
```

The key defaults to `true` if absent, so existing sessions are not affected.
This step is optional but recommended to make the state explicit.

---

## 3. Update nginx config

Add one line to the sensitive-files block and reload nginx:

```nginx
location = /auth/add-user.php  { deny all; }
location = /auth/toggle.php    { deny all; }   ← add this line
location ~ /auth/.*\.json$     { deny all; }
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

---

## 4. Deploy the updated check.php

The build must copy the new `check.php` to `dist/auth/check.php`. If the project
has a build script, run it and deploy as usual. If files are deployed manually:

```bash
scp -i ~/.ssh/gcp_key auth/check.php user@ip:/var/www/name/auth/check.php
scp -i ~/.ssh/gcp_key auth/toggle.php user@ip:/var/www/name/auth/toggle.php
```

---

## 5. Clear saved browser passwords (one-time)

The `name` attributes of the login fields have changed (`u` → `{{session_key}}_u`, `p` → `{{session_key}}_p`). Browsers key saved passwords partly on field names, so existing saved credentials may not auto-fill after the update. Users will need to save their password once after logging in again. This is a one-time event and the benefit is that browsers will no longer suggest passwords from a different project.

---

## 6. Verify

Check that auth still works end-to-end, then test the toggle:

```bash
# Disable auth — site should become publicly accessible
php /var/www/name/auth/toggle.php --off

# Re-enable
php /var/www/name/auth/toggle.php --on
```

---

## What's new in this version

| Change | Details |
|--------|---------|
| `auth/toggle.php` | New CLI script to enable/disable auth without touching nginx |
| `auth/check.php` | Respects the new `enabled` flag in config; returns 200 for all requests when disabled |
| `auth.config.json` | New `"enabled": true` key (backwards-compatible — defaults to true if absent) |
| `login.html` | Password show/hide toggle button; unique `name` attributes per project (`{{session_key}}_u` / `{{session_key}}_p`) to prevent browser autofill cross-project confusion |
| `login.example.html` | Ready-to-use login page with warm theme already inlined; no build step needed |
| nginx config | `toggle.php` must be blocked alongside `add-user.php` |
