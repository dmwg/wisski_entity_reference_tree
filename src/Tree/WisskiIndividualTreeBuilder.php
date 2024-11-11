<?php

declare(strict_types=1);

namespace Drupal\wisski_entity_reference_tree\Tree;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\entity_reference_tree\Tree\TreeBuilderInterface;
use Drupal\wisski_core\Entity\WisskiBundle;
use Drupal\wisski_core\Entity\WisskiEntity;
use Psr\Log\LoggerInterface;

/**
 * Provides a class for building a tree from WissKI entities.
 *
 * This builder is capable of resolving parent-child-relationships formed by
 * disambiguation points in the pathbuilder. It does so by filtering for the
 * current bundle's custom fields first, and then iterating these to figure out
 * if they constitute a hierarchy via the `target_id` property.
 *
 * This currently works, although
 *
 * @ingroup entity_reference_tree_api
 *
 * @see \Drupal\entity_reference_tree\Tree\TreeBuilderInterface
 */
class WisskiIndividualTreeBuilder implements TreeBuilderInterface {

  private const CACHE_PREFIX = "wisski_individual_tree_";

  private const CACHE_EXPIRE = 30 * 24 * 60 * 60;

  public function __construct(
    private LoggerInterface $logger,
    private AccountProxyInterface $currentUser,
    private EntityTypeManagerInterface $entityTypeManager,
    private EntityFieldManagerInterface $entityFieldManager,
    private LanguageManagerInterface $languageManager,
    private CacheBackendInterface $cacheBackend,
    private TimeInterface $time,
  ) {
  }

  /**
   * The permission name to access the entity tree.
   *
   * @var string
   *   The entity storage load function is actually responsible for
   *   the permission checking for each individual entity.
   *   So here just use a very weak permission.
   */
  private string $accessPermission = 'access content';

  /**
   * The current language.
   *
   * @var ?string
   *   Depending on the user's settings, a non-default language code may be set.
   *   This is used in the construction of the cache-key by which to retrieve
   *   the currently built tree.
   */
  private ?string $langCode = NULL;

  /**
   * Load all entities from an entity bundle for the tree.
   *
   * @param string $entityType
   *   The type of the entity.
   * @param string $bundleID
   *   The bundle ID.
   * @param string|null $langCode
   *   The language code.
   *   This shouldn't be part of the TreeBuilderInterface, only used in
   *    \Drupal\entity_reference_tree\Tree\TaxonomyTreeBuilder.
   * @param int $parent
   *   The parent term.
   *   This shouldn't be part of the TreeBuilderInterface, only used in
   *   \Drupal\entity_reference_tree\Tree\TaxonomyTreeBuilder.
   * @param int|null $max_depth
   *   The maximum depth.
   *   This shouldn't be part of the TreeBuilderInterface, only used in
   *   \Drupal\entity_reference_tree\Tree\TaxonomyTreeBuilder.
   *
   * @return array<object>
   *   All entities in the entity bundle.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function loadTree(
    string $entityType,
    string $bundleID,
    ?string $langCode = NULL,
    int $parent = 0,
    ?int $max_depth = NULL,
  ): array {
    if ($bundleID === '*') {
      $this->logger->warning(__CLASS__ . ": Can't build tree for bundle of wildcard '*', returning empty array.");
      return [];
    }

    $cacheId = self::CACHE_PREFIX . $bundleID;
    $cacheTags = [];

    // Caching needs to be language-aware, otherwise the tag-tree will
    // always display in the same language, regardless of user-language.
    if (empty($langCode)) {
      $this->langCode = $this->languageManager->getCurrentLanguage()->getId();
    }
    else {
      $this->langCode = $langCode;
    }

    if ($this->langCode !== NULL) {
      $cacheId .= sprintf("_%s", $this->langCode);
    }

    $bundle = WisskiBundle::load($bundleID);
    $bundleName = $bundle->label();

    $cacheTags = [...$cacheTags, ...$bundle->getCacheTags()];

    $cachedResult = $this->cacheBackend->get($cacheId);
    if ($cachedResult) {
      $this->logger->info(
            "Found cached result for {bundle} of length {len}, expires on {expires}",
            [
              'bundle' => $bundleName,
              'len' => count($cachedResult->data),
              'expires' => date('d M Y H:i:s', (int) $cachedResult->expire),
            ]
        );
      return $cachedResult->data;
    }

    $this->logger->info("No cache entry found for {cacheId}", ['cacheId' => $cacheId]);
    if ($this->hasAccess($this->currentUser)) {
      $entityStorage = $this->entityTypeManager->getStorage($entityType);

      /**
      * @var \Drupal\Core\Field\FieldDefinition[] $fields
      */
      $fields = $this->entityFieldManager->getFieldDefinitions('wisski_individual', (string) $bundle->id());

