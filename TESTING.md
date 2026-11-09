# pwbi Patch — Setup & Testing Guide

This patch adds the following features to the `pwbi` Drupal module:

1. **Configurable API endpoint** — choose Commercial, GCC, GCC High, or DoD instead of the hardcoded commercial endpoint.
2. **Automatic embed token refresh** — silently renews the embed token before it expires so dashboards stay live for users who keep a tab open.
3. **Multi-cloud OAuth2** — Service principal OAuth2 endpoint URLs auto-swap based on the configured cloud (commercial Azure AD vs US Government Azure AD).
4. **Drush diagnostic commands** — `pwbi:auth-check`, `pwbi:flush-token`, `pwbi:list-reports`, `pwbi:token-refresh` for CLI-based testing and operations.
5. **Endpoint change auto-clear** — changing the API endpoint in admin automatically invalidates the cached OAuth2 token, preventing cross-cloud authentication errors.

---

## Files Changed

| File | Change |
|------|--------|
| `src/Api/PowerBiClient.php` | Dynamic endpoint via Config API; `getApiRoot()` method replaces 8 hardcoded constants |
| `src/Form/PowerBiEmbedConfigForm.php` | Extends `ConfigFormBase`; adds 4 new admin fields (API endpoint, refresh on/off, refresh lead time in ms, debug logging) |
| `src/Controller/PwbiTokenRefreshController.php` | **New** — JSON endpoint that issues a fresh embed token |
| `src/Commands/PwbiCommands.php` | **New** — Drush commands: `pwbi:auth-check`, `pwbi:flush-token`, `pwbi:list-reports`, `pwbi:token-refresh` |
| `src/PowerBiEmbed.php` | Returns `datasetId` in embed data so JS can pass it to the refresh endpoint |
| `src/Plugin/Field/FieldFormatter/PowerBiEmbedFormatter.php` | Passes `workspaceId`, `datasetId`, and refresh settings into `drupalSettings`; sets render cache max-age to the time left on the embed token so a cached page never serves an expired token |
| `src/Plugin/Oauth2Client/PwbiServicePrincipal.php` | Multi-cloud OAuth2 — all four endpoint URLs (authorization, token, resource_owner, scope) auto-swap based on `pwbi_api_endpoint` config |
| `components/pwbi-embed/pwbi-embed.js` | Adds a `setTimeout` that fires `token_refresh_lead_ms` before expiry (`scheduleNextRefresh` / `updateToken`), retries every 1 s until the expiry advances, and re-checks on `visibilitychange`. If the page arrives with an expired token (for example a cached copy), it fetches a new token before embedding (retrying after 5 s on an error, after 1 s while the new token still expires within the lead time), so the report can render |
| `config/install/pwbi.settings.yml` | **New** — default config values |
| `config/schema/pwbi.schema.yml` | Adds `pwbi.settings` schema |
| `drush.services.yml` | **New** — registers Drush commands |
| `pwbi.permissions.yml` | Adds `view pwbi embed token` permission for the refresh endpoint |
| `pwbi.routing.yml` | Adds `/pwbi/token-refresh/{workspace_id}/{report_id}` route |
| `src/PageCache/DenyPowerBiEmbedPages.php` + `pwbi.services.yml` | **New** -- page cache response policy: pages tagged `pwbi_embed` are never stored in Drupal's internal page cache, which ignores max-age and would serve an expired token. The embed's render cache still expires with the token |
| `tests/src/Unit/DenyPowerBiEmbedPagesTest.php` | **New** -- unit test for the policy |

---

## Post-Install Checklist

Complete these steps in order after applying the patch. Each `[ ]` is a gate — if it fails, stop and diagnose before continuing.

### 1. Apply and initialize

- [ ] Apply patch via Composer: `ddev composer install` (or `composer install` on the server)
- [ ] `drush cr` — rebuild the container so new services and routes are registered
- [ ] `drush updb` — runs any update hooks and imports the new `pwbi.settings` config

### 2. Configure the module

Go to **Admin → Configuration → Power BI → Embed Settings** (`/admin/config/pwbi/embed_settings`):

- [ ] Set **API endpoint** to **US Government GCC** (`https://api.powerbigov.us`) for OIG/GCC sites
- [ ] Check **Enable automatic embed token refresh**
- [ ] Leave **Milliseconds before expiry to start refresh** at the default 1500 (1000 to 2000 recommended)
- [ ] Click **Save configuration**
- [ ] `drush cr` — clears cached config so the formatter picks up the new settings

