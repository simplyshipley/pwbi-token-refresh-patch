<?php

namespace Drupal\pwbi_banner\EventSubscriber;

use Drupal\pwbi\Event\PwbiEmbedOverlaysEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\pwbi_banner\DisclaimerBanner;

/**
 * Event subscriber for power bi render array overlays.
 */
class PwbiEmbedOverlaysSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected readonly DisclaimerBanner $disclaimer_banner,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PwbiEmbedOverlaysEvent::PWBI_OVERLAYS => 'addOverlays',
    ];
  }

  /**
   * Add overlays if they are configured.
   *
   * @param \Drupal\pwbi\Event\PwbiEmbedOverlaysEvent $event
   *   Event to alter power bi embed array overlays.
   */
  public function addOverlays(PwbiEmbedOverlaysEvent $event) {
    if ($this->disclaimer_banner->showAsBlock()) {
      return;
    }
    if ($this->disclaimer_banner->isBlocking()) {
      $event->overlay_blocking = $this->disclaimer_banner->getBannerRenderArray();
      return;
    }
    $event->overlay_top = $this->disclaimer_banner->getBannerRenderArray();
  }

}