      // The field name is an md5-hash of sorts; see
      // \Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity::generateIdForField
      // We store all non-base field names for this bundle, so that we can
      // use them to index into the values-array later.
      $customFields = [];
      foreach ($fields as $field) {
        // Skip if the current field is a base-field (`lang` etc.).
        if ($field->getFieldStorageDefinition()->isBaseField()) {
          continue;
        }
        $customFields[] = $field->getName();
      }

      // Query for entity properties by bundle.
      $properties = [
        $entityStorage->getEntityType()->getKey('bundle') => $bundleID,
      ];

      // Load all entities matching the conditions.
      $entities = $entityStorage->loadByProperties($properties);

      // The bundle itself is always the root node, without a parent.
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

      foreach ($entities as $entity) {
        assert($entity instanceof WisskiEntity);
        $cacheTags = [...$cacheTags, ...$entity->getCacheTags()];

        if ($entity->access('view')) {
          $trans_entity = $entity->hasTranslation($this->langCode) ? $entity->getTranslation($this->langCode) : $entity;
          $parentNodeID = 0;
          $values = $entity->getValues($entityStorage);
          $values = $values[0];

          if (empty(array_intersect($customFields, array_keys($values)))) {
            continue;
          }

          foreach ($customFields as $fieldName) {
            // Check key existance to avoid warnings.
            if (!array_key_exists($fieldName, $values)) {
              continue;
            }
            if (!array_key_exists('main_property', $values[$fieldName])) {
              continue;
            }

            if ($values[$fieldName]['main_property'] === 'target_id') {
              $parentNodeID = (int) $values[$fieldName][0]['target_id'];
              break;
            }
          }

          if ($parentNodeID != 0) {
            // Add entities to the tree that are connected to a parent.
            $tree[] = (object) [
              'id' => $entity->id(),
              // Required.
              'parent' => $parentNodeID,
              // Node text.
              'text' => $trans_entity->label(),
            ];
          }
          else {
            // Any other entities should reference the bundle itself as parent.
            $tree[] = (object) [
              'id' => $entity->id(),
              // Required.
              'parent' => $entity->bundle(),
              // Node text.
              'text' => $trans_entity->label(),
            ];
          }
        }
      }

      $expires = $this->time->getRequestTime() + self::CACHE_EXPIRE;
      $this->logger->info(
            "Caching tree for {bundle} ({bundleId}), expires on {expires}", [
              'bundle' => $bundleName,
              'bundleId' => $bundleID,
              'expires' => date('d M Y H:i:s', $expires),
            ]
        );
      $this->cacheBackend->set($cacheId, $tree, $expires, $cacheTags);

      return $tree;
    }

    $this->logger->warning(
          'User {user} does not have permission "{permission}" to access the WissKI bundle {bundle} ({bundleId})', [
            'user' => $this->currentUser->getAccountName(),
            'permission' => $this->accessPermission,
            'bundle' => $bundleName,
            'bundleId' => $bundleID,
          ]
      );

    return [];
  }

  /**
   * Create a tree node.
   *
   * @param object $entity
   *   The entity for the tree node.
   * @param array<string> $selected
   *   An array for all selected nodes.
   *
   * @return array{id: string, parent: string, text: string, state: array{selected: bool}}
   *   The tree node for the entity.
   */
  public function createTreeNode($entity, array $selected = []): array {
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
   * @param object $entity
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
  private function hasAccess(AccountProxyInterface $user): bool {
    return $user->hasPermission($this->accessPermission);
  }

}
