# pwbi Patch — Setup & Testing Guide

This patch adds two features to the `pwbi` Drupal module:

1. **Configurable API endpoint** — choose Commercial, GCC, GCC High, or DoD instead of the hardcoded commercial endpoint.
2. **Automatic embed token refresh** — silently renews the embed token before it expires so dashboards stay live for users who keep a tab open.

---

## Files Changed

| File | Change |
|------|--------|
| `src/Api/PowerBiClient.php` | Dynamic endpoint via Config API; `getApiRoot()` method replaces 8 hardcoded constants |
| `src/Form/PowerBiEmbedConfigForm.php` | Extends `ConfigFormBase`; adds 3 new admin fields |
| `src/Controller/PwbiTokenRefreshController.php` | **New** — JSON endpoint that issues a fresh embed token |
| `src/Commands/PwbiCommands.php` | **New** — Drush diagnostic commands |
| `src/PowerBiEmbed.php` | Returns `datasetId` in embed data so JS can pass it to the refresh endpoint |
| `src/Plugin/Field/FieldFormatter/PowerBiEmbedFormatter.php` | Passes `workspaceId`, `datasetId`, and refresh settings into `drupalSettings` |
| `components/pwbi-embed/pwbi-embed.js` | Adds `setInterval` polling (30s) with `checkTokenAndUpdate` / `updateToken` |
| `config/install/pwbi.settings.yml` | **New** — default config values |
| `config/schema/pwbi.schema.yml` | Adds `pwbi.settings` schema |
| `drush.services.yml` | **New** — registers Drush commands |
| `pwbi.routing.yml` | Adds `/pwbi/token-refresh/{workspace_id}/{report_id}` route |

---

## Installation

1. Copy the patched `pwbi/` directory over your existing `modules/contrib/pwbi/` installation, or apply as a Composer patch.
2. Rebuild the Drupal container and clear caches:

```bash
drush cr
```

3. Run database updates (adds the `pwbi.settings` config):

```bash
drush updb
```

---

## Configuration

Go to **Admin → Configuration → Power BI → Embed Settings**
(`/admin/config/pwbi/embed_settings`)

### API Endpoint

Select the endpoint that matches your Power BI tenant:

| Option | Endpoint |
|--------|----------|
| Commercial (default) | `https://api.powerbi.com` |
| US Government GCC | `https://api.powerbigov.us` |
| US Government GCC High | `https://api.high.powerbigov.us` |
| US DoD | `https://api.mil.powerbigov.us` |

> **OIG / GCC sites:** Select **US Government GCC**.

### Automatic Token Refresh

| Setting | Description | Default |
|---------|-------------|---------|
| Enable automatic embed token refresh | Turns refresh on site-wide | Off |
| Minutes before expiry to refresh | How early to request a new token | 10 |

Enable the feature and save. After a `drush cr`, the JS will start the 30-second polling interval on each embedded report's first render.

---

## Testing

The token refresh cycle has four layers. Test them in order — if an earlier layer fails, later layers cannot succeed.

---

### Layer 1 — OAuth2 handshake (Azure AD → Bearer token)

```bash
drush pwbi:auth-check
```

**What it tests:** Whether the service principal credentials (tenant, client ID, secret or cert) are valid and can obtain a Bearer token from Azure AD.

**Success output:**
```
Power BI OAuth2 Auth Check
[OK] OAuth2 handshake successful!

 ----------- ------------------------------------
  Property    Value
 ----------- ------------------------------------
  Token       eyJ0eXAiOiJKV1QiLCJub...
  Expires     2026-03-02 15:30:00 UTC (in 58 min)
  Is expired  No
 ----------- ------------------------------------
```

**Failure diagnoses:**

| Error | Likely cause |
|-------|-------------|
| `Failed to obtain OAuth2 token: ...` | Wrong credentials, expired cert, or wrong tenant |
| `OAuth2 handshake returned an empty token` | Auth succeeded but token payload is empty — check scope config |
| Token shows `Is expired: YES` | Cached stale token; Drupal State API holds the old one. Clear state: `drush php-eval "\Drupal::state()->delete('oauth2_client_access_token.pwbi_service_principal');"` then retry |

---

### Layer 2 — Embed token generation (Bearer token → Power BI API → embed token)

First, list all reports configured as Media entities on this site:

```bash
drush pwbi:list-reports
```

Output:
```
 Configured Power BI Reports
 ---------- ----------------------- -------------------------------------- --------------------------------------
  Media ID   Label                   Workspace ID                           Report ID
 ---------- ----------------------- -------------------------------------- --------------------------------------
  42         OIG Dashboard           xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx   yyyyyyyy-yyyy-yyyy-yyyy-yyyyyyyyyyyy
 ---------- ----------------------- -------------------------------------- --------------------------------------
```

Then generate a fresh embed token. The `dataset_id` is fetched automatically from the Power BI API (same path the field formatter takes on page render) — you only need the workspace and report IDs:

```bash
# Single report on the site — auto-detected, no arguments needed
drush pwbi:token-refresh

# Multiple reports — pass the IDs shown by pwbi:list-reports
drush pwbi:token-refresh <workspace_id> <report_id>
```

**Success output:**
```
Power BI Embed Token Refresh
[OK] Embed token generated successfully!

 -------------- ------------------------------------------
  Property       Value
 -------------- ------------------------------------------
  Workspace ID   xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
  Report ID      yyyyyyyy-yyyy-yyyy-yyyy-yyyyyyyyyyyy
  Dataset ID     zzzzzzzz-zzzz-zzzz-zzzz-zzzzzzzzzzzz
  Token          H4sIAAAAAAAEAy2Tz2...
  Expiration     2026-03-02T16:00:00Z
  Token ID       a1b2c3d4-...
 -------------- ------------------------------------------
```

