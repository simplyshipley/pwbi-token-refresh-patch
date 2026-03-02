# pwbi Token Refresh Patch — Session Handoff

## Project Purpose

A government website (USPS OIG) runs a Drupal site using the `pwbi` module (v2.x) to embed Power BI dashboards hosted on the US Government Community Cloud (GCC). Two problems need to be fixed: (1) the module hardcodes `api.powerbi.com` (commercial endpoint) instead of `api.powerbigov.us` (GCC), causing all server-side API calls to hit the wrong endpoint, and (2) embed tokens expire after one hour with no refresh mechanism, silently breaking dashboards for users who keep a tab open. This patch adds a configurable API endpoint selector and an automatic browser-side token refresh system.

---

## Status

**Done:**
- Module source cloned from `https://git.drupalcode.org/project/pwbi.git` (branch 2.x) into `pwbi/`
- `pwbi/README.md` updated with documentation for both new features (GCC endpoint config, automatic token refresh)
- Architecture fully designed and reviewed by multi-agent review (decisions locked, see below)
- **All 9 files from the spec implemented** (see "Complete Specification" below for details)
- **Drush commands added** — `pwbi:auth-check`, `pwbi:flush-token`, `pwbi:list-reports`, `pwbi:token-refresh` (Files 10–11)
- Both DDEV installation and patch repo are in sync
- **Code review applied** (Drupal architect + PHP quality review, session 2):
  - `PwbiServicePrincipal.php`: Multi-cloud OAuth2 endpoint map — authorization, token, resource_owner, and scope URLs all auto-swap from `pwbi_api_endpoint` config. Memoized per-request via `$resolvedCloudOauthConfig`.
  - `PowerBiEmbed.php`: `PWBI_REPORT_META` moved from State blob (read-modify-write) to per-key `KeyValueStore` entries. NULL guards added to all three `Json::decode()` calls.
  - `PowerBiEmbedFormatter.php`: `config:pwbi.settings` cache tag added; config read moved outside foreach loop; render cache max-age reduced by refresh buffer when refresh enabled; `ImmutableConfig` passed to `buildEmbedConfiguration()`.
  - `PowerBiEmbedConfigForm.php`: `StateInterface` injected (replaces `\Drupal::state()` static call); endpoint-change detection auto-clears cached OAuth2 token on save.
  - `PwbiTokenRefreshController.php`: UUID validation for `workspace_id`, `report_id`, and `dataset_id`; `Request` object injected (replaces `\Drupal::request()` static call); Drupal watchdog logging on successful token issue.
  - `pwbi-embed.js`: `_refreshing` guard (try/finally) prevents concurrent token refresh calls; diagnostic `console.log` in `checkTokenAndUpdate()` shows token expiry and minutes remaining every 30 seconds.
  - `pwbi.services.yml` + `drush.services.yml`: `@keyvalue` added to both service definitions.
  - JWT padding bug fixed in `PwbiCommands::authCheck()` (URL-safe base64 + pad before decode).

**JS implementation note:**
The `pwbi-embed.js` implementation (File 9) was refined during implementation to align exactly with Microsoft's
`setInterval`-based token refresh pattern (30s polling + `visibilitychange`), replacing the original `setTimeout`
approach in the spec. The live implementation uses `checkTokenAndUpdate` / `updateToken` function names that
mirror Microsoft's reference docs. The spec in this document still shows the original `setTimeout` design —
the live files (`pwbi/components/pwbi-embed/pwbi-embed.js`) are authoritative.

**Pending:**
- Apply patch to production deployment (pending OIG change management process)
- Submit upstream to drupal.org/project/pwbi if appropriate
- Consider reducing default `token_refresh_minutes` documentation guidance from 10 to whatever OIG settles on after testing

---

## How to Start the Next Session

Paste this prompt verbatim into a new Claude Code session:

