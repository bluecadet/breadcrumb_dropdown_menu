<?php

namespace Drupal\breadcrumb_dropdown_menu;

use Drupal\Component\Utility\SortArray;
use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Link;
use Drupal\Core\Menu\MenuActiveTrailInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;

/**
 * Class BreadcrumbDropdownBuilder.
 *
 * @package Drupal\breadcrumb_dropdown_menu
 */
class BreadcrumbDropdownBuilder implements BreadcrumbBuilderInterface {

  /**
   * Config Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * Menu active trail.
   *
   * @var \Drupal\Core\Menu\MenuActiveTrailInterface
   */
  protected $menuActiveTrail;

  /**
   * The menu tree service.
   *
   * @var \Drupal\Core\Menu\MenuLinkTreeInterface
   */
  protected $menuLinkTree;

  /**
   * The menu link manager.
   *
   * @var \Drupal\Core\Menu\MenuLinkManagerInterface
   */
  protected $linkManager;

  /**
   * Admin context.
   *
   * @var \Drupal\Core\Routing\AdminContext
   */
  protected $adminContext;

  /**
   * Constructs the MenuBasedBreadcrumbBuilder.
   */
  public function __construct(ConfigFactoryInterface $configFactory, MenuActiveTrailInterface $menuActiveTrail, MenuLinkManagerInterface $linkManager, AdminContext $adminContext, MenuLinkTreeInterface $menuLinkTree) {
    $this->configFactory = $configFactory;
    $this->config = $this->configFactory->get('breadcrumb_dropdown_menu.settings');
    $this->menuActiveTrail = $menuActiveTrail;
    $this->menuLinkTree = $menuLinkTree;
    $this->linkManager = $linkManager;
    $this->adminContext = $adminContext;
  }

  /**
   * Whether this breadcrumb builder should be used to build the breadcrumb.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   *
   * @return bool
   *   TRUE if this builder should be used or FALSE to let other builders
   *   decide.
   */
  public function applies(RouteMatchInterface $route_match) {
    return !$this->adminContext->isAdminRoute($route_match->getRouteObject());
  }

  /**
   * Builds the breadcrumb.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   *
   * @return \Drupal\Core\Breadcrumb\Breadcrumb
   *   A breadcrumb.
   */
  public function build(RouteMatchInterface $route_match) {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['url.path']);

    $menus = $this->config->get('breadcrumb_dropdown_menus');
    uasort($menus, function ($a, $b) {
      return SortArray::sortByWeightElement($a, $b);
    });

    $links = [];

    foreach ($menus as $menu_name => $params) {
      $is_node_page = FALSE;
      if (empty($params['enabled'])) {
        continue;
      }

      // Get active trail for current route for given menu.
      // $trailIds = $this->menuActiveTrail->getActiveTrailIds($menu_name);
      // $trailIds = array_filter($trailIds);
      // Active trail for current route for given menu ( soted by depth ASC )
      $trailIds = $this->getActiveTrailIdsSortedByDepthAsc($menu_name);
      $trailIds = array_filter($trailIds);

      // Get active trails for content type.
      if (empty($trailIds)) {
        $route_match = \Drupal::routeMatch();
        if ($route_match->getRouteName() == 'entity.node.canonical') {
          $is_node_page = TRUE;
          $node = $route_match->getParameter('node');
          $node_type = \Drupal::entityManager()->getStorage('node_type')->load($node->getType());
          $parentTrailId = $node_type->getThirdPartySetting('breadcrumb_dropdown_menu', 'breadcrumb_dropdown_menu_parent_item');
          if (!empty($parentTrailId)) {
            $parentTrailId = str_replace($menu_name . ':', '', $parentTrailId);
            $trailIds = $this->linkManager->getParentIds($parentTrailId);
          }
        }
      }

      // Skip if no links found.
      if (empty($trailIds)) {
        continue;
      }

      $parameters = $this->menuLinkTree->getCurrentRouteMenuTreeParameters($menu_name);
      $parameters->onlyEnabledLinks();
      $tree = $this->menuLinkTree->load($menu_name, $parameters);

      // Apply manipulators.
      $manipulators = [
        // Only show links that are accessible for the current user.
        ['callable' => 'menu.default_tree_manipulators:checkNodeAccess'],
        ['callable' => 'menu.default_tree_manipulators:checkAccess'],
        // Use the default sorting of menu links.
        ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
      ];
      $tree = $this->menuLinkTree->transform($tree, $manipulators);

      // Generate link for each menu item.
      foreach (array_reverse($trailIds) as $id) {
        $link = $this->linkManager->getInstance(['id' => $id]);
        $subtree = $this->getSubTree($tree, $id);
        $tree = $subtree;
        $drop_down = [];
        if (!empty($subtree)) {
          foreach ($subtree as $item) {
            $link_subtree = $item->link;
            $text_subtree = $link_subtree->getTitle();
            $url_object_subtree = $link_subtree->getUrlObject();
            $drop_down[] = Link::fromTextAndUrl($text_subtree, $url_object_subtree);
          }
        }

        $text = $link->getTitle();
        $url_object = $link->getUrlObject();
        if ($url_object->isExternal() || ($url_object->isRouted() && $url_object->getRouteName() != "<front>")) {
          $link_object = Link::fromTextAndUrl($text, $url_object);
          $link_object->subtree = $drop_down;
          $links[] = $link_object;
        }
      }
      break;
    }

