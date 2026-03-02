<?php

declare(strict_types=1);

namespace Drupal\pwbi\Plugin\Field\FieldFormatter;

use Drupal\breakpoint\BreakpointManager;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\pwbi\Event\PwbiEmbedOverlaysEvent;
use Drupal\pwbi\Plugin\Field\FieldType\PowerBiEmbedField;
use Drupal\pwbi\PowerBiEmbed;
use Drupal\pwbi\Traits\PowerBiLangTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Plugin implementation of the 'pwbi_embed_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "pwbi_embed_formatter",
 *   label = @Translation("PowerBi Embed report"),
 *   field_types = {
 *     "pwbi_embed"
 *   }
 * )
 */
class PowerBiEmbedFormatter extends FormatterBase {

  use PowerBiLangTrait;

  public function __construct(
    protected string $plugin_id,
    protected mixed $plugin_definition,
    protected FieldDefinitionInterface $field_definition,
    protected $settings,
    protected $label,
    protected string $view_mode,
    protected array $third_party_settings,
    protected PowerBiEmbed $pwbiEmbed,
    protected BreakpointManager $breakpointManager,
    protected EventDispatcherInterface $dispatcher,
    protected TimeInterface $time,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly CsrfTokenGenerator $csrfToken,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    // @phpstan-ignore-next-line
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('pwbi_embed.embed'),
      $container->get('breakpoint.manager'),
      $container->get('event_dispatcher'),
      $container->get('datetime.time'),
      $container->get('config.factory'),
      $container->get('csrf_token'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $element = [];
    $overlay_top = [];
    $overlay_blocking = [];
    $overlays_event = new PwbiEmbedOverlaysEvent($overlay_top, $overlay_blocking);
    $this->dispatcher->dispatch($overlays_event, PwbiEmbedOverlaysEvent::PWBI_OVERLAYS);
    $overlay_top = $overlays_event->overlay_top ?? [];
    $overlay_blocking = $overlays_event->overlay_blocking ?? [];

    // Load once outside the loop — config factory is cached internally but
    // loading here makes the single-read intent explicit and avoids redundant
    // calls to buildEmbedConfiguration() for multi-report pages.
    $pwbi_settings = $this->configFactory->get('pwbi.settings');

    foreach ($items as $item) {
      $report_id = $item->getValue()['report_id'];
      $workspace = $item->getValue()['workspace_id'];
      $report_breakpoint_height = unserialize($item->getValue()['report_breakpoints_height'], ['allowed_classes' => FALSE]);
      $report_width = $item->getValue()['report_width'];
      $report_height_units = $item->getValue()['report_height_units'];
      $report_width_units = $item->getValue()['report_width_units'];
      $report_configuration = $this->pwbiEmbed->getEmbedDataFromApi($workspace, $report_id);
      if (empty($report_configuration)) {
        // API call failed — getEmbedDataFromApi() already logged the error.
        // Render nothing for this item rather than crashing the page.
        continue;
      }
      $token_expiration = new DrupalDateTime((string) $report_configuration['tokenExpirationDate']);
      $current_time = $this->time->getRequestTime();
      $cache_max_age = 0;
      if ($token_expiration->getTimestamp() > $current_time) {
        $cache_max_age = $token_expiration->getTimestamp() - $current_time;
        // When JS auto-refresh is enabled, expire the render cache before the
        // refresh window so any new page load always gets a token with enough
        // life remaining for the JS timer to schedule properly.
        if ($pwbi_settings->get('token_refresh_enabled')) {
          $refresh_buffer = (int) ($pwbi_settings->get('token_refresh_minutes') ?? 10) * 60;
          $cache_max_age = max(0, $cache_max_age - $refresh_buffer);
        }
      }
      $embed_array = [
        '#cache' => [
          'max-age' => $cache_max_age,
          // Include config:pwbi.settings so changing the token refresh settings
          // in the admin form immediately invalidates cached render elements.
          'tags' => ['pwbi_embed', ...$pwbi_settings->getCacheTags()],
        ],
        '#type' => 'component',
        '#component' => 'pwbi:pwbi-embed',
        '#props' => [
          'report_id' => $report_id,
          'report_width' => $report_width,
          'report_width_units' => $report_width_units,
        ],
        '#slots' => [
          'media_styles' => $this->addMediaStyles($report_id, $report_breakpoint_height, $report_height_units),
        ],
        '#attached' => [
          'drupalSettings' => [
            'pwbi_embed' => [
              $report_id => $this->buildEmbedConfiguration($item, $report_configuration, $langcode, $pwbi_settings),
            ],
            'language' => $langcode,
          ],
        ],
      ];
      if (!empty($overlay_top)) {
        $embed_array['#slots']['overlay_top'] = $overlay_top;
      }
      if (!empty($overlay_blocking)) {
        $embed_array['#slots']['overlay_blocking'] = $overlay_blocking;
      }
      $element[] = $embed_array;
    }

    return $element;
  }

  /**
   * Create render arrays to add media query styles.
   *
   * @param string $elementId
   *   The id of the target element.
   * @param array <array<array<string>>> $heights
   *   Array with the heights for each breakpoint.
   * @param string $units
   *   The units used of the height.
   *
   * @return array <array<string>>
   *   Render array with the styles.
   */
  private function addMediaStyles(string $elementId, array $heights, string $units): array {
    $styles = [];
    $break_points = $this->breakpointManager->getBreakpointsByGroup('pwbi');
    foreach ($break_points as $break_point) {
      /** @var \Drupal\breakpoint\Breakpoint $break_point */
      if (!$heights[$break_point->pluginId]['height']) {
        continue;
      }
      $media = "@media %s  {
                    .media-%s {
                        height: %s%s;
            }
                }";
      $styles[] = [
        '#type' => 'html_tag',
        '#tag' => 'style',
        '#value' => sprintf($media, $break_point->getMediaQuery(), $elementId, $heights[$break_point->pluginId]['height'], $units),
      ];
    }

    return $styles;
  }