```
I need you to implement a patch for the Drupal `pwbi` module. The repo is at:

  /Users/bo/Sites/_ai/pwbi-token-refresh-patch/

Read HANDOFF.md first — it contains the full spec. Then read these files in the module:

  pwbi/src/Api/PowerBiClient.php
  pwbi/src/PowerBiEmbed.php
  pwbi/src/Form/PowerBiEmbedConfigForm.php
  pwbi/src/Plugin/Field/FieldFormatter/PowerBiEmbedFormatter.php
  pwbi/components/pwbi-embed/pwbi-embed.js
  pwbi/pwbi.routing.yml
  pwbi/config/schema/pwbi.schema.yml

Then implement all 9 changes specified in HANDOFF.md "The Patch — Complete Specification" section. Follow the "Review Decisions" exactly — they are locked, do not re-debate them. Do not deviate from the spec. All changes go inside the `pwbi/` subdirectory.

After implementing all changes, commit with: git -C /Users/bo/Sites/_ai/pwbi-token-refresh-patch commit -m "feat(pwbi): add GCC endpoint config + automatic embed token refresh"
```

---

## Repository Structure

```
/Users/bo/Sites/_ai/pwbi-token-refresh-patch/
├── HANDOFF.md          ← this document
└── pwbi/               ← the Drupal module source (read-only upstream, patched here)
    ├── pwbi.info.yml
    ├── pwbi.routing.yml
    ├── pwbi.module
    ├── pwbi.libraries.yml
    ├── pwbi.permissions.yml
    ├── pwbi.breakpoints.yml
    ├── pwbi.install
    ├── pwbi.services.yml
    ├── README.md           ← already updated with new feature docs
    ├── composer.json
    ├── package.json
    ├── phpcs.xml.dist
    ├── phpstan.neon
    ├── config/
    │   └── schema/
    │       └── pwbi.schema.yml   ← modify: add pwbi.settings schema
    ├── config/install/           ← CREATE: pwbi.settings.yml here
    ├── components/
    │   └── pwbi-embed/
    │       └── pwbi-embed.js     ← modify: add token refresh timer
    ├── src/
    │   ├── Api/
    │   │   └── PowerBiClient.php         ← modify: dynamic endpoint method
    │   ├── Controller/
    │   │   └── PwbiTokenRefreshController.php  ← CREATE this file
    │   ├── Form/
    │   │   └── PowerBiEmbedConfigForm.php      ← modify: extend ConfigFormBase, add 3 fields
    │   ├── Plugin/Field/FieldFormatter/
    │   │   └── PowerBiEmbedFormatter.php       ← modify: pass workspaceId, datasetId, refresh config
    │   └── PowerBiEmbed.php                    ← modify: add datasetId to return array
    ├── modules/
    │   ├── pwbi_banner/
    │   └── pwbi_purge/
    └── tests/
```

---

## The Patch — Complete Specification

### File 1: `pwbi/src/Api/PowerBiClient.php` — Replace hardcoded constants with configurable endpoint

**What to change:** Replace the 8 hardcoded `protected const` lines (currently lines 23–30) with a `private const API_ENDPOINTS` map and a `protected function getApiRoot(): string` method that reads the selected endpoint from Drupal's Config API.

**Add to constructor dependencies:** `ConfigFactoryInterface $configFactory` injected via DI. Add `use Drupal\Core\Config\ConfigFactoryInterface;` to the use block. Add `protected readonly ConfigFactoryInterface $configFactory` to the constructor. Add `$container->get('config.factory')` to the `create()` method.

**Replace the 8 constants with:**
```php
private const API_ENDPOINTS = [
  'commercial' => 'https://api.powerbi.com',
  'gcc'        => 'https://api.powerbigov.us',
  'gcc_high'   => 'https://api.high.powerbigov.us',
  'dod'        => 'https://api.mil.powerbigov.us',
];

protected function getApiRoot(): string {
  $config = $this->configFactory->get('pwbi.settings');
  $endpoint = $config->get('pwbi_api_endpoint') ?? 'https://api.powerbi.com';
  return rtrim((string) $endpoint, '/');
}
```

**Replace all 8 endpoint usages** — wherever `self::PWBI_GENERATE_TOKEN_ENDPOINT` etc. appeared, use `$this->getApiRoot() . '/v1.0/myorg/...'` inline or define `protected const` path suffixes (just the path portion). The cleanest approach: keep the path-suffix-only constants and compose them in `getApiRoot()` prefix + path suffix.

