<?php

namespace Drupal\breadcrumb_dropdown_menu\Form;

use Drupal\Component\Utility\SortArray;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class SettingsForm.
 *
 * @package Drupal\menu_breadcrumb\Form
 */
class SettingsForm extends ConfigFormBase {

  /**
   * Module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * SettingsForm constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   Module Handler.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ModuleHandlerInterface $moduleHandler) {
    parent::__construct($config_factory);
    $this->moduleHandler = $moduleHandler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['breadcrumb_dropdown_menu.settings'];
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return array
   *   Form array.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('breadcrumb_dropdown_menu.settings');

    $form['include_exclude'] = [
      '#type' => 'fieldset',
      '#title' => t('Enable / Disable Menus'),
      '#description' => t('The breadcrumb will be generated from the first "enabled" menu that contains a menu item for the page. Re-order the list to change the priority of each menu.'),
    ];

    $form['include_exclude']['note_about_navigation'] = [
      '#markup' => '<p class="description">' . t("Note: If none of the enabled menus contain an item for a given page, Drupal will look in the 'Navigation' menu by default, even if it is 'disabled' here.") . '</p>',
    ];

    // Orderable list of menu selections.
    $form['include_exclude']['breadcrumb_dropdown_menus'] = [
      '#type' => 'table',
      '#header' => [t('Menu'), t('Enabled'), t('Weight')],
      '#empty' => t('There is no menus yet.'),
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'menus-order-weight',
        ],
      ],
    ];

    foreach ($this->getSortedMenus() as $menu_name => $menu_config) {

      $form['include_exclude']['breadcrumb_dropdown_menus'][$menu_name] = [
        '#attributes' => [
          'class' => ['draggable'],
        ],
        '#weight' => $menu_config['weight'],
        'title' => [
          '#plain_text' => $menu_config['label'],
        ],
        'enabled' => [
          '#type' => 'checkbox',
          '#default_value' => $menu_config['enabled'],
        ],
        'weight' => [
          '#type' => 'weight',
          '#default_value' => $menu_config['weight'],
          '#attributes' => ['class' => ['menus-order-weight']],
        ],
      ];
    }

    $form['include_exclude']['description'] = [
      '#prefix' => '<p class="description">',
      '#suffix' => '</p>',
      '#markup' => t('<strong>Default setting</strong> is not a real menu - it defines the default position and enabled status for future menus. If it is "enabled", Menu Breadcrumb will automatically consider newly-added menus when establishing breadcrumbs. If it is disabled, new menus will not be used for breadcrumbs until they have explicitly been enabled here.'),
    ];

    $form['title_max_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Breadcrumb title Max length'),
      '#description' => $this->t('If left blank, no max length will be set. Otherwise, choose the approximate character max length.'),
      '#default_value' => $config->get('title_max_length'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('breadcrumb_dropdown_menu.settings')
      ->set('breadcrumb_dropdown_menus', $form_state->getValue('breadcrumb_dropdown_menus'))
      ->set('title_max_length', $form_state->getValue('title_max_length'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'breadcrumb_dropdown_menu_settings';
  }

  /**
   * Returns array of menus with properties (enabled, weight, label).
   *
   * Sorted by weight.
   */
  protected function getSortedMenus() {
    $menu_enabled = $this->moduleHandler->moduleExists('menu_ui');
    $menus = $menu_enabled ? menu_ui_get_menus() : menu_list_system_menus();
    $breadcrumb_dropdown_menus = $this->config('breadcrumb_dropdown_menu.settings')
      ->get('breadcrumb_dropdown_menus');

    foreach ($menus as $menu_name => &$menu) {
      if (!empty($breadcrumb_dropdown_menus[$menu_name])) {
        $menu = $breadcrumb_dropdown_menus[$menu_name] + ['label' => $menu];
      }
      else {
        $menu = ['weight' => 0, 'enabled' => 0, 'label' => $menu];
      }
    }

    uasort($menus, function ($a, $b) {
      return SortArray::sortByWeightElement($a, $b);
    });

    return $menus;
  }

}