  /**
   * Create the array with the js options for embedding.
   *
   * @param \Drupal\pwbi\Plugin\Field\FieldType\PowerBiEmbedField $item
   *   Configuration option from the pwbi_embed field.
   * @param array <mixed> $report_configuration
   *   Embed configuration from the Rest API.
   * @param string $langcode
   *   Language drupal code.
   *
   * @return array <mixed>
   *   The options that will be used to embed a powerbi report.
   */
  private function buildEmbedConfiguration(PowerBiEmbedField $item, array $report_configuration, string $langcode, ImmutableConfig $pwbi_settings): array {
    $embed_options = $report_configuration;
    $embed_options['tokenType'] = $item->getValue()['token_type'];
    $embed_options['type'] = $item->getValue()['embed_type'];
    $embed_options['id'] = $item->getValue()['report_id'];
    $embed_options['settings']['layoutType'] = (int) $item->getValue()['report_layout'];
    $embed_options['settings']['localeSettings'] = $this->getPowerBiLangcode($langcode);
    $embed_options['tokenType'] = (int) ($embed_options['tokenType'] === 'Embed');

    // Pass workspaceId so the JS refresh call can address the right workspace.
    $embed_options['workspaceId'] = $item->getValue()['workspace_id'];

    foreach ($embed_options as $key => $embedOption) {
      if (empty($embedOption)) {
        unset($embed_options[$key]);
      }
    }

    // Set boolean/int config after the empty() cleanup loop since empty(FALSE)
    // and empty(0) are truthy and would strip these values if set beforehand.
    $embed_options['token_refresh_enabled'] = (bool) ($pwbi_settings->get('token_refresh_enabled') ?? FALSE);
    $embed_options['token_refresh_minutes'] = (int) ($pwbi_settings->get('token_refresh_minutes') ?? 10);
    $embed_options['debug_enabled'] = (bool) ($pwbi_settings->get('debug_enabled') ?? FALSE);
    $embed_options['block_subscribe_heartbeat'] = (bool) ($pwbi_settings->get('block_subscribe_heartbeat') ?? FALSE);

    // Generate a per-session CSRF token for the token refresh endpoint.
    // The route has _csrf_token: TRUE, so the JS must append ?token=... to
    // each refresh request. The token is session-specific; render caches for
    // refresh-enabled embeds already have max-age:0 so stale tokens can't
    // be served to a different user from cache.
    if ($embed_options['token_refresh_enabled']) {
      $workspace = $embed_options['workspaceId'] ?? '';
      $report = $embed_options['id'] ?? '';
      if ($workspace && $report) {
        $embed_options['token_refresh_csrf'] = $this->csrfToken->get(
          "pwbi/token-refresh/{$workspace}/{$report}"
        );
      }
    }

    return $embed_options;
  }

}
