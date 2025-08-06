<?php
/**
 * Simple URL Router for Ayuni Beta
 */

class Router {
    private $routes = [];
    
    public function __construct() {
        $this->setupRoutes();
    }
    
    private function setupRoutes() {
        // Define URL routes -> [page, additional params]
        $this->routes = [
            '' => ['page' => 'home'],
            'home' => ['page' => 'home'],
            'login' => ['page' => 'home', 'step' => 'login'],
            'register' => ['page' => 'home', 'step' => 'register'],
            'beta-access' => ['page' => 'home', 'step' => 'beta_code'],
            'admin' => ['page' => 'admin'],
            'admin/api' => ['page' => 'admin-api'],
            'admin/prompts' => ['page' => 'admin-prompts'],
            'admin/users' => ['page' => 'admin-users'],
            'admin/beta' => ['page' => 'admin-beta'],
            'admin/emotions' => ['page' => 'admin-emotions'],
            'admin/social' => ['page' => 'admin-social'],
            'admin/logs' => ['page' => 'admin-logs'],
            'dashboard' => ['page' => 'dashboard'],
            'create-aei' => ['page' => 'create-aei'],
            'chat' => ['page' => 'chat'],
            'profile' => ['page' => 'profile'],
            'onboarding' => ['page' => 'onboarding'],
            'logout' => ['action' => 'logout'],
        ];
    }
    
    public function resolve($route) {
        // Clean the route
        $route = trim($route, '/');
        
        // Check for exact match first
        if (isset($this->routes[$route])) {
            return $this->routes[$route];
        }
        
        // Check for dynamic routes (like chat/aei-id)
        if (preg_match('/^chat\/(.+)$/', $route, $matches)) {
            return ['page' => 'chat', 'aei' => $matches[1]];
        }
        
        // Default to home if no match
        return ['page' => 'home'];
    }
    
    public function getCurrentRoute() {
        $route = $_GET['route'] ?? '';
        return trim($route, '/');
    }
    
    public function url($route, $params = []) {
        $url = '/' . trim($route, '/');
        
        if (!empty($params)) {
            $queryString = http_build_query($params);
            $url .= '?' . $queryString;
        }
        
        return $url;
    }
}

// Initialize router
$router = new Router();
$currentRoute = $router->getCurrentRoute();
$routeParams = $router->resolve($currentRoute);

// Set page and other parameters
$page = $routeParams['page'];
unset($routeParams['page']);

// Merge route parameters with GET parameters
foreach ($routeParams as $key => $value) {
    $_GET[$key] = $value;
}
?>