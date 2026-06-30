<?php

namespace Drupal\commerce_dinero\Access;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * Access check for the create invoice route.
 */
class CreateInvoiceAccessCheck {

  /**
   * Checks access for creating a Dinero invoice.
   */
  public static function check(Route $route, RouteMatchInterface $route_match, ?AccountInterface $account = NULL) {
    $account = $account ?? \Drupal::currentUser();
    if (!$account->hasPermission('create commerce dinero invoice')) {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    $order = $route_match->getParameter('commerce_order');
    if (!$order instanceof OrderInterface) {
      return AccessResult::forbidden();
    }

    /** @var \Drupal\commerce_dinero\Service\DineroInvoiceManager $manager */
    $manager = \Drupal::service('commerce_dinero.invoice_manager');
    if (!$manager->canCreateInvoice($order)) {
      return AccessResult::forbidden()->addCacheableDependency($order);
    }

    return AccessResult::allowed()->addCacheableDependency($order)->cachePerPermissions();
  }

}
