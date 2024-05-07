<?php

declare(strict_types=1);

namespace Dmkg\WisskiEntityReferenceTree\Tree;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\entity_reference_tree\Tree\TreeBuilderInterface;
use Drupal\wisski_core\Entity\WisskiBundle;

/**
 * Provides a class for building a tree from general entity.
 *
 * @ingroup entity_reference_tree_api
 *
 * @see \Drupal\entity_reference_tree\Tree\TreeBuilderInterface
 */
class WisskiIndividualTreeBuilder implements TreeBuilderInterface {

  /**
   *
   * @var string
   *   The permission name to access the entity tree.
   *   The entity storage load function is actually responsible for
   *   the permission checking for each individual entity.
   *   So here just use a very weak permission.
   */
  private $accessPermission = 'access content';

  /**
   * The Language code.
   *
   * @var string
   */
  protected $langCode;

  /**
   * Load all entities from an entity bundle for the tree.
   *
   * @param string $entityType
   *   The type of the entity.
   *
   * @param string $bundleID
   *   The bundle ID.
   *
   * @return array
   *   All entities in the entity bundle.
   */
  public function loadTree(string $entityType, string $bundleID, string $langCode = NULL, int $parent = 0, int $max_depth = NULL) {

    // dpm($langCode, __METHOD__);.
    if ($this->hasAccess()) {
      if ($bundleID === '*') {
        // Load all entities regardless bundles.
        $entities = \Drupal::entityTypeManager()->getStorage($entityType)->loadMultiple();
        $hasBundle = FALSE;
      }
      else {
        $hasBundle = TRUE;
        $entityStorage = \Drupal::entityTypeManager()->getStorage($entityType);
        // Build the tree node for the bundle.
        $bundle = WisskiBundle::load($bundleID);
        $bundleName = $bundle->label();

        $entityFieldManager = \Drupal::service('entity_field.manager');
        /** @var \Drupal\Core\Field\FieldDefinition[] $fields */
        $fields = $entityFieldManager->getFieldDefinitions('wisski_individual', $bundle->id());

        // The field name is an md5-hash of sorts; see \Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity::generateIdForField
        // We store all non-base field names for this bundle, so that we can use them to index into the values-array later.
        $customFields = [];
        foreach ($fields as $field) {
          if ($field->getFieldStorageDefinition()->isBaseField()) {
            continue;
          }
          $customFields[] = $field->getName();
        }

        $tree = [
          (object) [
            'id' => $bundleID,
            // Required.
            'parent' => '#',
            // Node text.
            'text' => $bundleName,
            'isBundle' => TRUE,
          ],
        ];
        // Entity query properties.
        $properties = [
          // Bundle key field.
          $entityStorage->getEntityType()->getKey('bundle') => $bundleID,
        ];

        // Load all entities matched the conditions.
        $entities = $entityStorage->loadByProperties($properties);

      }

      // By MyF:
      // the variable $entities contains all entity ids that belong to the bundle where the entity reference tree widget points to (= the disambiguation point
      // in the pathbuilder where "Type of form display for field = Entity reference tree widget")
      // ******************************************************************************************************************************************************
      // Difficult part:
      // in order to build the hierarchy of the tree, we need the target id of the parent entity (e.g. South Africa is a country in Africa, and Johannesburg
      // is a city in South Africa:)
      // Africa
      // |__South Africa
      //    |__Johannesburg
      // at this point: all entities Africa, South Africa and Johannesburg are stored flat in $entities, the problem is that the hierarchy information is hard
      // to get the values array of an entity contains all field ids that are important for an entity (the own field id and the parent field id)
      // the problem is that this array contains the field id only as long string of type f6380192737832... and we do not know how it can be determined
      // programmatically that string of that type are fields
      // however, the parent field id array contains a array of size 3, while all others are only of size 2 or smaller
      // we use this fact by looking at every entry in the values array and checking if it contains an array of size 3. If yes we probably found the parent
      // field and extract the eid which is necessary for the tree.
      // ******************************************************************************************************************************************************
      // PROBLEM/TODO: checking for size >2 seems to be a little bit hardcoded and we are not sure if this works in all cases!
      // A more straight forward approach would be to somehow get all fields (entries in values having a field id) and read out those that point to any
      // other field/eid.
      $language = \Drupal::service('language_manager')
        ->getCurrentLanguage()
        ->getId();

      foreach ($entities as $entity) {
        if ($entity->access('view')) {
          $trans_entity = $entity->hasTranslation($language) ? $entity->getTranslation($language) : $entity;
          $parentNodeID = 0;
          $values = $entity->getValues(\Drupal::entityTypeManager()
            ->getStorage($entityType));
          $values = $values[0];
          $myid = $values['eid'][0]['value'];

          if (empty(array_intersect($customFields, array_keys($values)))) {
            continue;
          }

          foreach ($customFields as $fieldName) {
            if ($values[$fieldName]['main_property'] === 'target_id') {
              $parentNodeID = (int) $values[$fieldName][0]['target_id'];
              break;
            }
          }

//          foreach ($values as $val) {
//            foreach ($val as $val_field) {
//              foreach ($val_field as $val_sub_field) {
//                if (is_array($val_sub_field)) {
//                  foreach ($val_sub_field as $target_id => $target_id_val) {
//                    if (count($val_sub_field) > 2 && is_int($target_id_val)) {
//                      if ($myid != $target_id_val) {
//                        $parentNodeID = $target_id_val;
//                      }
//                    }
//                  }
//                }
//              }
//            }
//          }

          // Here the tree elements get created, which are not directly connected to the root.
          if ($parentNodeID != 0) {
            $tree[] = (object) [
              'id' => $entity->id(),
              // Required.
              'parent' => $parentNodeID,
              // Node text.
              'text' => $trans_entity->label(),
            ];
          }

          // Here the elements of the first level get created - the ones which are directly connected with the root element.
          else {
            $tree[] = (object) [
              'id' => $entity->id(),
              // Required.
              'parent' => $hasBundle ? $entity->bundle() : '#',
              // Node text.
              'text' => $trans_entity->label(),
            ];
          }
        }
      }

      return $tree;
    }
    // The user is not allowed to access taxonomy overviews.
    return NULL;
  }