**Failure diagnoses:**

| Error | Likely cause |
|-------|-------------|
| `No Power BI report Media entities found` | No Media entities with `pwbi_embed` fields exist yet |
| `Could not retrieve datasetId from the Power BI API` | `getGroupReport()` failed — wrong endpoint (GCC vs commercial), or service principal lacks Workspace Member role |
| `API response did not contain a token` | `getEmbedToken()` failed; raw API response is printed — check service principal permissions in Power BI |
| Response is an HTML error page | Wrong API endpoint — verify GCC vs commercial in admin settings |

---

### Layer 3 — HTTP controller endpoint (browser → Drupal → token JSON)

This tests the actual route that the browser JS calls. Use `curl` from outside the server.

```bash
# 1. Get a session CSRF token (saves session cookie to /tmp/pwbi-test-cookie)
CSRF=$(curl -sc /tmp/pwbi-test-cookie "https://your-site.example.com/session/token")

# 2. Call the refresh endpoint
curl -sb /tmp/pwbi-test-cookie \
  -H "X-CSRF-Token: $CSRF" \
  -H "Accept: application/json" \
  "https://your-site.example.com/pwbi/token-refresh/<workspace_id>/<report_id>?dataset_id=<dataset_id>"
```

**Success response:**
```json
{"token":"H4sIAAAA...","expiration":"2026-03-02T16:00:00Z"}
```

**Failure diagnoses:**

| HTTP status / response | Cause |
|------------------------|-------|
| `403 {"error":"Forbidden"}` | `workspace_id` is not in the site's configured workspaces list (`admin/config/pwbi/embed_settings`). Add it there. |
| `400 {"error":"dataset_id query parameter required"}` | Missing `?dataset_id=` in URL |
| `400 {"error":"Invalid dataset_id format"}` | `dataset_id` is not a valid UUID |
| `502 {"error":"Token generation failed"}` | Layer 2 failed — fix embed token generation first |
| `404` or HTML page | Route not found — run `drush cr` and verify `pwbi.routing.yml` was updated |
| `403` with CSRF message | Missing or wrong `X-CSRF-Token` header |

---

### Layer 4 — Browser JS cycle (token applied to live embed)

Open the browser developer console on a page with an embedded Power BI report.

**Inspect current refresh state:**

```javascript
// See token expiry and refresh flags for all embeds on the page
Object.entries(drupalSettings.pwbi_embed).forEach(([key, s]) => {
  const msLeft = Date.parse(s.tokenExpiration) - Date.now();
  console.table({
    key,
    token_refresh_enabled: s.token_refresh_enabled,
    _refreshScheduled: s._refreshScheduled,
    tokenExpiration: s.tokenExpiration,
    minutesRemaining: Math.round(msLeft / 60000),
  });
});
```

`_refreshScheduled: true` means the interval is running. `minutesRemaining` should count down; after a refresh fires it resets to ~60.

**Force an immediate refresh (for testing):**

```javascript
// Set tokenExpiration to 5 minutes from now — next 30s interval tick will trigger refresh
const s = Object.values(drupalSettings.pwbi_embed)[0];
s.tokenExpiration = new Date(Date.now() + 5 * 60 * 1000).toISOString();
console.log('[pwbi-test] tokenExpiration set to', s.tokenExpiration, '— refresh will fire within 30s');
```

Watch the console for:
```
[pwbi] Token expiring soon, refreshing...
[pwbi] Token refreshed. New expiration: 2026-03-02T16:00:00Z
```

**Verify the new token was applied to the live embed:**

```javascript
// Check the access token on the live powerbi embed object
Object.values(powerbi.embeds).forEach(embed => {
  console.log(
    embed.element.id,
    '→ token prefix:',
    embed.config?.accessToken?.substring(0, 40)
  );
});
```

After a successful refresh, this value should differ from what it was on initial page load.

**Failure diagnoses:**

| Symptom | Cause |
|---------|-------|
| `_refreshScheduled` is `false` or missing | `token_refresh_enabled` was `false` in `drupalSettings` when the page loaded. Check admin settings, clear caches, reload. |
| `token_refresh_enabled` is `false` in `drupalSettings` | Feature not enabled in admin, or `PowerBiEmbedFormatter.php` patch not applied |
| Refresh fires but token doesn't change | Layer 3 returning an error — open Network tab and inspect the `/pwbi/token-refresh/` XHR |
| Console error `setAccessToken rate limit reached` | `_refreshScheduled` guard missing — multiple intervals stacking. Should not occur with the patch applied; indicates an old version of the JS is cached. Run `drush cr` and hard-refresh the browser. |
| Console error `report.setAccessToken is not a function` | The `report` reference was lost. Should not occur with current implementation which captures `report` from `powerbiClient.load()` directly. |

---

## Quick Reference

```bash
# Rebuild cache after installing patch
drush cr

# Test Layer 1: OAuth2 handshake
drush pwbi:auth-check

# List all configured reports (workspace_id + report_id from Media entities)
drush pwbi:list-reports

# Test Layer 2: Embed token generation
drush pwbi:token-refresh                        # auto-detect if only one report
drush pwbi:token-refresh <workspace_id> <report_id>  # explicit when multiple reports

# Clear a stale cached OAuth2 token
drush php-eval "\Drupal::state()->delete('oauth2_client_access_token.pwbi_service_principal');"

# Check current API endpoint setting
drush php-eval "print \Drupal::config('pwbi.settings')->get('pwbi_api_endpoint');"
```
