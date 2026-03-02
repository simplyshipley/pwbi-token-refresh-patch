<?php

declare(strict_types=1);

namespace Drupal\pwbi\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\pwbi\Api\PowerBiClient;
use Drupal\pwbi\PowerBiEmbed;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Returns a fresh embed token for an active Power BI report embed.
 */
class PwbiTokenRefreshController extends ControllerBase {

  private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

  public function __construct(
    protected readonly PowerBiClient $pwbiClient,
    protected readonly PowerBiEmbed $pwbiEmbed,
    protected readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    // @phpstan-ignore-next-line
    return new static(
      $container->get('pwbi_api.client'),
      $container->get('pwbi_embed.embed'),
      $container->get('request_stack'),
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
   *   JSON with `token`, or an error with HTTP 400/403/502.
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
    $dataset_id = (string) $this->requestStack->getCurrentRequest()?->query->get('dataset_id', '');
    if (empty($dataset_id)) {
      return new JsonResponse(['error' => 'dataset_id query parameter required'], 400, [
        'Cache-Control' => 'no-store',
      ]);
    }

    // Validate dataset_id is a properly-formed UUID before passing to API.
    if (!preg_match(self::UUID_PATTERN, $dataset_id)) {
      return new JsonResponse(['error' => 'Invalid dataset_id format'], 400, [
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

    // connect() returns a plain error string on HTTP failure, which
    // Json::decode() resolves to null — guard before subscripting.
    if (!is_array($result) || empty($result['token'])) {
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
    if (!preg_match(self::UUID_PATTERN, $workspace_id) || !preg_match(self::UUID_PATTERN, $report_id)) {
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
