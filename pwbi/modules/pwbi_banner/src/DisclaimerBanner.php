<?php

declare(strict_types=1);

namespace Drupal\pwbi_banner;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\pwbi_banner\Form\PowerBiBannerSettingsForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service (pwbi_banner.disclaimer_banner) to create banner render array.
 */
class DisclaimerBanner implements ContainerInjectionInterface {

  public function __construct(
    protected readonly ConfigFactoryInterface $config_factory,
    protected readonly RequestStack $request_stack,
    protected readonly ModuleHandler $module_handler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    // @phpstan-ignore-next-line
    return new static(
      $container->get('config.factory'),
      $container->get('request_stack'),
      $container->get('module_handler'),
    );
  }

  /**
   * Create a render array for disclaimer banner.
   *
   * @return array
   *   The render array.
   */
  public function getBannerRenderArray(): array {
    $config = $this->config_factory->get('pwbi_banner.settings');
    $domain = $this->request_stack->getCurrentRequest()->getHost();
    $display_type = 'pwbi';
    if ($this->showAsBlock()) {
      $display_type = 'block';
    }
    $render_array = [
      '#type' => 'component',
      '#component' => 'pwbi_banner:disclaimer-banner',
      '#attached' => [
        'drupalSettings' => [
          'pwbi_banner' => [
            'days' => 365,
            'domain' => $domain,
            'bannerSelector' => '.pwbi-disclaimer-banner',
            'bannerAction' => '.pwbi-disclaimer-banner',
            'must_have' => $config->get('banner_type'),
          ],
        ],
      ],
      '#props' => [
        'banner_text' => [
          '#markup' => $config->get('banner_text')['value'] ?? '',
        ],
        'icon_path' => base_path() . $this->module_handler->getModule('pwbi_banner')->getPath() . '/components/disclaimer-banner/icon.png',
        'display_type' => $display_type,
        'display_icon' => $display_type,
      ],
    ];

    $cache = new CacheableMetadata();
    $cache->addCacheableDependency($config);
    $cache->applyTo($render_array);
    return $render_array;

  }

  /**
   * Check if disclaimer banner is configured to be shown as a block.
   *
   * @return bool
   *   TRUE if it is shown as a block.
   */
  public function showAsBlock(): bool {
    $config = $this->config_factory->get('pwbi_banner.settings');
    return $config->get('display_options') === PowerBiBannerSettingsForm::DISPLAY_BANNER_AS_BLOCK;
  }

  /**
   * Check if disclaimer banner is configured to be shown blockng .
   *
   * @return bool
   *   TRUE if it is shown as a block.
   */
  public function isBlocking(): bool {
    $config = $this->config_factory->get('pwbi_banner.settings');
    return $config->get('display_options') === PowerBiBannerSettingsForm::DISPLAY_BANNER_BLOCKING;
  }

}