Example for `getEmbedToken()`:
```php
public function getEmbedToken(array $body): string {
  return $this->connect('post', $this->getApiRoot() . '/v1.0/myorg/GenerateToken', $body);
}
```

All 8 endpoint paths to update:
- `/v1.0/myorg/GenerateToken`
- `/v1.0/myorg/datasets/%s/executeQueries`
- `/v1.0/myorg/groups/%s/datasets/%s/executeQueries`
- `/v1.0/myorg/groups/%s/reports/%s/ExportTo`
- `/v1.0/myorg/groups/%s/reports/%s/exports/%s`
- `/v1.0/myorg/groups/%s/reports/%s/exports/%s/file`
- `/v1.0/myorg/groups/%s/reports/%s`
- `/v1.0/myorg/groups/%s/reports/%s/pages`

---

### File 2: `pwbi/src/Form/PowerBiEmbedConfigForm.php` — Extend ConfigFormBase, add 3 new settings fields

**Change class declaration:** `extends FormBase` → `extends ConfigFormBase`

**Add use statements:**
```php
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
```

**Remove:** `use Drupal\Core\Form\FormBase;`

**Add required `getEditableConfigNames()` method:**
```php
protected function getEditableConfigNames(): array {
  return ['pwbi.settings'];
}
```

**Update constructor:** `ConfigFormBase` requires `ConfigFactoryInterface` and `TypedDataManagerInterface`. The cleanest approach is to call `parent::__construct($config_factory)` with ConfigFormBase's pattern. Because ConfigFormBase already injects `config.factory` via its own `create()`, wire it through:

```php
public function __construct(
  ConfigFactoryInterface $config_factory,
  protected readonly PowerBiEmbed $pwbiEmbed,
) {
  parent::__construct($config_factory);
}

public static function create(ContainerInterface $container): static {
  // @phpstan-ignore-next-line
  return new static(
    $container->get('config.factory'),
    $container->get('pwbi_embed.embed'),
  );
}
```

**Add 3 new form fields in `buildForm()`** (after the `pwbi_workspaces` field):

```php
$pwbi_settings = $this->config('pwbi.settings');

$form['pwbi_api_endpoint'] = [
  '#type' => 'select',
  '#title' => $this->t('Power BI API endpoint'),
  '#default_value' => $pwbi_settings->get('pwbi_api_endpoint') ?? 'https://api.powerbi.com',
  '#options' => [
    'https://api.powerbi.com'         => $this->t('Commercial (api.powerbi.com)'),
    'https://api.powerbigov.us'        => $this->t('US Government GCC (api.powerbigov.us)'),
    'https://api.high.powerbigov.us'   => $this->t('US Government GCC High (api.high.powerbigov.us)'),
    'https://api.mil.powerbigov.us'    => $this->t('US DoD (api.mil.powerbigov.us)'),
  ],
  '#description' => $this->t('Select the Power BI REST API root for your tenant cloud environment.'),
];

$form['token_refresh_enabled'] = [
  '#type' => 'checkbox',
  '#title' => $this->t('Enable automatic embed token refresh'),
  '#default_value' => $pwbi_settings->get('token_refresh_enabled') ?? FALSE,
  '#description' => $this->t('Silently renew embed tokens before they expire, preserving all user state.'),
];

$form['token_refresh_minutes'] = [
  '#type' => 'number',
  '#title' => $this->t('Minutes before expiry to refresh'),
  '#default_value' => $pwbi_settings->get('token_refresh_minutes') ?? 10,
  '#min' => 1,
  '#max' => 55,
  '#description' => $this->t('Request a new token this many minutes before the current one expires.'),
  '#states' => [
    'visible' => [':input[name="token_refresh_enabled"]' => ['checked' => TRUE]],
  ],
];
```

**Update `submitForm()`** — save `pwbi_workspaces` to State API (unchanged), save new 3 fields to Config API:

