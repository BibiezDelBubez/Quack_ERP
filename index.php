<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$routes = require __DIR__ . '/app/routes.php';
$router = new Router($routes);

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$basePath = rtrim(BASE_URL, '/');
if ($basePath !== '' && stripos($uriPath, $basePath) === 0) {
    // Reindirizza al case canonico se la maiuscola non corrisponde (es. /quack → /Quack)
    if (!str_starts_with($uriPath, $basePath)) {
        $canonical = $basePath . substr($uriPath, strlen($basePath));
        header('Location: ' . $canonical, true, 301);
        exit;
    }
    $uriPath = substr($uriPath, strlen($basePath));
}

// Canonicalizzazione path: slash iniziale, niente slash finale (tranne root)
$path = '/' . ltrim($uriPath ?: '/', '/');
if ($path !== '/') {
    $path = rtrim($path, '/');
    if ($path === '') {
        $path = '/';
    }
}

// Redirect verso URL canonico se richiesto con slash finale.
// 302 in sviluppo per evitare cache aggressiva dei redirect durante i fix.
$requestPathForCompare = '/' . ltrim($uriPath ?: '/', '/');
if ($requestPathForCompare !== $path) {
    $query = $_SERVER['QUERY_STRING'] ?? '';
    $target = url(ltrim($path, '/'));
    if ($query !== '') {
        $target .= '?' . $query;
    }
    header('Location: ' . $target, true, 302);
    exit;
}

$route = $router->resolve($path);

$requiresAuth = $route['auth'] ?? true;
if ($requiresAuth && !is_authenticated()) {
    redirect('login');
}

if (!empty($route['permission']) && !can($route['permission'])) {
    http_response_code(403);
    // Se è una chiamata AJAX/API restituisce JSON, altrimenti pagina HTML
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Accesso negato. Permesso richiesto: ' . $route['permission']]);
    } else {
        echo '<h1>403 — Accesso negato</h1><p>Non hai i permessi per eseguire questa operazione.</p>';
    }
    exit;
}

switch ($route['type']) {
    case 'redirect':
        header('Location: ' . url($route['target']));
        exit;

    case 'view':
        require __DIR__ . '/app/views/' . $route['target'];
        exit;

    case 'action':
        require __DIR__ . '/app/views/' . $route['target'];
        exit;

    case 'controller':
        require __DIR__ . '/app/Controllers/BaseController.php';
        require __DIR__ . '/app/Controllers/' . $route['controller'] . '.php';
        $ctrl = new $route['controller']($pdo);
        $ctrl->{$route['action']}();
        exit;

    case 'legacy':
    default:
        require __DIR__ . '/' . $route['target'];
        exit;
}
