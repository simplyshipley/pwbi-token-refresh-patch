<?php

declare(strict_types=1);

namespace Drupal\Tests\pwbi_purge\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;

/**
 * Test for the purge configuration form.
 */
class ConfigFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'pwbi_purge',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests the form for the purge configuration.
   */
  public function testConfigFormP(): void {
    $user = $this->drupalCreateUser([
      'configure pwbi',
    ]);

    $assert_session = $this->assertSession();
    $this->drupalGet(Url::fromRoute('pwbi_purge.settings'));
    $assert_session->statusCodeEquals(403);
    $this->drupalLogin($user);
    $this->drupalGet(Url::fromRoute('pwbi_purge.settings'));
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('cron_window')->setValue(500);
    $assert_session->buttonExists('Save configuration')->press();
    $assert_session->statusMessageContains('The configuration options have been saved.', 'status');
    $this->drupalGet(Url::fromRoute('pwbi_purge.settings'));
    $assert_session->fieldValueEquals('cron_window', 500);

    self::assertEquals(500, $this->config('pwbi_purge.settings')->get('cron_window'));
  }

}