```php
public function submitForm(array &$form, FormStateInterface $form_state): void {
  // Existing: save workspaces to State API.
  $settings = ['pwbi_workspaces' => $form_state->getValue('pwbi_workspaces')];
  $this->pwbiEmbed->setEmbedConfiguration($settings);

  // New: save API settings to Config API.
  $this->config('pwbi.settings')
    ->set('pwbi_api_endpoint', $form_state->getValue('pwbi_api_endpoint'))
    ->set('token_refresh_enabled', (bool) $form_state->getValue('token_refresh_enabled'))
    ->set('token_refresh_minutes', (int) $form_state->getValue('token_refresh_minutes'))
    ->save();

  parent::submitForm($form, $form_state);
}
```

---

### File 3: `pwbi/src/Controller/PwbiTokenRefreshController.php` — NEW FILE

Full file content:

```php
<?php

declare(strict_types=1);

namespace Drupal\pwbi\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\pwbi\Api\PowerBiClient;
use Drupal\pwbi\PowerBiEmbed;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Component\Serialization\Json;

/**
 * Returns a fresh embed token for an active Power BI report embed.
 */
class PwbiTokenRefreshController extends ControllerBase {

  public function __construct(
    protected readonly PowerBiClient $pwbiClient,
    protected readonly PowerBiEmbed $pwbiEmbed,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    // @phpstan-ignore-next-line
    return new static(
      $container->get('pwbi_api.client'),
      $container->get('pwbi_embed.embed'),
    );
  }

  /**
   * Issue a fresh embed token for the given workspace+report combination.
   *
   * Only workspace+report pairs that are currently configured as active
   * embeds in the site's State API are eligible — this is the allowlist.
   *
   * @param string $workspace_id
   *   The Power BI workspace (group) UUID.
   * @param string $report_id
   *   The Power BI report UUID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with `token` and `expiration`, or an error with HTTP 403/502.
   */
  public function refresh(string $workspace_id, string $report_id): JsonResponse {
    // Allowlist check: confirm this workspace+report is an active embed.
    if (!$this->isAllowedEmbed($workspace_id, $report_id)) {
      return new JsonResponse(['error' => 'Forbidden'], 403, [
        'Cache-Control' => 'no-store',
      ]);
    }

    // NOTE: datasetId is passed via drupalSettings on initial page load.
    // The client must include it as a query parameter: ?dataset_id=...
    // This avoids calling getEmbedDataFromApi() (3x API cost).
    $dataset_id = \Drupal::request()->query->get('dataset_id', '');
    if (empty($dataset_id)) {
      return new JsonResponse(['error' => 'dataset_id query parameter required'], 400, [
        'Cache-Control' => 'no-store',
      ]);
    }

    $body = [
      'datasets' => [['id' => $dataset_id]],
      'reports'  => [['id' => $report_id]],
    ];

    try {
      $result = Json::decode($this->pwbiClient->getEmbedToken($body));
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => 'Token generation failed'], 502, [
        'Cache-Control' => 'no-store',
      ]);
    }

    if (empty($result['token'])) {
      return new JsonResponse(['error' => 'Empty token from API'], 502, [
        'Cache-Control' => 'no-store',
      ]);
    }

    return new JsonResponse([
      'token'      => $result['token'],
      'expiration' => $result['expiration'],
    ], 200, [
      'Cache-Control' => 'no-store',
    ]);
  }

  /**
   * Check whether the workspace+report pair is an active configured embed.
   *
   * Reads the State API workspaces list and confirms the given workspace_id
   * is listed. The report_id validation is intentionally loose (workspace
   * membership is the binding constraint for our service principal scope).
   *
   * @param string $workspace_id
   *   The workspace UUID to validate.
   * @param string $report_id
   *   The report UUID (validated for UUID format only).
   *
   * @return bool
   *   TRUE if the combination is permitted.
   */
  protected function isAllowedEmbed(string $workspace_id, string $report_id): bool {
    // Basic UUID format sanity check.
    $uuid_pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    if (!preg_match($uuid_pattern, $workspace_id) || !preg_match($uuid_pattern, $report_id)) {
      return FALSE;
    }

    $config = $this->pwbiEmbed->getEmbedConfiguration();
    $workspaces_raw = $config['pwbi_workspaces'] ?? '';
    if (empty($workspaces_raw)) {
      return FALSE;
    }

    // Each line is "workspaceid|Workspace Name". Check if workspace_id is listed.
    foreach (explode("\n", $workspaces_raw) as $line) {
      $parts = explode('|', trim($line));
      if (isset($parts[0]) && trim($parts[0]) === $workspace_id) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
```