### 3. Assign the new permission

Go to **Admin → People → Permissions** (`/admin/people/permissions`):

- [ ] Find **"Request Power BI embed tokens"** under the Power BI section
- [ ] Grant it to every role that should see embedded reports. On a public site that includes `anonymous user` as well as `authenticated user`. Export the role config afterwards (`drush cex`) so a later config import does not remove it.
- [ ] Save permissions

> Without this permission the JS token refresh will receive a 403 on every refresh and the embed will stop working when its token expires.

### 4. Test the OAuth handshake (Layer 1)

```bash
drush pwbi:auth-check
```

- [ ] Output shows `[OK] OAuth2 handshake successful!`
- [ ] `Is expired` shows `No`
- [ ] `Expires` shows a future time (~60 min from now)

**If this fails:** stop here. No downstream test will pass until the service principal credentials are valid.

### 5. List configured reports (Layer 2 — part A)

```bash
drush pwbi:list-reports
```

- [ ] At least one report is listed with a valid Workspace ID and Report ID
- [ ] The Workspace ID matches one of the workspaces entered in the admin form

**If no reports appear:** the Media entities with `pwbi_embed` fields either don't exist yet or have empty workspace/report ID values.

### 6. Test embed token generation (Layer 2 — part B)

```bash
# If only one report is configured — auto-detected:
drush pwbi:token-refresh

# If multiple reports — use IDs from pwbi:list-reports:
drush pwbi:token-refresh <workspace_id> <report_id>
```

- [ ] Output shows `[OK] Embed token generated successfully!`
- [ ] `Token (truncated)` field is populated
- [ ] `Expiration` shows a future timestamp (~60 min from now)
- [ ] `Dataset ID` is a valid UUID

**If this fails:** check the failure diagnosis table in Layer 2 below.

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

When the endpoint is changed and saved, the cached Service Principal OAuth2 token is automatically cleared and a warning is displayed. This ensures the next request re-authenticates against the correct cloud's Azure AD endpoint. You do not need to run `pwbi:flush-token` manually after changing the endpoint setting.

The OAuth2 endpoint URLs (authorization, token, resource_owner, scope) also update automatically — GCC and commercial use the same Azure AD (`login.microsoftonline.com`), while GCC High and DoD use the US Government Azure AD (`login.microsoftonline.us`).

### Automatic Token Refresh

| Setting | Description | Default |
|---------|-------------|---------|
| Enable automatic embed token refresh | Turns refresh on site-wide | Off |
| Milliseconds before expiry to start refresh | How long before the token's expiry timestamp the browser asks for a new one. Fire too early and Power BI returns the same expiry (the JS then retries every 1 s, up to 15 times, then every 30 s) | 1500 |

Enable the feature and save. After a `drush cr`, the JS schedules one refresh per embedded report on its first render, `token_refresh_lead_ms` before the token expires.

### Debug logging

| Setting | Description | Default |
|---------|-------------|---------|
| Enable debug logging | Extra browser console lines and a watchdog entry per refresh | Off |

With it on:
- the browser console logs `[pwbi] Next token refresh scheduled for <time> (in <n>s)` each time a refresh is scheduled;
- after each refresh it logs the last 8 characters of the old and new token and the old and new expiry, each marked `CHANGED` or `SAME`, and warns when the expiry did not advance or retries stalled;
- the controller writes `Embed token refreshed for report ... expires ...` to the watchdog (`drush watchdog:show --type=pwbi`).

Errors (`[pwbi] Token refresh failed: Token refresh HTTP error <status>`, `... response missing token`, or the network or `setAccessToken()` error) are logged to the console whether debug is on or off. Debug changes no behavior, only logging. On production, where config is read-only, turn it on with a `settings.php` override (`$config['pwbi.settings']['debug_enabled'] = TRUE;`) followed by `drush cr`, and remove it after testing.

### Page cache

Pages that show a report are never stored in Drupal's internal page cache (the policy in `src/PageCache/DenyPowerBiEmbedPages.php`). Check it as an anonymous visitor:

```bash
curl -sI https://your-site.example.com/<dashboard-page> | grep -i x-drupal-cache
# X-Drupal-Cache: UNCACHEABLE (response policy)
```

