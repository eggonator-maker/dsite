<?php

namespace Drupal\route_manager\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enforces route access rules stored in route_manager_settings.
 *
 * Routes with is_public = 0 return 403 for non-admin users.
 */
class RouteAccessSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected Connection $database,
    protected AccountProxyInterface $currentUser,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 30],
    ];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    // Admins bypass all restrictions.
    if ($this->currentUser->hasPermission('administer route manager')) {
      return;
    }

    $path = $event->getRequest()->getPathInfo();

    // Look up stored setting for this exact path.
    $record = $this->database->select('route_manager_settings', 'rms')
      ->fields('rms', ['is_public'])
      ->condition('path', $path)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($record === FALSE) {
      // No record — use global default (public).
      $default = $this->configFactory
        ->get('route_manager.settings')
        ->get('default_public');
      // Default is TRUE (public) when not configured.
      if ($default === FALSE || $default === 0) {
        throw new AccessDeniedHttpException('Route is not public.');
      }
      return;
    }

    $is_public = ($record === NULL) ? NULL : (int) $record;

    if ($is_public === 0) {
      throw new AccessDeniedHttpException('This route is not publicly accessible.');
    }
  }

}
