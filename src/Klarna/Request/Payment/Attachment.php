<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Klarna\Request\Payment;

use Drupal\commerce_klarna_payments\Klarna\Data\Payment\AttachmentInterface;
use Drupal\commerce_klarna_payments\Klarna\Data\Payment\AttachmentItemInterface;
use Drupal\commerce_klarna_payments\Klarna\ObjectNormalizer;

/**
 * Value object for attachments.
 */
class Attachment implements AttachmentInterface {

  use ObjectNormalizer;

  protected $data = [];

  /**
   * {@inheritdoc}
   */
  public function setContentType(string $type) : AttachmentInterface {
    $this->data['content_type'] = $type;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setBody(AttachmentItemInterface $item) : AttachmentInterface {
    $this->data['body'] = \GuzzleHttp\json_encode($item->toArray());
    return $this;
  }

  /**
   * Gets the body.
   *
   * @return \Drupal\commerce_klarna_payments\Klarna\Data\Payment\AttachmentItemInterface|null
   *   The body or NULL.
   */
  public function getBody() : ? AttachmentItemInterface {
    return $this->data['body'] ?? NULL;
  }

}