---

### File 4: `pwbi/src/PowerBiEmbed.php` — Add datasetId to getEmbedDataFromApi() return

In the `getEmbedDataFromApi()` method, the return array currently does NOT include `datasetId`. Add it:

```php
return [
  'pageName'            => $pages['value'][0]['name'],
  'visualName'          => $pages['value'][0]['displayName'],
  'embedUrl'            => $reportInfo['embedUrl'],
  'accessToken'         => $embedToken['token'],
  'tokenExpirationDate' => $embedToken['expiration'],
  'datasetId'           => $reportInfo['datasetId'],   // ADD THIS LINE
];
```

Also update the `@return` docblock to document `datasetId`.

---

### File 5: `pwbi/src/Plugin/Field/FieldFormatter/PowerBiEmbedFormatter.php` — Pass workspaceId, datasetId, and refresh config

**Add `ConfigFactoryInterface` injection:** Add `use Drupal\Core\Config\ConfigFactoryInterface;` to imports. Add `protected readonly ConfigFactoryInterface $configFactory` to constructor and wire `$container->get('config.factory')` in `create()`.

**In `buildEmbedConfiguration()`**, add before the final `foreach` cleanup loop:

```php
// Pass workspaceId so the JS refresh call can address the right workspace.
$embed_options['workspaceId'] = $item->getValue()['workspace_id'];

// Pass token refresh settings from Config API.
$pwbi_settings = $this->configFactory->get('pwbi.settings');
$embed_options['token_refresh_enabled'] = (bool) ($pwbi_settings->get('token_refresh_enabled') ?? FALSE);
$embed_options['token_refresh_minutes'] = (int) ($pwbi_settings->get('token_refresh_minutes') ?? 10);
```

Note: `datasetId` is already added by File 4 (it comes through `$report_configuration` which is spread into `$embed_options` at the top of `buildEmbedConfiguration()`).

Also note: The existing `foreach` cleanup loop at the end removes empty values. `token_refresh_enabled` is a bool and `token_refresh_minutes` is an int — `empty(FALSE)` and `empty(0)` are truthy in PHP, so **remove `token_refresh_enabled` and `token_refresh_minutes` from the cleanup loop's scope**, or use `unset` only for `null` values. The safest fix: after the cleanup loop, explicitly re-add them:

```php
// Re-add boolean/int fields stripped by the empty() cleanup above.
$embed_options['token_refresh_enabled'] = (bool) ($pwbi_settings->get('token_refresh_enabled') ?? FALSE);
$embed_options['token_refresh_minutes'] = (int) ($pwbi_settings->get('token_refresh_minutes') ?? 10);
```

---

### File 6: `pwbi/config/install/pwbi.settings.yml` — NEW FILE

Create the directory `pwbi/config/install/` if it does not exist, then create:

```yaml
pwbi_api_endpoint: 'https://api.powerbi.com'
token_refresh_enabled: false
token_refresh_minutes: 10
```

---

### File 7: `pwbi/config/schema/pwbi.schema.yml` — Add pwbi.settings schema

Add the following block to the existing file (after the existing `pwbi_embed.embed:` block):

```yaml
pwbi.settings:
  type: config_object
  label: Power BI module settings
  mapping:
    pwbi_api_endpoint:
      type: string
      label: Power BI API endpoint
    token_refresh_enabled:
      type: boolean
      label: Enable automatic embed token refresh
    token_refresh_minutes:
      type: integer
      label: Minutes before expiry to refresh
```

---

### File 8: `pwbi/pwbi.routing.yml` — Add token refresh route

Append to the end of the existing file:

