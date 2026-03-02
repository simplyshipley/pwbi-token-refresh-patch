<?php

declare(strict_types=1);

namespace Drupal\pwbi\Commands;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\oauth2_client\Service\Oauth2ClientService;
use Drupal\pwbi\Api\PowerBiClient;
use Drupal\pwbi\PowerBiEmbed;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for diagnosing Power BI connectivity and token refresh.
 *
 * Commands:
 *   pwbi:auth-check    — verify OAuth2 service principal handshake.
 *   pwbi:flush-token   — delete the cached Service Principal OAuth2 token.
 *   pwbi:list-reports  — list all Power BI reports configured as Media entities.
 *   pwbi:token-refresh — generate a fresh embed token for a report.
 */
class PwbiCommands extends DrushCommands {

  /**
   * Maps configured API endpoint URLs to their expected OAuth2 audience (aud).
   *
   * When the cached token's `aud` claim doesn't match this map for the
   * configured endpoint, the token was issued for the wrong cloud environment
   * and API calls will fail with 401 Unauthorized.
   */
  private const ENDPOINT_AUDIENCE_MAP = [
    'https://api.powerbi.com'          => 'https://analysis.windows.net/powerbi/api',
    'https://api.powerbigov.us'        => 'https://analysis.usgovcloudapi.net/powerbi/api',
    'https://api.high.powerbigov.us'   => 'https://analysis.usgovcloudapi.net/powerbi/api',
    'https://api.mil.powerbigov.us'    => 'https://analysis.usgovcloudapi.net/powerbi/api',
  ];