`HIT` on a dashboard page means the policy is not active: rebuild caches (`drush cr`) and check that the patch applied.

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
| Token shows `Is expired: YES` | Cached stale token. Clear it with `drush pwbi:flush-token` then retry. |
| Audience warning `aud` does not match GCC scope | Endpoint changed after token was cached. Run `drush pwbi:flush-token` to force re-authentication against the new cloud's Azure AD. |

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
# Call the refresh endpoint as an anonymous visitor (no cookie, no CSRF token).
# The route has no CSRF requirement; access is the `view pwbi embed token`
# permission plus the workspace allowlist.
curl -si -H "Accept: application/json" \
  "https://your-site.example.com/pwbi/token-refresh/<workspace_id>/<report_id>?dataset_id=<dataset_id>"
```

Get the three IDs from the page itself: in the browser console on the report page, run `drupalSettings.pwbi_embed` and read `workspaceId`, `id` and `datasetId`.

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
| `403` whose body is not `{"error":"Forbidden"}` (Drupal's own access denied) | The role making the request lacks the `view pwbi embed token` permission (for the curl above, the `anonymous user` role) |

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

`_refreshScheduled: true` means a refresh timer is set. `minutesRemaining` should count down; after a refresh with a new expiry it jumps forward.

**Real test (the one that proves it):** turn on debug logging, load the report, leave the tab open past the expiry shown in `tokenExpiration`, and watch for:
```
[pwbi] Next token refresh scheduled for 2026-03-03T16:00:00.000Z (in 3540s)
[pwbi] Token refreshed.
  token:      ...a1b2c3d4 → ...e5f6g7h8 (CHANGED)
  expiration: 2026-03-03T16:00:01Z → 2026-03-03T17:00:01Z (CHANGED)
[pwbi] Next token refresh scheduled for 2026-03-03T16:59:59.500Z (in 3598s)
```
(Example values.) An expiry marked `CHANGED` that moves forward about an hour confirms that `setAccessToken()` ran with a new token. Keep the Network tab open too: the `/pwbi/token-refresh/` request should return 200, and Power BI's own requests from the iframe (for example `.../powerbi/refresh/subscribe`) should not start failing with 401/403 after the old expiry.

**Quick test (forces a refresh now):** the timer is only rescheduled from `tokenExpiration` when the tab becomes visible again, so move the expiry and switch tabs:

```javascript
const s = Object.values(drupalSettings.pwbi_embed)[0];
s.tokenExpiration = new Date(Date.now() + 10 * 1000).toISOString();
console.log('[pwbi-test] tokenExpiration set to', s.tokenExpiration, '- switch to another tab and back');
```

After you come back, the refresh fires within about 5 seconds. This proves the endpoint and `setAccessToken()` path work. Because the real token is not near expiry yet, Power BI may return the **same** expiry: with debug logging on, the log then shows `expiration: ... (SAME)` and `Expiration unchanged — OAuth window not yet renewed. Retrying in 1 s.`, retries every second up to 15 times, then every 30 seconds until the expiry advances. That is expected for a forced test. Reload the page to reset.

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

**Server-side confirmation via Drupal watchdog:**

While **Enable debug logging** is on, every successful token refresh also logs to the Drupal watchdog. Run this to see server-side confirmation:

```bash
drush watchdog:show --type=pwbi
```

Each successful refresh logs:
```
Embed token refreshed for report <report_id> (workspace <workspace_id>), expires <datetime>.
```

If the browser is refreshing (Network tab shows GET requests to `/pwbi/token-refresh/`) but no watchdog entries appear, the controller is returning an error before reaching the log line — check the response body in the Network tab.

**Failure diagnoses:**

| Symptom | Cause |
|---------|-------|
| `_refreshScheduled` is `false` or missing | `token_refresh_enabled` was `false` in `drupalSettings` when the page loaded. Check admin settings, clear caches, reload. |
| `_refreshScheduled` missing and `tokenExpirationDate` in the past | The page was served with an expired token and the fetch before embedding keeps failing: the console shows `[pwbi] Token refresh failed: ...` every 5 s. Check Layer 3. Before the 2026-11 fix this also happened whenever the page cache served a page older than its token |
| `token_refresh_enabled` is `false` in `drupalSettings` | Feature not enabled in admin, or `PowerBiEmbedFormatter.php` patch not applied |
| Refresh fires but token doesn't change | Layer 3 returning an error — open Network tab and inspect the `/pwbi/token-refresh/` XHR |
| Console error `setAccessToken rate limit reached` | `_refreshScheduled` guard missing — multiple intervals stacking. Should not occur with the patch applied; indicates an old version of the JS is cached. Run `drush cr` and hard-refresh the browser. |
| Console error `report.setAccessToken is not a function` | The `report` reference was lost. Should not occur with current implementation which captures `report` from `powerbiClient.load()` directly. |

---

## Layer 0 — REST API Playground (pre-Drupal verification)

Use the [Power BI REST API Playground](https://learn.microsoft.com/en-us/rest/api/power-bi/) to verify your Bearer token and workspace/report IDs work against the GCC endpoint **before** Drupal is involved. This isolates credential and permission issues from Drupal configuration issues.

> **GCC base URL for all requests below:** `https://api.powerbigov.us` (not `api.powerbi.com`)

