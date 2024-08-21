<?php

declare(strict_types=1);

namespace Drupal\drush_config_export_entity\Drush\Commands;

use Drupal\Core\Config\CachedStorage;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Utility\Token;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A Drush commandfile.
 */
class ConfigExportEntityDrushCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Constructs a DrushConfigEntityExportCommands object.
   */
  public function __construct(
    private readonly Token $token,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly ConfigFactoryInterface $configFactory,
    #[Autowire('extension.list.module')]
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly FileSystemInterface $fileSystem,
    #[Autowire('config.storage')]
    private readonly CachedStorage $configStorage,
  ) {
    parent::__construct();
  }

  /**
   * Exports configuration connected to chosen entity bundle.
   */
  #[CLI\Command(name: 'config:export:entity:bundle', aliases: ['ceeb'])]
  #[CLI\Argument(name: 'entity_type_id', description: 'Entity type id', suggestedValues: ['node', 'taxonomy_term'])]
  #[CLI\Argument(name: 'bundle', description: 'Bundle id', suggestedValues: ['page'])]
  #[CLI\Option(name: 'module', description: 'Module name to export configuration (cannot be mixed with --path)')]
  #[CLI\Option(name: 'path', description: 'Custom path to export configuration (cannot be mixed with --module)')]
  #[CLI\Option(name: 'unset-uuid', description: 'If set, the configuration will be exported without uuid key')]
  #[CLI\Option(name: 'unset-config-hash', description: 'If set, the configuration will be exported without default config hash key')]
  #[CLI\Usage(name: 'ceeb node page --path="../config/partial/feature-312"', description: 'Export all configuration connected with bundle to custom path')]
  #[CLI\Usage(name: 'ceeb taxonomy_term category --module=custom_commerce_extender --unset-uuid', description: 'Export all configuration connected with bundle to module')]
  #[CLI\Usage(name: 'ceeb', description: 'Start config entity export prompt')]
  public function configEntityExportBundle(
    ?string $entity_type_id = NULL,
    ?string $bundle = NULL,
    array $options = [
      'module' => NULL,
      'path' => NULL,
      'unset-uuid' => FALSE,
      'unset-config-hash' => FALSE,
    ],
  ) {
    if ($entity_type_id = $this->ensureEntityTypeId($entity_type_id, self::class . '::isBundleEntity')) {
      $this->ensureBundleExists($entity_type_id, $bundle);
    }
    $path = $this->checkPathFromOptions($options);

    $entity_type_id ??= $this->promptEntityType(self::class . '::isBundleEntity');
    $bundle ??= $this->promptBundle($entity_type_id);
    $path ??= $this->promptPath();

    $this->doExportEntityConfig($entity_type_id, $bundle, $path, $options);

    $this->logger()->success(dt('Successfully exported configuration of @entity_type @bundle to @path.', [
      '@entity_type' => $entity_type_id,
      '@bundle' => $bundle ? "($bundle)" : '',
      '@path' => $path,
    ]));
  }

  /**
   * Exports configuration connected to chosen entity.
   */
  #[CLI\Command(name: 'config:export:entity:non-bundle', aliases: ['ceenb'])]
  #[CLI\Argument(name: 'entity_type_id', description: 'Entity type id', suggestedValues: ['user'])]
  #[CLI\Option(name: 'module', description: 'Module name to export configuration (cannot be mixed with --path)')]
  #[CLI\Option(name: 'path', description: 'Custom path to export configuration (cannot be mixed with --module)')]
  #[CLI\Option(name: 'unset-uuid', description: 'If set, the configuration will be exported without uuid key')]
  #[CLI\Option(name: 'unset-config-hash', description: 'If set, the configuration will be exported without default config hash key')]
  #[CLI\Usage(name: 'ceenb user --path="../config/partial/feature-312"', description: 'Export all configuration connected with entity to custom path')]
  #[CLI\Usage(name: 'ceenb user --module=custom_commerce_extender --unset-uuid', description: 'Export all configuration connected with entity to module')]
  #[CLI\Usage(name: 'ceenb', description: 'Start config entity export prompt')]
  public function configEntityExportNonBundle(
    ?string $entity_type_id = NULL,
    array $options = [
      'module' => NULL,
      'path' => NULL,
      'unset-uuid' => FALSE,
      'unset-config-hash' => FALSE,
    ],
  ) {
    $entity_type_id = $this->ensureEntityTypeId($entity_type_id, self::class . '::isNotBundleEntity');
    $path = $this->checkPathFromOptions($options);

    $entity_type_id ??= $this->promptEntityType(self::class . '::isNotBundleEntity');
    $path ??= $this->promptPath();

    $this->doExportEntityConfig($entity_type_id, NULL, $path, $options);

    $this->logger()->success(dt('Successfully exported configuration of @entity_type to @path.', [
      '@entity_type' => $entity_type_id,
      '@path' => $path,
    ]));
  }

  /**
   * Checks if path can be constructed from predefined options.
   *
   * @param array $options
   *   Command options.
   *
   * @return string|null
   *   Path if provided by options.
   *
   * @throws \Exception
   */
  public function checkPathFromOptions(array $options): ?string {
    if (isset($options['path']) && isset($options['module'])) {
      throw new \Exception(dt('You have to define either path or module but not both.'));
    }
    if (isset($options['path'])) {
      $this->fileSystem->prepareDirectory($options['path'], FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      return $options['path'];
    }
    if (isset($options['module'])) {
      if ($this->moduleExtensionList->exists($options['module'])) {
        $directory = $this->moduleExtensionList->getPath($options['module']) . '/config/install';
        $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
        return $directory;
      }
      throw new \Exception(dt('Provided module does not exist.'));
    }
    return NULL;
  }

  /**
   * Returns path to export config from either provided options or user prompt.
   *
   * @return string
   *   File system path.
   *
   * @throws \Exception
   */
  protected function promptPath(): string {
    $config_export_type = $this->io()->askQuestion(new ChoiceQuestion(dt('Export configuration to custom path or module'), [
      'module' => dt('Module'),
      'path' => dt('Path'),
    ]));
    $directory = match($config_export_type) {
      'module' => $this->moduleExtensionList->getPath($this->modulePicker()) . '/config/install',
      'path' => $this->io()->askQuestion(new Question('Please provide path to export config'))
    };
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    return $directory;
  }

  /**
   * Returns name of picked module.
   *
   * @return string
   *   Module name.
   */
  protected function modulePicker(): string {
    $module_list = array_map(
      static fn($info) => $info['name'],
      $this->moduleExtensionList->getAllAvailableInfo(),
    );
    $question = new Question('Choose module you want to export config to');
    $question->setAutocompleterValues($module_list);
    return $this->io()->askQuestion($question);
  }

  /**
   * Checks whether entity is bundle entity.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   Entity type.
   *
   * @return bool
   *   Is bundle entity.
   */
  public static function isBundleEntity(EntityTypeInterface $entity_type): bool {
    return $entity_type->hasKey('bundle');
  }

  /**
   * Checks whether entity is not bundle entity.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   Entity type.
   *
   * @return bool
   *   Is bundle entity.
   */
  public static function isNotBundleEntity(EntityTypeInterface $entity_type): bool {
    return !$entity_type->hasKey('bundle') && $entity_type->getGroup() === 'content';
  }

  /**
   * If entity type id is provided and does not exists exception will be thrown.
   *
   * If entity type id is not provided, NULL will be returned.
   *
   * @param string|null $entity_type_id
   *   Entity type to check.
   * @param callable $additional_callback
   *   Custom callback to perform on entity type.
   *
   * @return string|null
   *   If provided same entity type id will be returned.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function ensureEntityTypeId(?string $entity_type_id, callable $additional_callback): ?string {
    if (is_null($entity_type_id)) {
      return NULL;
    }
    if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
      throw new \Exception(dt('The entity type id does not exist.'));
    }
    if (!$additional_callback($this->entityTypeManager->getDefinition($entity_type_id))) {
      throw new \Exception(dt('This entity type id cannot be chosen in this command.'));
    }
    return $entity_type_id;
  }

  /**
   * Ensures bundle exists for entity type id.
   *
   * If bundle is provided and it is part of entity type id, exception will not
   * be thrown.
   *
   * @param string $entity_type_id
   *   Entity type id.
   * @param string|null $bundle
   *   Bundle.
   *
   * @throws \Exception
   */
  private function ensureBundleExists(string $entity_type_id, ?string $bundle): void {
    if (!isset($bundle)) {
      return;
    }
    $bundle_info = $this->entityTypeBundleInfo->getBundleInfo($entity_type_id);
    if (!isset($bundle_info[$bundle])) {
      throw new \Exception(dt('The bundle does not exist on given entity type.'));
    }
  }

  /**
   * Prompts user to choose entity type.
   *
   * @param callable $filter_callback
   *   Callback to filter entity type.
   *
   * @return string
   *   Entity type id.
   */
  private function promptEntityType(callable $filter_callback): string {
    $entity_types = array_filter($this->entityTypeManager->getDefinitions(), $filter_callback);
    $entity_types_options = array_map(static fn($entity_type) => $entity_type->getLabel(), $entity_types);
    return $this->io()->askQuestion(new ChoiceQuestion('Choose entity type', $entity_types_options));
  }

  /**
   * Prompts user to choose entity bundle.
   *
   * @param string $entity_type_id
   *   Entity type id.
   *
   * @return string
   *   Bundle.
   */
  private function promptBundle(string $entity_type_id): string {
    $bundle_options = array_map(static fn($bundle_info) => $bundle_info['label'], $this->entityTypeBundleInfo->getBundleInfo($entity_type_id));
    return $this->io()->askQuestion(new ChoiceQuestion('Choose bundle', $bundle_options));
  }

  /**
   * Exports configuration connected to given entity bundle.
   *
   * @param string $entity_type_id
   *   Entity type id.
   * @param string|null $bundle
   *   Entity bundle.
   * @param string $path
   *   Path to export.
   * @param array $options
   *   Options.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function doExportEntityConfig(string $entity_type_id, ?string $bundle, string $path, array $options): void {
    $destination_storage = new FileStorage($path);
    // @todo Add ability to developer to add additional config.
    foreach (['entity_form_display', 'entity_view_display', 'field_config', 'base_field_override'] as $config_entity_type_id) {
      if ($this->entityTypeManager->hasDefinition($config_entity_type_id)) {
        $config_entity_storage = $this->entityTypeManager->getStorage($config_entity_type_id);
        foreach ($config_entity_storage->loadMultiple() as $config_entity) {
          if (is_null($bundle) || $config_entity->get('bundle') === $bundle) {
            $entity_type = $config_entity->get('entity_type') ?? $config_entity->get('targetEntityType');
            if ($entity_type === $entity_type_id) {
              $this->doExportConfig($destination_storage, $config_entity->getConfigDependencyName(), $options);
            }
          }
        }
      }
    }
  }

  /**
   * Exports configuration with its dependencies.
   *
   * @param \Drupal\Core\Config\StorageInterface $destination_storage
   *   Destination storage.
   * @param string $config_name
   *   Configuration name.
   * @param array $options
   *   Options.
   */
  protected function doExportConfig(StorageInterface $destination_storage, string $config_name, array $options): void {
    $config_data = $this->configStorage->read($config_name);
    foreach ($config_data['dependencies']['config'] ?? [] as $config_dependency) {
      $this->doExportConfig($destination_storage, $config_dependency, $options);
    }
    if ($options['unset-uuid']) {
      unset($config_data['uuid']);
    }
    if ($options['unset-config-hash']) {
      unset($config_data['_core']['default_config_hash']);
      if (isset($config_data['_core']) && $config_data['_core'] === []) {
        unset($config_data['_core']);
      }
    }
    $destination_storage->write($config_name, $config_data);
  }

}
