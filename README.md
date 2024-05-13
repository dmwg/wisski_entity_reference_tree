# WissKI entity reference tree

A Drupal module extending [drupal/entity_reference_tree](https://www.drupal.org/project/entity_reference_tree) to provide a hierarchy of entities configured through disambiguation points in the pathbuilder, e.g., tags or other forms of taxonomy.
This module depends on `drupal/entity_reference_tree` and was tested successfully with `2.1.0`.

**Note:** This module depends on the dynamic dispatch to other tree-builders defined in `src/web/modules/contrib/entity_reference_tree/src/Controller/EntityReferenceTreeController.php:104`.
Should this dispatch ever go away, this module **will break** (or at least cease to function, which is the same).

Required dynamic dispatch in `drupal/entity_reference_tree`:
```php
// Instance a entity tree builder for this entity type if it exists.
if (\Drupal::hasService('entity_reference_' . $entity_type . '_tree_builder')) {
  $treeBuilder = \Drupal::service('entity_reference_' . $entity_type . '_tree_builder');
}
```

![](./screenshot.png "A screenshot of the reference tree widget")

## Installation

## Usage

1. Install the module via `/admin/modules`
2. Under `/admin/structure/`, configure the required bundle:
   1. Go to `Manage form display`
   2. For the required field, set the type to "Entity reference tree"
   3. (optional) Configure the theme of the widget and other layout properties

## Contributing

We welcome all and any contributions via Pull Requests to this repository!

### Setup

```shell
$ git clone https://github.com/dmwg/wisski_entity_reference_tree
$ cd wisski_entity_reference
$ composer install
```

### Linting & Static analysis
Please run `vendor/bin/phpcs --standard=Drupal` and fix any flagged errors; `vendor/bin/phpcbf --standard=Drupal src` can assist.
Optionally, please run `vendor/bin/phpstan` and fix any errors.

## Authors and acknowledgment

* `Oliver Baumann <oliver.baumann@uni-bayreuth.de>`
  * Refactoring & Maintenance
* `Myriel Fichtner`
  * Concept & original version
* `Philipp Eisenhut`
  * Concept & original version

## License

`MIT`, but unpublished elsewhere.

## Project status

Alive, but dormant.

## Relevant background information

The initial version of this module relied on the length of sub-arrays within the entity configuration to figure out if something was "parent" or "child".
As noted in the developers' comments from then, this was brittle and ultimately led to breakage.
Thus, this functionality was re-worked entirely, and the valuable comments that helped achieve this are left below, should anyone ever stumble across this again and wonder "Whatwhy?!".

```php
// by MyF:
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

 foreach ($values as $val) {
  foreach ($val as $val_field) {
    foreach ($val_field as $val_sub_field) {
      if (is_array($val_sub_field)) {
        foreach ($val_sub_field as $target_id => $target_id_val) {
          if (count($val_sub_field) > 2 && is_int($target_id_val)) {
            if ($myid != $target_id_val) {
              $parentNodeID = $target_id_val;
            }
          }
        }
      }
    }
  }
}
```
