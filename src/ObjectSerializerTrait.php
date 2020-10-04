<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments;

use Klarna\Model\ModelInterface;
use Klarna\ObjectSerializer;

/**
 * Object serialization helper trait.
 */
trait ObjectSerializerTrait {

  /**
   * Casts the given model as array.
   *
   * @param \Klarna\Model\ModelInterface $model
   *   The model to convert.
   *
   * @return array
   *   The array.
   */
  protected function modelToArray(ModelInterface $model) : array {
    return (array) ObjectSerializer::sanitizeForSerialization($model);
  }

  /**
   * Converts json string to a model.
   *
   * @param string $json
   *   The json.
   * @param string $class
   *   The class.
   *
   * @return \Klarna\Model\ModelInterface
   *   The model.
   */
  protected function jsonToModel(string $json, string $class) : ModelInterface {
    return ObjectSerializer::deserialize($json, $class);
  }

  /**
   * Converts the given model to json.
   *
   * @param \Klarna\Model\ModelInterface $model
   *   The model.
   *
   * @return string
   *   The json.
   */
  protected function modelToJson(ModelInterface $model) : string {
    return json_encode($this->modelToArray($model));
  }

  /**
   * Converts multiple models to array.
   *
   * @param \Klarna\Model\ModelInterface[] $models
   *   The models.
   *
   * @return array
   *   The array.
   */
  protected function modelsToArray(array $models) : \Generator {
    foreach ($models as $model) {
      yield $this->modelToArray($model);
    }
  }

}
