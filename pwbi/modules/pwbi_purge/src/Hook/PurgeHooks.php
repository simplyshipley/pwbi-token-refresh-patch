<?php

namespace Drupal\pwbi_purge\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\State\StateInterface;
use Drupal\purge\Plugin\Purge\Invalidation\InvalidationsServiceInterface;
use Drupal\purge\Plugin\Purge\Queue\QueueServiceInterface;
use Drupal\purge\Plugin\Purge\Queuer\QueuersServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Hook implementations.
 */
class PurgeHooks {

  /**
   * The constructor.
   *
   * @param \Drupal\purge\Plugin\Purge\Invalidation\InvalidationsServiceInterface $purgeInvalidationFactory
   *   The invalidation factory.
   * @param \Drupal\purge\Plugin\Purge\Queuer\QueuersServiceInterface $purgeQueuers
   *   The queuers.
   * @param \Drupal\purge\Plugin\Purge\Queue\QueueServiceInterface $purgeQueue
   *   The queue service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The Drupal state.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The request time.
   */
  public function __construct(
    #[Autowire('@purge.invalidation.factory')]
    protected InvalidationsServiceInterface $purgeInvalidationFactory,
    #[Autowire('@purge.queuers')]
    protected QueuersServiceInterface $purgeQueuers,
    #[Autowire('@purge.queue')]
    protected QueueServiceInterface $purgeQueue,
    protected StateInterface $state,
    protected TimeInterface $time,
  ) {}

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function purgeOnCron(): void {
    // In the field formatter we save the power bi report
    // expiration to state so that we only purge if there
    // is a cached power bi report.
    $expiration = $this->state->get('pwbi_purge_expiration');

    if (is_numeric($expiration) && $this->time->getRequestTime() >= $expiration) {
      // If the expiration is in the past we need to purge.
      // The expiration already takes into account the time it takes for
      // the purge to happen.
      $queuer = $this->purgeQueuers->get('coretags');
      $tags = $this->purgeInvalidationFactory->get('tag', 'pwbi_embed');
      $this->purgeQueue->add($queuer, [$tags]);
      // Now we delete the state key, the next time Drupal renders
      // the field formatter it will get added again.
      $this->state->delete('pwbi_purge_expiration');
    }
  }

}