```yaml
pwbi.token_refresh:
  path: '/pwbi/token-refresh/{workspace_id}/{report_id}'
  defaults:
    _controller: '\Drupal\pwbi\Controller\PwbiTokenRefreshController::refresh'
    _title: 'Power BI token refresh'
  options:
    no_cache: TRUE
  requirements:
    _access: 'TRUE'
    csrf_token: 'TRUE'
    workspace_id: '[0-9a-f\-]+'
    report_id: '[0-9a-f\-]+'
```

---

### File 9: `pwbi/components/pwbi-embed/pwbi-embed.js` — Add token refresh timer

After the `report.on('rendered', ...)` block inside `runEmbeds()`, add the refresh timer logic. The full updated `runEmbeds` function:

```javascript
runEmbeds(pwbiEmbeds, powerbiClient) {
  Object.entries(pwbiEmbeds).forEach((entry) => {
    const [key, embedSettings] = entry;
    const embedContainer = document.getElementById(embedSettings.id);
    const alreadyEmbedded = Object.values(powerbiClient.embeds).some(
      (embed) => embed.element.id === embedSettings.id,
    );
    if (alreadyEmbedded) {
      return;
    }
    const arrayToken = embedSettings.accessToken.split('.');
    if (arrayToken.length < 2) {
      return;
    }
    const tokenPayload = JSON.parse(atob(arrayToken[1]));
    if (tokenPayload?.exp === undefined) {
      return;
    }

    const embedConfiguration = embedSettings;
    if (embedConfiguration.settings === undefined) {
      embedConfiguration.settings = {};
    }
    const powerBiEmbedParams = {
      embedConfiguration,
      embedContainer,
    };
    const PowerBiPreEmbed = new CustomEvent('PowerBiPreEmbed', {
      detail: powerBiEmbedParams,
    });
    window.dispatchEvent(PowerBiPreEmbed);
    const report = powerbiClient.load(
      powerBiEmbedParams.embedContainer,
      powerBiEmbedParams.embedConfiguration,
    );
    const PowerBiPostEmbed = new CustomEvent('PowerBiPostEmbed', {
      detail: report,
    });
    report.on('loaded', () => {
      report.render();
    });
    report.on('rendered', () => {
      window.dispatchEvent(PowerBiPostEmbed);
      // Start token refresh timer if enabled.
      if (embedSettings.token_refresh_enabled) {
        scheduleTokenRefresh(embedContainer, embedSettings, powerbiClient);
      }
    });
  });
},
```

Add the `scheduleTokenRefresh` function as a module-level helper inside the IIFE (before `pwbiEmbed = {`):