  public function __construct(
    protected readonly PowerBiClient $pwbiClient,
    protected readonly Oauth2ClientService $auth,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    protected readonly StateInterface $state,
    protected readonly KeyValueFactoryInterface $keyValueFactory,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    // @phpstan-ignore-next-line
    return new static(
      $container->get('pwbi_api.client'),
      $container->get('oauth2_client.service'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('state'),
      $container->get('keyvalue'),
      $container->get('config.factory'),
    );
  }

  /**
   * Verify the Power BI service principal OAuth2 handshake.
   *
   * Attempts to obtain a Bearer token from Azure AD via the
   * pwbi_service_principal OAuth2 client. Run this first — if it fails,
   * embed token generation cannot succeed.
   */
  #[CLI\Command(name: 'pwbi:auth-check', aliases: ['pwbi-ac'])]
  #[CLI\Help(description: 'Verify the Power BI service principal OAuth2 handshake.')]
  #[CLI\Usage(name: 'drush pwbi:auth-check', description: 'Test OAuth2 connectivity to Azure AD.')]
  public function authCheck(): void {
    $this->io()->title('Power BI OAuth2 Auth Check');

    try {
      $token = $this->auth->getAccessToken('pwbi_service_principal');
    }
    catch (\Throwable $e) {
      $this->io()->error('Failed to obtain OAuth2 token: ' . $e->getMessage());
      return;
    }

    // oauth2_client may return null without throwing if the client plugin is
    // not configured (e.g. 'pwbi_service_principal' entity does not exist).
    if ($token === NULL) {
      $this->io()->error('OAuth2 client "pwbi_service_principal" is not configured. Visit Admin → Configuration → Services → OAuth2 Clients to set it up.');
      return;
    }

    $tokenValue = $token->getToken();
    if (empty($tokenValue)) {
      $this->io()->error('OAuth2 handshake returned an empty token.');
      return;
    }

    $expires = $token->getExpires();
    $expiry = $expires
      ? date('Y-m-d H:i:s T', $expires) . ' (in ' . round(($expires - time()) / 60) . ' min)'
      : 'not reported';
    $expired = $token->hasExpired();

    // Decode the JWT payload to verify the OAuth audience matches the
    // configured cloud endpoint. A mismatch means the cached token was issued
    // for the wrong environment (e.g. commercial token used against GCC API).
    $configuredEndpoint = $this->configFactory->get('pwbi.settings')->get('pwbi_api_endpoint')
      ?? 'https://api.powerbi.com';
    $expectedAudience = self::ENDPOINT_AUDIENCE_MAP[$configuredEndpoint]
      ?? self::ENDPOINT_AUDIENCE_MAP['https://api.powerbi.com'];

    $audienceLabel = 'not a JWT / could not parse';
    $audienceMismatch = FALSE;
    $actualAudience = '';
    $parts = explode('.', $tokenValue);
    if (count($parts) === 3) {
      // JWT segments use URL-safe base64 with padding stripped. Re-add padding
      // before decoding — base64_decode strict mode rejects bad chars but does
      // not catch missing padding, which causes silent truncation.
      $segment = strtr($parts[1], '-_', '+/');
      $segment = str_pad($segment, strlen($segment) + (4 - strlen($segment) % 4) % 4, '=');
      $payloadJson = base64_decode($segment, TRUE);
      $payload = ($payloadJson !== FALSE) ? json_decode($payloadJson, TRUE) : NULL;
      if (is_array($payload)) {
        $actualAudience = (string) ($payload['aud'] ?? 'unknown');
        if (str_starts_with($actualAudience, $expectedAudience)) {
          $audienceLabel = $actualAudience . ' ✓ matches configured endpoint';
        }
        else {
          $audienceLabel = $actualAudience . ' ✗ MISMATCH — expected: ' . $expectedAudience;
          $audienceMismatch = TRUE;
        }
      }
    }

    $this->io()->success('OAuth2 handshake successful!');
    $this->io()->table(
      ['Property', 'Value'],
      [
        ['Token (truncated)', substr($tokenValue, 0, 30) . '...'],
        ['Expires', $expiry],
        ['Is expired', $expired ? 'YES (cached token is stale)' : 'No'],
        ['Configured endpoint', $configuredEndpoint],
        ['Expected audience', $expectedAudience],
        ['Token audience (aud)', $audienceLabel],
      ],
    );

    if ($audienceMismatch) {
      $this->io()->warning([
        'The cached token was issued for a different cloud environment.',
        '',
        "  Token audience:    {$actualAudience}",
        "  Expected audience: {$expectedAudience}",
        "  Configured endpoint: {$configuredEndpoint}",
        '',
        'Power BI API calls will fail with 401 Unauthorized until this is fixed.',
        '',
        'To fix:',
        '  1. Run: drush pwbi:flush-token',
        '  2. Verify the OAuth2 client scope matches your endpoint:',
        '       Admin → Configuration → Services → OAuth2 Clients → pwbi_service_principal',
        "       Scope should be: {$expectedAudience}/.default",
        '  3. Run: drush pwbi:auth-check (to confirm the new token has the correct audience)',
      ]);
    }
  }

  /**
   * Delete the cached Service Principal OAuth2 token from Drupal State.
   *
   * Forces oauth2_client to request a fresh token on the next API call.
   * Use this after changing the cloud endpoint, rotating client credentials,
   * or when the cached token has the wrong audience for your environment.
   */
  #[CLI\Command(name: 'pwbi:flush-token', aliases: ['pwbi-ft'])]
  #[CLI\Help(description: 'Delete the cached Service Principal OAuth2 token.')]
  #[CLI\Usage(name: 'drush pwbi:flush-token', description: 'Clear the cached token so a fresh one is fetched on next use.')]
  public function flushToken(): void {
    $key = 'oauth2_client_access_token-pwbi_service_principal';
    $existing = $this->state->get($key);

    if ($existing === NULL) {
      $this->io()->note('No cached Service Principal token found — nothing to flush.');
      return;
    }

    $this->state->delete($key);
    $this->io()->success('Cached Service Principal OAuth2 token deleted. A fresh token will be requested on next use.');
    $this->io()->text('Run <info>drush pwbi:auth-check</info> to verify the new token.');
  }

  /**
   * List all Power BI reports configured as Media entities on this site.
   *
   * Scans all media entities for fields of type pwbi_embed and displays
   * their workspace and report IDs. Use this to find the IDs needed for
   * pwbi:token-refresh without opening the Drupal admin UI.
   */
  #[CLI\Command(name: 'pwbi:list-reports', aliases: ['pwbi-lr'])]
  #[CLI\Help(description: 'List all Power BI reports configured as Media entities.')]
  #[CLI\Usage(name: 'drush pwbi:list-reports', description: 'Show all workspace/report ID pairs on this site.')]
  public function listReports(): void {
    $this->io()->title('Configured Power BI Reports');

    $reports = $this->discoverReports();

    if (empty($reports)) {
      $this->io()->warning('No Power BI report Media entities found on this site.');
      return;
    }

    $rows = [];
    foreach ($reports as $report) {
      $rows[] = [
        $report['media_id'],
        $report['label'],
        $report['workspace_id'],
        $report['report_id'],
        $report['dataset_id'] ?: '(not cached — visit the report page first)',
      ];
    }

    $this->io()->table(
      ['Media ID', 'Label', 'Workspace ID', 'Report ID', 'Dataset ID (cached)'],
      $rows,
    );

    $this->io()->text(sprintf('%d report(s) found. Use <info>drush pwbi:token-refresh</info> to generate a fresh embed token.', count($reports)));
  }

  /**
   * Generate a fresh Power BI embed token for a report.
   *
   * Fetches the dataset_id automatically from the Power BI API (same as the
   * field formatter does on render) so you only need the workspace and report
   * IDs. If no arguments are given and only one report is configured on the
   * site, it is used automatically.
   *
   * @param string $workspace_id
   *   Power BI workspace (group) UUID. Optional if only one report exists.
   * @param string $report_id
   *   Power BI report UUID. Optional if only one report exists.
   */
  #[CLI\Command(name: 'pwbi:token-refresh', aliases: ['pwbi-tr'])]
  #[CLI\Help(description: 'Generate a fresh Power BI embed token (dataset_id auto-fetched from API).')]
  #[CLI\Argument(name: 'workspace_id', description: 'Power BI workspace UUID. Auto-detected if only one report exists.')]
  #[CLI\Argument(name: 'report_id', description: 'Power BI report UUID. Auto-detected if only one report exists.')]
  #[CLI\Usage(name: 'drush pwbi:token-refresh', description: 'Auto-detect workspace/report from the single configured Media entity.')]
  #[CLI\Usage(
    name: 'drush pwbi:token-refresh xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
    description: 'Generate a fresh embed token for the specified workspace and report.',
  )]
  public function tokenRefresh(string $workspace_id = '', string $report_id = ''): void {
    $this->io()->title('Power BI Embed Token Refresh');

    $cached_dataset_id = '';

    // Auto-discover workspace/report from Media entities when not supplied.
    if (empty($workspace_id) || empty($report_id)) {
      $reports = $this->discoverReports();

      if (empty($reports)) {
        $this->io()->error('No Power BI report Media entities found. Run <info>drush pwbi:list-reports</info> to verify.');
        return;
      }

      if (count($reports) > 1) {
        // Show an interactive picker — no manual UUID entry required.
        $choices = [];
        foreach ($reports as $r) {
          $cached = !empty($r['dataset_id']) ? 'dataset cached' : 'dataset not yet cached';
          $choices[] = sprintf('%s [Media #%s] — %s', $r['label'], $r['media_id'], $cached);
        }
        $chosen = $this->io()->choice('Which report do you want to refresh?', $choices, $choices[0]);
        $idx = (int) array_search($chosen, $choices, TRUE);
        $workspace_id      = $reports[$idx]['workspace_id'];
        $report_id         = $reports[$idx]['report_id'];
        $cached_dataset_id = $reports[$idx]['dataset_id'];
        $this->io()->text(sprintf('Selected: <info>%s</info> (Media #%s)', $reports[$idx]['label'], $reports[$idx]['media_id']));
      }
      else {
        $workspace_id      = $reports[0]['workspace_id'];
        $report_id         = $reports[0]['report_id'];
        $cached_dataset_id = $reports[0]['dataset_id'];
        $this->io()->text(sprintf('Auto-detected report: <info>%s</info> (Media #%s)', $reports[0]['label'], $reports[0]['media_id']));
      }
    }
    else {
      // IDs were provided as arguments — check if we have a cached dataset_id.
      $entry = $this->keyValueFactory->get(PowerBiEmbed::PWBI_REPORT_META)->get($report_id);
      $cached_dataset_id = $entry['dataset_id'] ?? '';
    }

    // Validate both IDs are proper UUIDs before hitting the API.
    $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    foreach (['workspace_id' => $workspace_id, 'report_id' => $report_id] as $name => $value) {
      if (!preg_match($uuidPattern, $value)) {
        $this->io()->error("Invalid UUID format for {$name}: {$value}");
        return;
      }
    }

    // Use cached dataset_id when available to skip an API round-trip.
    if (!empty($cached_dataset_id)) {
      $dataset_id = $cached_dataset_id;
      $this->io()->text("Dataset ID: <info>{$dataset_id}</info> (from cache)");
    }
    else {
      // Fetch datasetId from the API — same call the field formatter makes.
      $this->io()->text("Fetching report info for workspace <info>{$workspace_id}</info>...");
      $reportInfoRaw = $this->pwbiClient->getGroupReport($workspace_id, $report_id);
      $reportInfo    = Json::decode($reportInfoRaw);

      // connect() now returns JSON in all cases:
      // - Success          → {"datasetId":"...","embedUrl":"...",...}
      // - PBI error body   → {"error":{"code":"...","message":"..."}}
      // - Empty body (403) → {"httpError":403,"httpReason":"Forbidden","message":"..."}
      // - No response      → plain string (Json::decode → null)
      if (!is_array($reportInfo)) {
        $this->io()->error('Power BI API request failed. Raw response:');
        $this->io()->text($reportInfoRaw);
        return;
      }

      // Empty-body HTTP error: tenant setting likely disabled.
      if (isset($reportInfo['httpError'])) {
        $status = $reportInfo['httpError'];
        $reason = $reportInfo['httpReason'] ?? '';
        $this->io()->error("Power BI API returned HTTP {$status} {$reason} with an empty response body.");
        if ($status === 403) {
          $this->io()->note([
            'An empty 403 from the Power BI API typically means the tenant-level',
            '"Allow service principals to use Power BI APIs" setting is disabled.',
            'A Power BI admin must enable it at:',
            'app.powerbigov.us/admin → Tenant settings → Developer settings',
            'Docs: https://learn.microsoft.com/en-us/power-bi/developer/embedded/embed-service-principal',
          ]);
        }
        return;
      }

      // Power BI error object with body: {"error":{"code":"...","message":"..."}}.
      if (!empty($reportInfo['error'])) {
        $code = $reportInfo['error']['code'] ?? 'unknown';
        $msg  = $reportInfo['error']['message'] ?? 'no message';
        $this->io()->error("Power BI API error [{$code}]: {$msg}");
        $this->maybeHintAudienceMismatch($msg);
        return;
      }

      if (empty($reportInfo['datasetId'])) {
        $this->io()->error('Power BI returned a report object but datasetId is missing. Raw response:');
        $this->io()->text($reportInfoRaw);
        return;
      }

      $dataset_id = $reportInfo['datasetId'];
      $this->io()->text("Dataset ID: <info>{$dataset_id}</info>");
    }

    // Generate the embed token.
    $body = [
      'datasets' => [['id' => $dataset_id]],
      'reports'  => [['id' => $report_id]],
    ];

    $this->io()->text('Requesting embed token...');

    try {
      $raw = $this->pwbiClient->getEmbedToken($body);
    }
    catch (\Throwable $e) {
      $this->io()->error('Embed token request failed: ' . $e->getMessage());
      return;
    }

    $result = Json::decode($raw);

    if (!is_array($result)) {
      $this->io()->error('Embed token API request failed. Raw response:');
      $this->io()->text($raw);
      return;
    }

    if (isset($result['httpError'])) {
      $status = $result['httpError'];
      $reason = $result['httpReason'] ?? '';
      $this->io()->error("Embed token API returned HTTP {$status} {$reason} with an empty response body.");
      if ($status === 403) {
        $this->io()->note([
          'An empty 403 on GenerateToken typically means the tenant-level',
          '"Allow service principals to use Power BI APIs" setting is disabled.',
          'Docs: https://learn.microsoft.com/en-us/power-bi/developer/embedded/embed-service-principal',
        ]);
      }
      return;
    }

    if (!empty($result['error'])) {
      $code = $result['error']['code'] ?? 'unknown';
      $msg  = $result['error']['message'] ?? 'no message';
      $this->io()->error("Power BI embed token error [{$code}]: {$msg}");
      $this->maybeHintAudienceMismatch($msg);
      return;
    }

    if (empty($result['token'])) {
      $this->io()->error('Power BI returned a response but the token field is missing. Raw response:');
      $this->io()->text($raw);
      return;
    }

    $this->io()->success('Embed token generated successfully!');
    $this->io()->table(
      ['Property', 'Value'],
      [
        ['Workspace ID', $workspace_id],
        ['Report ID', $report_id],
        ['Dataset ID', $dataset_id],
        ['Token (truncated)', substr($result['token'], 0, 40) . '...'],
        ['Expiration', $result['expiration'] ?? 'not returned'],
        ['Token ID', $result['tokenId'] ?? 'n/a'],
      ],
    );
  }

  /**
   * Print a hint if an API error message suggests an OAuth2 audience mismatch.
   *
   * Power BI returns 401 with error messages containing "audience" or
   * "unauthorized" when the Bearer token was issued for a different cloud
   * (e.g. a commercial token used against the GCC API endpoint). This helper
   * surfaces the fix steps inline so the developer doesn't have to guess.
   *
   * @param string $msg
   *   The error message from the Power BI API response.
   */
  protected function maybeHintAudienceMismatch(string $msg): void {
    $lower = strtolower($msg);
    if (!str_contains($lower, 'audience') && !str_contains($lower, 'unauthorized')) {
      return;
    }

    $configuredEndpoint = $this->configFactory->get('pwbi.settings')->get('pwbi_api_endpoint')
      ?? 'https://api.powerbi.com';
    $expectedAudience = self::ENDPOINT_AUDIENCE_MAP[$configuredEndpoint]
      ?? self::ENDPOINT_AUDIENCE_MAP['https://api.powerbi.com'];

    $this->io()->note([
      'This error may indicate an OAuth2 token audience mismatch.',
      'The cached token may have been issued for a different cloud environment.',
      '',
      "  Configured endpoint: {$configuredEndpoint}",
      "  Expected audience:   {$expectedAudience}/.default",
      '',
      'To fix:',
      '  1. Run: drush pwbi:flush-token',
      '  2. Verify the OAuth2 client scope at:',
      '       Admin → Configuration → Services → OAuth2 Clients → pwbi_service_principal',
      "       Scope should be: {$expectedAudience}/.default",
      '  3. Run: drush pwbi:auth-check (confirm new token has the correct audience)',
    ]);
  }

  /**
   * Discover all Power BI reports from Media entities on this site.
   *
   * Finds every media entity that has a field of type pwbi_embed and returns
   * its workspace_id, report_id, and cached dataset_id (if available).
   *
   * @return array<int, array{media_id: string|int, label: string, workspace_id: string, report_id: string, dataset_id: string}>
   *   Each entry has media_id, label, workspace_id, report_id, dataset_id.
   */
  protected function discoverReports(): array {
    // Find all fields of type pwbi_embed across all entity types.
    $field_map = $this->entityFieldManager->getFieldMapByFieldType('pwbi_embed');
    $media_fields = $field_map['media'] ?? [];

    if (empty($media_fields)) {
      return [];
    }

    // Load per-report metadata from the KeyValueStore (one row per report).
    $meta_store = $this->keyValueFactory->get(PowerBiEmbed::PWBI_REPORT_META);

    $reports = [];
    $storage = $this->entityTypeManager->getStorage('media');

    foreach (array_keys($media_fields) as $field_name) {
      // accessCheck(FALSE): Drush commands run as a privileged server process,
      // not as a web request. Bypassing entity access is intentional here —
      // the command is only available to users with Drush shell access anyway.
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition($field_name . '.workspace_id', '', '<>')
        ->execute();

      if (empty($ids)) {
        continue;
      }

      /** @var \Drupal\media\MediaInterface[] $entities */
      $entities = $storage->loadMultiple($ids);

      foreach ($entities as $entity) {
        if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
          continue;
        }

        $value = $entity->get($field_name)->first()?->getValue();
        if (empty($value['workspace_id']) || empty($value['report_id'])) {
          continue;
        }

        $report_id = (string) $value['report_id'];
        $entry = $meta_store->get($report_id);
        $reports[] = [
          'media_id'     => $entity->id(),
          'label'        => $entity->label() ?? '(no label)',
          'workspace_id' => (string) $value['workspace_id'],
          'report_id'    => $report_id,
          'dataset_id'   => $entry['dataset_id'] ?? '',
        ];
      }
    }

    return $reports;
  }

}
