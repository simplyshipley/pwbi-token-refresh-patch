<?php

declare(strict_types=1);

namespace Drupal\pwbi\EventSubscriber;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Page response subscriber to set appropriate headers on anonymous requests.
 *
 * Drupal doesn't propagate render arrays max-age to the page, as per
 * https://www.drupal.org/node/2352009
 *
 * This event subscriber take care of that for pages that embed powerbi.
 */
class PwbiResponseSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected TimeInterface $time,
    protected AccountInterface $user,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::RESPONSE][] = ['onResponse'];
    return $events;
  }

  /**
   * Sets expires and max-age for bubbled-up max-age values that are > 0.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onResponse(ResponseEvent $event) {
    if (!$event->isMainRequest() || $this->user->isAuthenticated()) {
      return;
    }

    $response = $event->getResponse();
    if (!($response instanceof CacheableResponseInterface)) {
      return;
    }
    $cache = $response->getCacheableMetadata();
    if (!in_array('pwbi_embed', $cache->getCacheTags())) {
      return;
    }

    $max_age = (int) $cache->getCacheMaxAge();
    if ($max_age !== Cache::PERMANENT) {
      $response->setMaxAge($max_age);
      $date = new \DateTime('@' . ($this->time->getRequestTime() + $max_age));
      $response->setExpires($date);
    }
  }

}