```javascript
/**
 * Schedule a token refresh before the current embed token expires.
 *
 * Uses the `exp` field from the JWT payload (already parsed upstream).
 * Adds jitter to prevent thundering herd on cached pages.
 *
 * @param {HTMLElement} embedContainer - The container element for the embed.
 * @param {object} embedSettings - The drupalSettings entry for this report.
 * @param {object} powerbiClient - The global powerbi client object.
 */
function scheduleTokenRefresh(embedContainer, embedSettings, powerbiClient) {
  const arrayToken = embedSettings.accessToken.split('.');
  if (arrayToken.length < 2) {
    return;
  }
  const tokenPayload = JSON.parse(atob(arrayToken[1]));
  const expMs = tokenPayload.exp * 1000;
  const refreshMinutes = embedSettings.token_refresh_minutes ?? 10;
  const refreshMs = refreshMinutes * 60 * 1000;
  // Add up to 30 seconds jitter to prevent thundering herd.
  const jitter = Math.random() * 30000;
  const delay = expMs - Date.now() - refreshMs + jitter;

  if (delay <= 0) {
    // Token already close to expiry or past; refresh immediately.
    performTokenRefresh(embedContainer, embedSettings, powerbiClient);
    return;
  }

  setTimeout(() => {
    performTokenRefresh(embedContainer, embedSettings, powerbiClient);
  }, delay);

  // Also refresh when tab becomes visible (catches expiry during inactivity).
  document.addEventListener('visibilitychange', function onVisible() {
    if (document.visibilityState === 'visible') {
      const nowMs = Date.now();
      if (nowMs >= expMs - refreshMs) {
        document.removeEventListener('visibilitychange', onVisible);
        performTokenRefresh(embedContainer, embedSettings, powerbiClient);
      }
    }
  });
}

/**
 * Fetch a fresh embed token from Drupal and apply it to the live report.
 *
 * Uses `powerbi.get(embedContainer)` (Microsoft's recommended pattern) to
 * obtain the live report reference without a registry.
 *
 * @param {HTMLElement} embedContainer
 * @param {object} embedSettings
 * @param {object} powerbiClient
 */
async function performTokenRefresh(embedContainer, embedSettings, powerbiClient) {
  const { workspaceId, id: reportId, datasetId } = embedSettings;
  try {
    // Get CSRF token (works for anonymous users via cookie-based session).
    const csrfResponse = await fetch('/session/token');
    const csrfToken = await csrfResponse.text();

    const refreshUrl = `/pwbi/token-refresh/${workspaceId}/${reportId}?dataset_id=${encodeURIComponent(datasetId)}`;
    const response = await fetch(refreshUrl, {
      method: 'GET',
      headers: {
        'X-CSRF-Token': csrfToken,
        'Accept': 'application/json',
      },
    });

    if (!response.ok) {
      console.error('[pwbi] Token refresh HTTP error', response.status);
      return;
    }

    const data = await response.json();
    if (!data.token) {
      console.error('[pwbi] Token refresh response missing token');
      return;
    }

    // Use powerbi.get() to retrieve the live report reference.
    const liveReport = powerbiClient.get(embedContainer);
    await liveReport.setAccessToken(data.token);

    // Update local embedSettings and reschedule.
    embedSettings.accessToken = data.token;
    scheduleTokenRefresh(embedContainer, embedSettings, powerbiClient);
  }
  catch (err) {
    console.error('[pwbi] Token refresh failed', err);
  }
}
```

---

---

### File 10: `pwbi/src/Commands/PwbiCommands.php` — NEW FILE (Drush diagnostic commands)

Two Drush commands for verifying the token refresh chain from the CLI:

- **`drush pwbi:auth-check`** — Verifies the Azure AD OAuth2 service principal handshake. Shows token
  truncation, expiry time, and whether the cached token is already stale. Run this first when
  troubleshooting — if this fails, no embed tokens can be generated.
- **`drush pwbi:token-refresh <workspace_id> <report_id> <dataset_id>`** — Calls `getEmbedToken()` through
  the full server-side chain (OAuth2 → Power BI REST API → embed token). Shows the resulting token
  (truncated) and expiry. Confirms the GCC endpoint is being used correctly.

Requires Drush 12 (attribute-based command registration). File lives at `pwbi/src/Commands/PwbiCommands.php`.

---

### File 11: `pwbi/drush.services.yml` — NEW FILE (Drush service registration)

```yaml
services:
  pwbi.commands:
    class: Drupal\pwbi\Commands\PwbiCommands
    arguments:
      - '@pwbi_api.client'
      - '@oauth2_client.service'
    tags:
      - { name: drush.command }
```

After adding `drush.services.yml`, run `drush cr` to rebuild the container so Drush discovers the new commands.

---

## Review Decisions

These decisions are locked from the architecture review session. Do not re-debate.