  /**
   * Create a tree node.
   *
   * @param $entity
   *   The entity for the tree node.
   *
   * @param array $selected
   *   A anrray for all selected nodes.
   *
   * @return array
   *   The tree node for the entity.
   */
  public function createTreeNode($entity, array $selected = []) {

    $node = [
      // Required.
      'id' => $entity->id,
      // Required.
      'parent' => $entity->parent,
      // Node text.
      'text' => $entity->text,
      'state' => ['selected' => FALSE],
    ];
    if (in_array($entity->id, $selected)) {
      // Initially selected node.
      $node['state']['selected'] = TRUE;
    }

    $is_bundle = $entity->isBundle ?? FALSE;
    if ($is_bundle) {
      $node['data'] = [
        'isBundle' => TRUE,
      ];
    }

    return $node;
  }

  /**
   * Get the ID of a tree node.
   *
   * @param $entity
   *   The entity for the tree node.
   *
   * @return string|int|null
   *   The id of the tree node for the entity.
   */
  public function getNodeId($entity) {
    return $entity->id;
  }

  /**
   * Check if a user has the access to the tree.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $user
   *   The user object to check.
   *
   * @return bool
   *   If the user has the access to the tree return TRUE,
   *   otherwise return FALSE.
   */
  private function hasAccess(AccountProxyInterface $user = NULL) {
    // Check current user as default.
    if (empty($user)) {
      $user = \Drupal::currentUser();
    }

    return $user->hasPermission($this->accessPermission);
  }

}
