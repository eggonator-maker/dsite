<?php

namespace Drupal\route_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\Role;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route as SymfonyRoute;

/**
 * Route Manager admin listing and CSV export controller.
 */
class RouteManagerController extends ControllerBase {

  public function __construct(
    protected Connection $database,
    protected RouteProviderInterface $routeProvider,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('router.route_provider'),
    );
  }

  // ---------------------------------------------------------------------------
  // Listing
  // ---------------------------------------------------------------------------

  public function listing(Request $request): array {
    $search = $request->query->get('search', '');
    $filter = $request->query->get('filter', 'all');

    // Handle inline toggle before rendering.
    if ($request->query->has('toggle_path')) {
      $this->handleToggle(
        $request->query->get('toggle_path'),
        (int) $request->query->get('toggle_to', 1),
      );
      return $this->redirect('route_manager.admin_listing', [], [
        'query' => array_filter(['search' => $search, 'filter' => $filter]),
      ])->send() ?: [];
    }

    $settings = $this->loadAllSettings();
    $audits   = $this->loadLatestAudits();
    $rows     = $this->buildRouteList($settings, $audits, $search, $filter);
    $groups   = $this->groupRoutes($rows);

    $global_public = $this->config('route_manager.settings')->get('default_public') !== FALSE;

    $build = [];

    $build['toolbar'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['route-manager-toolbar']],
      'filter' => $this->buildFilterForm($search, $filter),
      'export' => [
        '#type' => 'link',
        '#title' => $this->t('Export CSV'),
        '#url' => Url::fromRoute('route_manager.admin_export'),
        '#attributes' => ['class' => ['button', 'button--small']],
      ],
      'import' => [
        '#type' => 'link',
        '#title' => $this->t('Import'),
        '#url' => Url::fromRoute('route_manager.admin_import'),
        '#attributes' => ['class' => ['button', 'button--small']],
      ],
    ];

    $build['groups'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['route-manager-groups']],
    ];

    $table_header = [
      $this->t('Path'),
      $this->t('Access'),
      $this->t('Status'),
      $this->t('Load time'),
      $this->t('JS errors'),
      $this->t('Resp. issues'),
      $this->t('Actions'),
    ];

    foreach ($groups as $group_name => $subgroups) {
      $total = array_sum(array_map('count', $subgroups));
      $has_subgroups = count($subgroups) > 1 || !isset($subgroups['']);

      $table_rows = [];

      foreach ($subgroups as $sub_name => $sub_rows) {
        // Sub-group header row (only when the group has multiple sub-sections).
        if ($has_subgroups && $sub_name !== '') {
          $table_rows[] = [
            'data' => [
              [
                'data'    => ['#markup' => '<strong class="route-manager-subgroup-label">/' . htmlspecialchars($group_name . '/' . $sub_name) . '</strong>'],
                'colspan' => 7,
              ],
            ],
            'class' => ['route-manager-subgroup-header'],
          ];
        }

        foreach ($sub_rows as $row) {
          $depth  = substr_count($row['path'], '/') - 1;
          $indent = $has_subgroups && $sub_name !== '' ? str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', max(0, $depth - 1)) : '';

          [$access_label, $access_class] = $this->effectiveAccessLabel(
            $row['is_public'],
            $row['native_admin_only'],
          );

          $edit_url = $row['id']
            ? Url::fromRoute('route_manager.admin_edit', ['id' => $row['id']])
            : Url::fromRoute('route_manager.admin_edit', ['id' => 0], ['query' => ['path' => $row['path']]]);

          // Toggle away from the effective public/hidden state.
          $effectively_public = $row['is_public'] === 1
            || ($row['is_public'] === NULL && !$row['native_admin_only'] && $global_public);
          $toggle_to    = $effectively_public ? 0 : 1;
          $toggle_url   = Url::fromRoute('route_manager.admin_listing', [], [
            'query' => array_filter([
              'toggle_path' => $row['path'],
              'toggle_to'   => $toggle_to,
              'search'      => $search,
              'filter'      => $filter,
            ]),
          ]);
          $toggle_label = $toggle_to === 0 ? $this->t('Hide') : $this->t('Make public');

          $status_code  = $row['status_code'];
          $status_class = match(TRUE) {
            $status_code !== NULL && $status_code < 300  => 'rm-status-ok',
            $status_code !== NULL && $status_code >= 400 => 'rm-status-error',
            default                                       => 'rm-status-unknown',
          };
          $status_display = $status_code ?? '—';

          $error_count      = $row['error_count'] ?? 0;
          $responsive_count = $row['responsive_issue_count'] ?? 0;

          // Path cell with SEO presence indicators (merged from all sources).
          $path_cell = $indent . '<code>' . htmlspecialchars($row['path']) . '</code>';

          // Source 1: our own stored overrides (route_manager_settings).
          $rm_meta = [];
          if (!empty($row['metatag_overrides'])) {
            $rm_meta = unserialize($row['metatag_overrides'], ['allowed_classes' => FALSE]);
            if (!is_array($rm_meta)) {
              $rm_meta = [];
            }
          }

          // Source 2: crawl results imported from the Python auditor.
          $audit_seo = is_string($row['seo_data'])
            ? json_decode($row['seo_data'], TRUE)
            : NULL;

          // Source 3: entity-level flags derived from metatag defaults.
          $entity_seo = $row['entity_seo'];

          if ($rm_meta || is_array($audit_seo) || $entity_seo !== NULL) {
            $indicators = [
              'T'  => !empty($rm_meta['title'])
                   || (is_array($audit_seo) && !empty($audit_seo['title']))
                   || ($entity_seo['title'] ?? FALSE),
              'D'  => !empty($rm_meta['description'])
                   || (is_array($audit_seo) && !empty($audit_seo['meta_description']))
                   || ($entity_seo['description'] ?? FALSE),
              'H1' => is_array($audit_seo) && !empty($audit_seo['h1']),
              'OG' => (!empty($rm_meta['og:title']) || !empty($rm_meta['og:image']))
                   || (is_array($audit_seo) && !empty($audit_seo['og']))
                   || ($entity_seo['og'] ?? FALSE),
            ];
            $seo_html = '';
            foreach ($indicators as $tag => $present) {
              $cls = $present ? 'rm-seo-tag rm-seo-tag--present' : 'rm-seo-tag rm-seo-tag--missing';
              $seo_html .= '<span class="' . $cls . '">' . $tag . '</span>';
            }
            $path_cell .= '<div class="rm-seo-tags">' . $seo_html . '</div>';
          }

          $table_rows[] = [
            'data' => [
              [
                'data' => ['#markup' => $path_cell],
              ],
              [
                'data' => ['#markup' => '<span class="rm-access rm-access--' . $access_class . '">' . $access_label . '</span>'],
              ],
              [
                'data' => ['#markup' => '<span class="rm-status ' . $status_class . '">' . $status_display . '</span>'],
              ],
              [
                'data'  => $row['load_time_ms'] !== NULL ? $row['load_time_ms'] . ' ms' : '—',
                'class' => ($row['load_time_ms'] !== NULL && $row['load_time_ms'] > 2000) ? ['route-manager--slow'] : [],
              ],
              [
                'data' => $error_count > 0
                  ? ['#markup' => '<span class="route-manager--errors">' . $error_count . '</span>']
                  : '0',
              ],
              [
                'data' => $responsive_count > 0
                  ? ['#markup' => '<span class="route-manager--issues">' . $responsive_count . '</span>']
                  : '0',
              ],
              [
                'data' => [
                  '#type'  => 'operations',
                  '#links' => [
                    'view' => [
                      'title'      => $this->t('View'),
                      'url'        => Url::fromUri('base:' . ltrim($row['path'], '/')),
                      'attributes' => ['target' => '_blank', 'rel' => 'noopener'],
                    ],
                    'edit' => [
                      'title'      => $this->t('Edit'),
                      'url'        => $edit_url,
                      'attributes' => [
                        'class'               => ['use-ajax'],
                        'data-dialog-type'    => 'modal',
                        'data-dialog-options' => json_encode(['width' => 720]),
                      ],
                    ],
                    'toggle' => ['title' => $toggle_label, 'url' => $toggle_url],
                  ],
                ],
              ],
            ],
          ];
        }
      }

      $build['groups'][$group_name] = [
        '#type'       => 'details',
        '#title'      => $this->t('@group <small>(@count)</small>', [
          '@group' => '/' . $group_name,
          '@count' => $total,
        ]),
        '#open'       => FALSE,
        '#attributes' => ['class' => ['route-manager-group']],
        'table'       => [
          '#type'       => 'table',
          '#header'     => $table_header,
          '#rows'       => $table_rows,
          '#empty'      => $this->t('No routes.'),
          '#attributes' => ['class' => ['route-manager-table']],
        ],
      ];
    }

    if (empty($groups)) {
      $build['groups']['empty'] = [
        '#markup' => '<p>' . $this->t('No routes found matching your filter.') . '</p>',
      ];
    }

    $build['#attached']['library'][] = 'route_manager/admin';
    $build['#attached']['library'][] = 'core/drupal.dialog.ajax';

    return $build;
  }

  // ---------------------------------------------------------------------------
  // CSV Export
  // ---------------------------------------------------------------------------

  public function exportCsv(): Response {
    $settings = $this->loadAllSettings();
    $audits   = $this->loadLatestAudits();
    $rows     = $this->buildRouteList($settings, $audits, '', 'all');

    $columns = [
      'route_name', 'path', 'is_public', 'page_title',
      'meta_title', 'meta_description', 'og_title', 'og_description',
      'og_image', 'canonical', 'robots',
    ];

    $lines = [implode(',', $columns)];
    foreach ($rows as $row) {
      $meta = $row['metatag_overrides']
        ? unserialize($row['metatag_overrides'], ['allowed_classes' => FALSE])
        : [];
      $lines[] = implode(',', [
        $this->csvCell($row['route_name'] ?? ''),
        $this->csvCell($row['path']),
        $row['is_public'] !== NULL ? (string) $row['is_public'] : '',
        $this->csvCell($row['page_title_override'] ?? ''),
        $this->csvCell($meta['title'] ?? ''),
        $this->csvCell($meta['description'] ?? ''),
        $this->csvCell($meta['og:title'] ?? ''),
        $this->csvCell($meta['og:description'] ?? ''),
        $this->csvCell($meta['og:image'] ?? ''),
        $this->csvCell($meta['canonical'] ?? ''),
        $this->csvCell($meta['robots'] ?? ''),
      ]);
    }

    return new Response(implode("\n", $lines), 200, [
      'Content-Type'        => 'text/csv; charset=UTF-8',
      'Content-Disposition' => 'attachment; filename="route_manager_export.csv"',
    ]);
  }

  // ---------------------------------------------------------------------------
  // Internal helpers
  // ---------------------------------------------------------------------------

  protected function loadAllSettings(): array {
    return $this->database->select('route_manager_settings', 'rms')
      ->fields('rms')
      ->execute()
      ->fetchAllAssoc('path', \PDO::FETCH_ASSOC) ?: [];
  }

  protected function loadLatestAudits(): array {
    $sub = $this->database->select('route_manager_audit', 'a');
    $sub->fields('a', ['path']);
    $sub->addExpression('MAX(audit_date)', 'max_date');
    $sub->groupBy('path');

    $query = $this->database->select('route_manager_audit', 'a2');
    $query->join($sub, 'latest', 'a2.path = latest.path AND a2.audit_date = latest.max_date');
    $query->fields('a2');
    return $query->execute()->fetchAllAssoc('path', \PDO::FETCH_ASSOC) ?: [];
  }

  protected function buildRouteList(
    array $settings,
    array $audits,
    string $search,
    string $filter,
  ): array {
    $paths = array_keys($settings);

    // Collect alias paths AND build alias→system path map for SEO detection.
    $alias_to_system = [];
    if ($this->database->schema()->tableExists('path_alias')) {
      $alias_rows = $this->database->select('path_alias', 'pa')
        ->fields('pa', ['alias', 'path'])
        ->condition('status', 1)
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);
      foreach ($alias_rows as $ar) {
        $alias_to_system[$ar['alias']] = $ar['path'];
        $paths[] = $ar['alias'];
      }
      $paths = array_unique($paths);
    }

    // Pre-load anonymous permissions for native access detection.
    $anon_perms = [];
    $anon_role  = Role::load('anonymous');
    if ($anon_role) {
      $anon_perms = $anon_role->getPermissions();
    }

    // Build native access map while collecting static route paths.
    $nativeAdminMap = [];
    try {
      foreach ($this->routeProvider->getAllRoutes() as $name => $route) {
        if (str_starts_with($name, 'system.') || str_starts_with($name, 'update.')) {
          continue;
        }
        $path = $route->getPath();
        if (!str_contains($path, '{')) {
          $paths[] = $path;
          $nativeAdminMap[$path] = $this->isRouteAdminOnly($route, $anon_perms);
        }
      }
    }
    catch (\Exception) {}

    $paths = array_unique($paths);

    // Build entity-level SEO flag map (metatag defaults + body summary check).
    $entity_seo_map = $this->buildEntitySeoMap($alias_to_system, $paths);

    // Global default: public unless explicitly configured otherwise.
    $global_public = $this->config('route_manager.settings')->get('default_public') !== FALSE;

    $rows = [];
    foreach ($paths as $path) {
      $s = $settings[$path] ?? [];
      $a = $audits[$path] ?? [];

      $is_public    = isset($s['is_public']) ? (int) $s['is_public'] : NULL;
      $native_admin = $nativeAdminMap[$path] ?? FALSE;

      // Resolve effective access for filtering.
      $effective_public = $is_public !== NULL
        ? ($is_public === 1)
        : (!$native_admin && $global_public);

      if ($filter === 'public' && !$effective_public) {
        continue;
      }
      if ($filter === 'hidden' && $effective_public) {
        continue;
      }
      if ($search && !str_contains(strtolower($path), strtolower($search))) {
        continue;
      }

      $errors     = $a ? json_decode($a['errors'] ?? '[]', TRUE) : [];
      $responsive = $a ? json_decode($a['responsive_issues'] ?? '[]', TRUE) : [];

      // status_code: use stored value if available, fall back to heuristic for old rows.
      $status_code = !empty($a)
        ? ($a['status_code'] ?? (isset($a['load_time_ms']) ? 200 : NULL))
        : NULL;

      $rows[] = [
        'id'                     => $s['id'] ?? NULL,
        'route_name'             => $s['route_name'] ?? NULL,
        'path'                   => $path,
        'is_public'              => $is_public,
        'native_admin_only'      => $native_admin,
        'page_title_override'    => $s['page_title_override'] ?? NULL,
        'metatag_overrides'      => $s['metatag_overrides'] ?? NULL,
        'status_code'            => $status_code,
        'load_time_ms'           => isset($a['load_time_ms']) ? (int) $a['load_time_ms'] : NULL,
        'error_count'            => is_array($errors) ? count($errors) : 0,
        'responsive_issue_count' => is_array($responsive) ? count($responsive) : 0,
        'seo_data'               => $a['seo_data'] ?? NULL,
        'entity_seo'             => $entity_seo_map[$path] ?? NULL,
      ];
    }

    usort($rows, fn($a, $b) => strcmp($a['path'], $b['path']));
    return $rows;
  }

  /**
   * Builds a map of path → SEO flags derived from metatag defaults + entity fields.
   *
   * For node-backed paths this checks:
   *   - title: always TRUE when the node metatag default has a title token
   *   - description: TRUE when the node has a non-empty body summary
   *   - og: TRUE when any OG tag default is configured
   *
   * Returns: [ path => ['title' => bool, 'description' => bool, 'og' => bool] ]
   */
  protected function buildEntitySeoMap(array $aliasToSystem, array $paths): array {
    // Resolve every path to its system path, collect node IDs.
    $path_to_nid = [];
    foreach ($paths as $path) {
      $system = $aliasToSystem[$path] ?? $path;
      if (preg_match('#^/node/(\d+)$#', $system, $m)) {
        $path_to_nid[$path] = (int) $m[1];
      }
    }

    if (empty($path_to_nid)) {
      return [];
    }

    // Read metatag defaults for nodes.
    $node_tags = $this->config('metatag.metatag_defaults.node')->get('tags') ?? [];

    // Title: present whenever the defaults define a non-empty title token.
    $default_title = !empty($node_tags['title']);

    // Description: configured via token [node:summary] — only resolves when
    // the node body has a non-empty summary. Check that in bulk.
    $default_desc  = !empty($node_tags['description']);
    $nids_with_summary = [];
    if ($default_desc && $this->database->schema()->tableExists('node__body')) {
      $nids_with_summary = array_flip(
        $this->database->select('node__body', 'nb')
          ->fields('nb', ['entity_id'])
          ->condition('entity_id', array_values($path_to_nid), 'IN')
          ->condition('body_summary', '', '<>')
          ->isNotNull('body_summary')
          ->execute()
          ->fetchCol()
      );
    }

    // OG: present when any og_* tag has a non-empty default.
    $og_keys = ['og_title', 'og_description', 'og_image', 'og_url', 'og_type'];
    $default_og = FALSE;
    foreach ($og_keys as $k) {
      if (!empty($node_tags[$k])) {
        $default_og = TRUE;
        break;
      }
    }

    $map = [];
    foreach ($path_to_nid as $path => $nid) {
      $map[$path] = [
        'title'       => $default_title,
        'description' => $default_desc && isset($nids_with_summary[$nid]),
        'og'          => $default_og,
      ];
    }
    return $map;
  }

  /**
   * Determines whether anonymous users are blocked by native route requirements.
   */
  protected function isRouteAdminOnly(SymfonyRoute $route, array $anonPerms): bool {
    $reqs = $route->getRequirements();

    if (($reqs['_is_admin'] ?? NULL) === 'TRUE') {
      return TRUE;
    }
    if (($reqs['_user_is_logged_in'] ?? NULL) === 'TRUE') {
      return TRUE;
    }
    if (($reqs['_access'] ?? NULL) === 'FALSE') {
      return TRUE;
    }

    if (!empty($reqs['_permission'])) {
      $perm = $reqs['_permission'];
      if (str_contains($perm, '+')) {
        // AND logic: anonymous must hold every permission.
        foreach (array_map('trim', explode('+', $perm)) as $p) {
          if (!in_array($p, $anonPerms, TRUE)) {
            return TRUE;
          }
        }
      }
      else {
        // OR logic: anonymous must hold at least one.
        $any = FALSE;
        foreach (array_map('trim', explode(',', $perm)) as $p) {
          if (in_array($p, $anonPerms, TRUE)) {
            $any = TRUE;
            break;
          }
        }
        if (!$any) {
          return TRUE;
        }
      }
    }

    // Entity creation requires authentication in virtually all cases.
    if (!empty($reqs['_entity_create_any_access']) || !empty($reqs['_entity_create_access'])) {
      return TRUE;
    }

    if (!empty($reqs['_role'])) {
      $roles = array_map('trim', explode(',', $reqs['_role']));
      if (!in_array('anonymous', $roles, TRUE)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Organise a flat list of routes into a two-level tree.
   *
   * Returns: [ primary_segment => [ secondary_segment => [ ...rows ] ] ]
   * Routes with only one segment go under secondary key ''.
   */
  protected function groupRoutes(array $rows): array {
    $groups = [];
    foreach ($rows as $row) {
      $parts     = array_values(array_filter(explode('/', $row['path'])));
      $primary   = $parts[0] ?? 'homepage';
      $secondary = $parts[1] ?? '';

      $groups[$primary][$secondary][] = $row;
    }
    ksort($groups);
    foreach ($groups as &$subs) {
      ksort($subs);
    }
    return $groups;
  }

  /**
   * Returns [label, css-modifier] for the effective access of a route.
   *
   * Priority: explicit is_public > native route requirements > global default.
   */
  protected function effectiveAccessLabel(?int $is_public, bool $nativeAdminOnly = FALSE): array {
    if ($is_public === 1) {
      return [$this->t('Anonymous'), 'public'];
    }
    if ($is_public === 0) {
      return [$this->t('Admin only'), 'hidden'];
    }
    // Inherited — check native route requirements first.
    if ($nativeAdminOnly) {
      return [$this->t('Admin only (route)'), 'hidden-inherited'];
    }
    $global_public = $this->config('route_manager.settings')->get('default_public') !== FALSE;
    if ($global_public) {
      return [$this->t('Anonymous (default)'), 'public-inherited'];
    }
    return [$this->t('Admin only (default)'), 'hidden-inherited'];
  }

  protected function handleToggle(string $path, int $value): void {
    $existing = $this->database->select('route_manager_settings', 'rms')
      ->fields('rms', ['id'])
      ->condition('path', $path)
      ->execute()
      ->fetchField();

    if ($existing) {
      $this->database->update('route_manager_settings')
        ->fields(['is_public' => $value, 'updated' => time()])
        ->condition('path', $path)
        ->execute();
    }
    else {
      $this->database->insert('route_manager_settings')
        ->fields(['path' => $path, 'is_public' => $value, 'updated' => time()])
        ->execute();
    }
  }

  protected function csvCell(string $value): string {
    return '"' . str_replace('"', '""', $value) . '"';
  }

  protected function buildFilterForm(string $search, string $filter): array {
    return [
      '#type'     => 'inline_template',
      '#template' => '<form method="get" class="route-manager-filter-form">
        <input type="text" name="search" value="{{ search }}" placeholder="{{ placeholder }}" />
        <select name="filter">
          <option value="all"{% if filter == "all" %} selected{% endif %}>{{ all }}</option>
          <option value="public"{% if filter == "public" %} selected{% endif %}>{{ public }}</option>
          <option value="hidden"{% if filter == "hidden" %} selected{% endif %}>{{ hidden }}</option>
        </select>
        <button type="submit" class="button button--small">{{ submit }}</button>
      </form>',
      '#context' => [
        'search'      => $search,
        'filter'      => $filter,
        'placeholder' => $this->t('Search by path…'),
        'all'         => $this->t('All'),
        'public'      => $this->t('Anonymous (public)'),
        'hidden'      => $this->t('Admin only (hidden)'),
        'submit'      => $this->t('Filter'),
      ],
    ];
  }

}