| Decision | Resolution |
|---|---|
| Storage for new settings | Config API (`pwbi.settings`) for api_endpoint, refresh toggle, refresh minutes. State API unchanged for `pwbi_workspaces`. |
| CSRF protection | `csrf_token: TRUE` route option — standard Drupal pattern, correct for both authenticated and anonymous users (cookie-based sessions). |
| Token refresh as core module or submodule | Core module. It is a correctness feature, not optional. |
| Controller base class | `ControllerBase` (not `ContainerInjectionInterface` directly). |
| `load()` vs `embed()` | Irrelevant for `setAccessToken()` — both return a compatible Report object. Do not change the existing `load()` call. |
| Report reference in timer | `powerbi.get(embedContainer)` — Microsoft's own recommended pattern. No custom registry needed. |
| Allowlist validation | Controller must confirm the workspace_id is in the State API workspaces list before generating a token. |
| Refresh controller API call | Call `getEmbedToken()` only — do NOT call `getEmbedDataFromApi()` in the refresh path (3x API cost). The `datasetId` comes from `drupalSettings` on initial load, passed as a query parameter to the refresh endpoint. |
| PHP typed constants | PHP 8.3+ supports `const` with typed values; module targets PHP 8.1+. Use regular `private const` (untyped) for the `API_ENDPOINTS` array. |
| Form change | `PowerBiEmbedConfigForm` must change from `FormBase` to `ConfigFormBase` to properly handle Config API saving with cache invalidation. |

---

## Known Issues / Gotchas

### 1. The subscribe endpoint 405 error (NOT our problem)
The site also observes a `405 Method Not Allowed` on:
`https://wabi-us-gov-virginia-redirect.analysis.usgovcloudapi.net/powerbi/refresh/subscribe`

Root cause: HTTP 302 redirect on Microsoft's routing cluster causes a POST→GET method downgrade (RFC-non-compliant redirect behavior). This is a Microsoft backend issue. The token refresh patch does not interact with this endpoint and does not fix this. Do not attempt to address it.

### 2. Pre-existing schema/State discrepancy
`pwbi/config/schema/pwbi.schema.yml` declares `pwbi_embed.embed` as `type: config_object`, but the actual code stores to Drupal's State API (not Config API). This is a pre-existing bug in the upstream module. Do NOT fix it in this patch — it is out of scope and fixing it would require a data migration.

### 3. PHP empty() strips boolean FALSE and integer 0
The `buildEmbedConfiguration()` method in `PowerBiEmbedFormatter.php` runs a `foreach` cleanup loop that calls `empty()` on all values and unsets them. `empty(FALSE)` and `empty(0)` both return `true`. This means `token_refresh_enabled = false` and `token_refresh_minutes = 0` would be stripped before reaching `drupalSettings`. Fix: re-set the refresh config values after the cleanup loop (see File 5 spec above).

### 4. Thundering herd on cached pages
If a page with an embedded report is cached (e.g., Varnish / CDN), many users may load the page at nearly the same time with the same token expiry time. Without jitter, all their browsers would call the refresh endpoint simultaneously. The `Math.random() * 30000` ms jitter in `scheduleTokenRefresh` addresses this.

### 5. Tab inactivity — token expiry during background
If a user puts the tab in the background for more than the token lifetime, the `setTimeout` fires but the tab is hidden. The `visibilitychange` listener catches the user returning to the tab after expiry and triggers an immediate refresh.

### 6. PHP strict types
All PHP files in this module use `declare(strict_types=1)`. The new controller and any modifications must maintain this.

### 7. `@phpstan-ignore-next-line` on `new static()`
The existing codebase uses `// @phpstan-ignore-next-line` before `return new static(...)` in all `create()` methods. Follow this pattern in the new controller.

---

## Sources & References

- [Power BI Embedded — refresh embed token (JavaScript SDK)](https://learn.microsoft.com/en-us/javascript/api/overview/powerbi/refresh-token)
- [Embed for customers — national clouds](https://learn.microsoft.com/en-us/power-bi/developer/embedded/embed-sample-for-customers-national-clouds)
- [Power BI for US Government overview](https://learn.microsoft.com/en-us/fabric/enterprise/powerbi/service-government-us-overview)
- [Embed using service principal](https://learn.microsoft.com/en-us/power-bi/developer/embedded/embed-service-principal)
- [Drupal ConfigFormBase API](https://api.drupal.org/api/drupal/core!lib!Drupal!Core!Form!ConfigFormBase.php/class/ConfigFormBase)
- [Drupal CSRF token route option](https://www.drupal.org/docs/drupal-apis/routing-system/structure-of-routes#csrf_token)
- [pwbi module on drupal.org](https://www.drupal.org/project/pwbi)
- [pwbi GitLab source](https://git.drupalcode.org/project/pwbi)
