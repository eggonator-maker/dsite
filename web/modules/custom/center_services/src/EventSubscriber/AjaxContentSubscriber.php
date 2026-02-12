<?php

namespace Drupal\center_services\EventSubscriber;

use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Render\RenderableInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Render\RenderContext;

class AjaxContentSubscriber implements EventSubscriberInterface {

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  public function __construct(RendererInterface $renderer) {
    $this->renderer = $renderer;
  }

  public static function getSubscribedEvents() {
    $events[KernelEvents::VIEW][] = ['onView', 10];
    return $events;
  }
  public function onView(ViewEvent $event) {
    $request = $event->getRequest();
    if ($request->query->get('ajax_content') !== '1') {
      return;
    }

    $controller_result = $event->getControllerResult();

    if (!is_array($controller_result) && !$controller_result instanceof RenderableInterface) {
      return;
    }

    try {
      $response_data = [];
      $context = new RenderContext();

      // Execute ALL rendering inside the render context.
      $response_data = $this->renderer->executeInRenderContext($context, function() use ($controller_result, $request) {
          // Render the main content.
          $main_content_display = $this->renderer->render($controller_result);

          // Get the title object.
          $title_object = \Drupal::service('title_resolver')->getTitle($request, $request->attributes->get('_route_object'));
          
          $final_title = '';
          if (is_array($title_object)) {
              // Render the title array safely inside the context.
              $final_title = (string) $this->renderer->render($title_object);
          } 
          elseif (!is_null($title_object)) {
              $final_title = (string) $title_object;
          }

          // Return the complete data structure from the closure.
          return [
            'status' => TRUE,
            'content' => (string) $main_content_display,
            'title' => $final_title,
          ];
      });
    }
    catch (\Exception $e) {
      \Drupal::logger('center_services')->error($e->getMessage());
      $response_data = [
        'status' => FALSE,
        'message' => 'An error occurred while rendering the page content.',
      ];
    }

    $response = new JsonResponse($response_data);
    $event->setResponse($response);
}}