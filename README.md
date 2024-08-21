INTRODUCTION
---
The Drush Config Entity Export module provides some helper drush commands to
export specific config entity (bundle) with all connected configuration like
fields, displays etc.

Tho fully rewritten and built upon this module is inspired by (sadly) long gone
drupal console's [config:export:entity](https://drupalconsole.com/docs/vn/commands/config-export-entity) command.

USAGE
---
There are two commands provided, one is for exporting bundleable entities and other one for non bundleable.
### config:export:entity:bundle (ceeb)
```shell
drush ceeb node page --path="../config/partial/feature-312"         # Export all configuration connected with bundle to custom path
drush ceeb taxonomy_term category --module=custom_commerce_extender # Export all configuration connected with bundle to module
drush ceeb                                                          # Start config entity export prompt
```
### config:export:entity:non-bundle (ceenb)
```shell
drush ceenb user --path="../config/partial/feature-312" # Export all configuration connected with entity to custom path
drush ceenb user --module=custom_commerce_extender      # Export all configuration connected with entity to module
drush ceenb                                             # Start config entity export prompt
```

The primary use case for this module is:
- Branching
- Custom module development
- Recipes development

REQUIREMENTS
---

At least drush 12.5 is required.

INSTALLATION
---
Just require package with composer, this module is meant for development
```shell
composer require holo96/drush_config_export_entity --dev
```
IMPORTING
---
This package will not handle import, you can use `drush cim --partial`,
[features](https://www.drupal.org/project/features) and other importing tools.

MAINTAINERS
---

Current maintainers for Drupal 10 and 11:

- Davor Horvacki (Holo96) - https://www.drupal.org/u/Holo96