### What you need

| Value | Where to find it |
|-------|-----------------|
| Bearer token | Output of `drush pwbi:auth-check` (copy the full token, not the truncated form) |
| Workspace ID | Output of `drush pwbi:list-reports` |
| Report ID | Output of `drush pwbi:list-reports` |
| Dataset ID | Returned by the Get Report call below |

### Step 1 — Get a fresh Bearer token

```bash
drush pwbi:auth-check
```

Copy the full token from Drupal State (not the truncated display):

```bash
drush php-eval "
  \$token = \Drupal::service('oauth2_client.service')->getAccessToken('pwbi_service_principal');
  print \$token->getToken();
"
```

### Step 2 — Verify workspace access (Get Report)

```
GET https://api.powerbigov.us/v1.0/myorg/groups/{workspace_id}/reports/{report_id}

Authorization: Bearer <token from step 1>
```

**Expected response:**
```json
{
  "id": "yyyyyyyy-yyyy-yyyy-yyyy-yyyyyyyyyyyy",
  "reportType": "PowerBIReport",
  "name": "OIG Dashboard",
  "datasetId": "zzzzzzzz-zzzz-zzzz-zzzz-zzzzzzzzzzzz",
  "webUrl": "https://app.powerbigov.us/...",
  "embedUrl": "https://app.powerbigov.us/reportEmbed?reportId=..."
}
```

Copy the `datasetId` — you need it for Step 3.

**Failure diagnoses:**

| HTTP status | Cause |
|-------------|-------|
| `401 Unauthorized` | Bearer token expired or wrong endpoint (used `.com` instead of `powerbigov.us`) |
| `403 Forbidden` | Service principal is not a Workspace Member — add it in Power BI workspace settings |
| `404 Not Found` | Wrong workspace ID or report ID |

### Step 3 — Generate an embed token

```
POST https://api.powerbigov.us/v1.0/myorg/GenerateToken

Authorization: Bearer <token from step 1>
Content-Type: application/json

{
  "datasets": [{"id": "<dataset_id from step 2>"}],
  "reports":  [{"id": "<report_id>"}]
}
```

**Expected response:**
```json
{
  "token": "H4sIAAAA...",
  "tokenId": "a1b2c3d4-...",
  "expiration": "2026-03-02T16:00:00Z"
}
```

**Failure diagnoses:**

| HTTP status | Cause |
|-------------|-------|
| `401 Unauthorized` | Bearer token expired |
| `403 Forbidden` | Service principal lacks `Dataset.ReadWrite.All` or `Report.ReadWrite.All` permission in Power BI, or the dataset is not in the workspace |
| `400 Bad Request` | Malformed request body — verify dataset and report IDs are valid UUIDs |

If this call succeeds, Layers 1–2 of the Drupal test (`drush pwbi:auth-check` and `drush pwbi:token-refresh`) will also succeed.

---

## Quick Reference

```bash
# Rebuild cache after installing patch or changing settings
drush cr

# Test Layer 1: OAuth2 handshake (service principal → Azure AD Bearer token)
drush pwbi:auth-check

# Flush the cached OAuth2 Service Principal token (force re-authentication)
# Use after changing API endpoint, rotating credentials, or if auth-check shows expired token
drush pwbi:flush-token

# List all configured reports (workspace_id + report_id from Media entities)
drush pwbi:list-reports

# Test Layer 2: Embed token generation (Bearer token → Power BI REST API → embed token)
drush pwbi:token-refresh                                      # auto-detect if only one report
drush pwbi:token-refresh <workspace_id> <report_id>          # explicit when multiple reports

# Server-side token refresh confirmations (watchdog entries from the controller;
# written only while debug logging is on)
drush watchdog:show --type=pwbi

# All four patch settings at once: endpoint, refresh on/off, lead time, debug
# (shows the effective values, including any settings.php override)
drush cget pwbi.settings --include-overridden

# Is the refresh permission still granted to anonymous visitors?
drush role:list --filter="view pwbi embed token"
```
