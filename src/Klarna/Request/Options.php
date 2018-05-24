<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Klarna\Request;

use Drupal\commerce_klarna_payments\Klarna\Data\OptionsInterface;
use Drupal\commerce_klarna_payments\Klarna\ObjectNormalizer;

/**
 * Value object for options.
 */
class Options implements OptionsInterface {

  use ObjectNormalizer;

  protected $data = [];

  /**
   * Asserts that given color is valid hexadecimal color.
   *
   * @param string $color
   *   The color code.
   */
  protected function assertColor(string $color) : void {
    if (!ctype_xdigit($color) || strlen($color) !== 6) {
      throw new \InvalidArgumentException('Color must be valid hexadecimal and exactly 6 characters long.');
    }
  }

  /**
   * Sets the color.
   *
   * @param string $color
   *   The color.
   * @param string $type
   *   The type.
   */
  protected function setColor(string $color, string $type) : void {
    // Remove # if added.
    $color = ltrim($color, '#');
    $this->assertColor($color);

    // Add # back.
    $this->data[$type] = sprintf('#%s', $color);
  }

  /**
   * {@inheritdoc}
   */
  public function setButtonColor(string $color) : OptionsInterface {
    $this->setColor($color, 'color_button');
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setButtonTextColor(string $color) : OptionsInterface {
    $this->setColor($color, 'color_button_text');
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setCheckBoxColor(string $color) : OptionsInterface {
    $this->setColor($color, 'color_checkbox');
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setCheckBoxCheckMarkColor(string $color) : OptionsInterface {
    $this->setColor($color, 'color_checkbox_checkmark');
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setHeaderColor(string $color) : OptionsInterface {
    $this->setColor($color, 'color_header');
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setLinkColor(string $color) : OptionsInterface {
    $this->setColor($color, 'color_link');
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setBorderColor(string $color) : OptionsInterface {
    $this->setColor($color, 'color_border');
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setSelectedBorderColor(string $color) : OptionsInterface {
    $this->setColor($color, 'color_border_selected');
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setTextColor(string $color) : OptionsInterface {
    $this->setColor($color, 'color_text');
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setDetailsColor(string $color) : OptionsInterface {
    $this->setColor($color, 'color_details');
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setSecondaryTextColor(string $color) : OptionsInterface {
    $this->setColor($color, 'color_text_secondary');
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setBorderRadius(string $radius) : OptionsInterface {
    $this->data['radius_border'] = $radius;
    return $this;
  }

}
