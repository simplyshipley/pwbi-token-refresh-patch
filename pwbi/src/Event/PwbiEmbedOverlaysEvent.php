<?php

declare(strict_types=1);

namespace Drupal\pwbi\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Event that allows changing power bi's embed overlays.
 */
class PwbiEmbedOverlaysEvent extends Event {

  const PWBI_OVERLAYS = 'pwbi_overlays';

  public function __construct(
    public array $overlay_top,
    public array $overlay_blocking,
  ) {}

}
