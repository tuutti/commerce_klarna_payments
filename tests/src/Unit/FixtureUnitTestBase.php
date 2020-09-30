<?php

declare(strict_types = 1);

namespace Drupal\Tests\commerce_klarna_payments\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Base class for tests with fixtures.
 */
abstract class FixtureUnitTestBase extends UnitTestCase {

  /**
   * Gets the fixture.
   *
   * @param string $name
   *   The name of the fixture.
   *
   * @return string
   *   The fixture.
   */
  protected function getFixture(string $name) : string {
    return file_get_contents(__DIR__ . '/../../fixtures/' . $name);
  }

}