    // Added home link.
    $label = t('Home');
    $home = Link::createFromRoute($label, '<front>');
    array_unshift($links, $home);

    if ($is_node_page) {
      // Add node title to breadcrumb links.
      if (!empty($node)) {
        $title = $node->getTitle();
        $current = Link::createFromRoute($title, '<none>');
        array_push($links, $current);
      }
    }
    else {
      /** @var \Drupal\Core\Link $current */
      if (!empty($links)) {
        $current = array_pop($links);
        $current->setUrl(new Url('<none>'));
        array_push($links, $current);
      }
    }

    $count_links = count($links);
    /*if ($count_links <= 1) {
    $links = [];
    }*/

    return $breadcrumb->setLinks($links);
  }

  /**
   * Get sub tree.
   */
  public function getSubTree($tree, $menu_id) {
    $subtree = [];
    foreach ($tree as $id => $link) {
      if (strpos($id, $menu_id) !== FALSE) {
        $subtree = $link->subtree;
        break;
      }
    }
    return $subtree;
  }

  /**
   * Get active trail for current route for given menu ( soted by depth ASC )
   */
  public function getActiveTrailIdsSortedByDepthAsc($menu_name) {
    // Parent ids; used both as key and value to ensure uniqueness.
    // We always want all the top-level links with parent == ''.
    $active_trail = ['' => ''];

    // If a link in the given menu indeed matches the route, then use it to
    // complete the active trail.
    if ($active_link = $this->getActiveLink($menu_name)) {
      if ($parents = $this->linkManager->getParentIds($active_link->getPluginId())) {
        $active_trail = $parents + $active_trail;
      }
    }

    return $active_trail;
  }

  /**
   * {@inheritdoc}
   */
  public function getActiveLink($menu_name = NULL) {
    // Note: this is a very simple implementation. If you need more control
    // over the return value, such as matching a prioritized list of menu names,
    // you should substitute your own implementation for the 'menu.active_trail'
    // service in the container.
    // The menu links coming from the storage are already sorted by depth,
    // weight and ID.
    $found = NULL;

    $route_name = \Drupal::routeMatch()->getRouteName();
    // On a default (not custom) 403 page the route name is NULL. On a custom
    // 403 page we will get the route name for that page, so we can consider
    // it a feature that a relevant menu tree may be displayed.
    if ($route_name) {
      $route_parameters = \Drupal::routeMatch()->getRawParameters()->all();

      // Load links matching this route.
      $links = $this->linkManager->loadLinksByRoute($route_name, $route_parameters, $menu_name);
      // Select the first matching link.
      if ($links) {
        $found = reset($links);
      }
    }
    return $found;
  }

}
