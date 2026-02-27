<?php

declare(strict_types=1);

namespace Drupal\pwbi_banner\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\pwbi_banner\DisclaimerBanner;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Power Bi disclaimer block.
 */
#[Block(
  id: "pwbi_disclaimer_block",
  admin_label: new TranslatableMarkup("Power Bi disclaimer block"),
  category: new TranslatableMarkup("Power Bi")
)]

class DisclaimerBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected DisclaimerBanner $disclaimer_banner,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('pwbi_banner.disclaimer_banner'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    if (!$this->disclaimer_banner->showAsBlock()) {
      return [];
    }
    return $this->disclaimer_banner->getBannerRenderArray();
  }

}
