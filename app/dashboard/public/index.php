<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use WpOps\Dashboard\Database\Connection;
use WpOps\Dashboard\Controllers\DashboardController;
use WpOps\Dashboard\Controllers\SitesController;
use WpOps\Dashboard\Controllers\ServersController;
use WpOps\Dashboard\Controllers\JobsController;
use WpOps\Dashboard\Controllers\ApiController;
use WpOps\Dashboard\Controllers\ChecksumController;
use WpOps\Dashboard\Middleware\AuthMiddleware;
use WpOps\Dashboard\Middleware\CorsMiddleware;
use WpOps\Dashboard\Middleware\TwigGlobalsMiddleware;
use WpOps\Dashboard\Middleware\MethodOverrideMiddleware;

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Create Slim app
$app = AppFactory::create();

// Add error middleware
$errorMiddleware = $app->addErrorMiddleware(
    $_ENV['DEBUG'] === 'true',
    true,
    true
);

// Create Twig view
$twig = Twig::create(__DIR__ . '/../templates', [
    'cache' => $_ENV['DEBUG'] === 'true' ? false : __DIR__ . '/../cache'
]);

// Add Twig middleware
$app->add(TwigMiddleware::create($app, $twig));

// Add Twig globals middleware (must be after TwigMiddleware)
$app->add(new TwigGlobalsMiddleware($twig));

// Add method override middleware (must be before routing)
$app->add(new MethodOverrideMiddleware());

// Add CORS middleware
$app->add(new CorsMiddleware());

// Database connection
$db = Connection::getInstance();

// Create logger
$logger = new \Monolog\Logger('dashboard');
$logger->pushHandler(new \Monolog\Handler\StreamHandler(__DIR__ . '/../logs/app.log', \Monolog\Level::Debug));

// Create Database wrapper
$database = new \WpOps\Dashboard\Database\Database($db->getPdo());

// Create services
$sshService = new \WpOps\Dashboard\Services\SSHService([]);
$checksumService = new \WpOps\Dashboard\Services\ChecksumService(
    $database,
    $logger,
    $sshService
);

// Controllers
$dashboardController = new DashboardController($db, $twig);
$sitesController = new SitesController($db, $twig);
$serversController = new ServersController($db, $twig);
$jobsController = new JobsController($db, $twig);
$apiController = new ApiController($db);
$checksumController = new ChecksumController($checksumService, $database, $logger);

// Auth middleware
$authMiddleware = new AuthMiddleware($db);

// Routes - Dashboard
$app->get('/', [$dashboardController, 'index']);
$app->get('/login', [$dashboardController, 'loginForm']);
$app->post('/login', [$dashboardController, 'login']);
$app->get('/logout', [$dashboardController, 'logout']);

// Protected routes group
$app->group('', function ($group) use ($sitesController, $serversController, $jobsController, $apiController, $checksumController) {
    
    // Sites management
    $group->get('/sites', [$sitesController, 'index']);
    $group->get('/sites/{id}', [$sitesController, 'show']);
    $group->post('/sites/{id}/lock', [$sitesController, 'lock']);
    $group->post('/sites/{id}/unlock', [$sitesController, 'unlock']);
    $group->post('/sites/{id}/harden', [$sitesController, 'harden']);
    $group->post('/sites/{id}/unharden', [$sitesController, 'unharden']);
    $group->post('/sites/{id}/fix-permissions', [$sitesController, 'fixPermissions']);
    $group->post('/sites/{id}/scan', [$sitesController, 'scanCode']);
    $group->post('/sites/{id}/backup', [$sitesController, 'backup']);
    $group->get('/sites/{id}/desired-state', [$sitesController, 'desiredStateForm']);
    $group->post('/sites/{id}/desired-state', [$sitesController, 'updateDesiredState']);
    $group->post('/sites/{id}/immutabilize', [$sitesController, 'immutabilize']);
    $group->post('/sites/{id}/deimmutabilize', [$sitesController, 'deimmutabilize']);
    $group->post('/sites/{id}/check-immutability', [$sitesController, 'checkImmutability']);
    $group->post('/sites/check-all-immutability', [$sitesController, 'checkAllImmutability']);
    $group->post('/sites/bulk-immutabilize', [$sitesController, 'bulkImmutabilize']);
    $group->post('/sites/bulk-deimmutabilize', [$sitesController, 'bulkDeimmutabilize']);
    $group->delete('/sites/{id}/delete', [$sitesController, 'delete']);
    
    // Checksum verification routes
    $group->post('/sites/{id}/verify-checksum', [$checksumController, 'startVerification']);
    $group->get('/sites/{id}/checksum-status/{verification_id}', [$checksumController, 'getVerificationStatus']);
    $group->get('/sites/{id}/checksum-history', [$checksumController, 'getVerificationHistory']);
    $group->get('/sites/{id}/unauthorized-files', [$checksumController, 'getUnauthorizedFiles']);
    $group->get('/sites/{id}/checksum-summary', [$checksumController, 'getDashboardSummary']);
    
    // Servers management
    $group->get('/servers', [$serversController, 'index']);
    $group->get('/servers/create', [$serversController, 'create']);
    $group->post('/servers', [$serversController, 'store']);
    $group->get('/servers/{id}', [$serversController, 'show']);
    $group->get('/servers/{id}/scan-logs', [$serversController, 'showScanLogs']);
    $group->get('/servers/{id}/edit', [$serversController, 'edit']);
    $group->put('/servers/{id}', [$serversController, 'update']);
    $group->delete('/servers/{id}', [$serversController, 'delete']);
    $group->post('/servers/{id}/test', [$serversController, 'testConnection']);
    $group->post('/servers/{id}/scan', [$serversController, 'scanWordPress']);
    $group->post('/servers/{id}/async-scan', [$serversController, 'startAsyncScan']);
    $group->get('/servers/{id}/jobs', [$serversController, 'getServerJobs']);
    $group->get('/jobs/{id}/status', [$serversController, 'getJobStatus']);
    $group->get('/servers/{id}/wordpress-sites', [$serversController, 'getWordPressSites']);
    $group->post('/servers/test-connection', [$serversController, 'testConnectionForm']);
    
    // Jobs management
    $group->get('/jobs', [$jobsController, 'index']);
    $group->get('/jobs/{id}', [$jobsController, 'show']);
    $group->post('/jobs/{id}/retry', [$jobsController, 'retry']);
    $group->delete('/jobs/{id}', [$jobsController, 'delete']);
    
    // API endpoints
    $group->group('/api', function ($api) use ($apiController) {
        $api->get('/sites', [$apiController, 'getSites']);
        $api->get('/sites/{id}', [$apiController, 'getSite']);
        $api->post('/sites/{id}/jobs', [$apiController, 'createJob']);
        $api->get('/jobs', [$apiController, 'getJobs']);
        $api->get('/jobs/{id}', [$apiController, 'getJob']);
        $api->post('/jobs/status', [$apiController, 'getJobsStatus']);
        $api->get('/dashboard/stats', [$apiController, 'getDashboardStats']);
        $api->get('/issues', [$apiController, 'getIssues']);
        $api->post('/issues/{id}/resolve', [$apiController, 'resolveIssue']);
    });
    
})->add($authMiddleware);

// Health check endpoint (no auth required) - moved to correct API path
$app->get('/api/health', function (Request $request, Response $response) {
    $health = [
        'status' => 'ok',
        'timestamp' => date('c'),
        'version' => '1.0.0'
    ];
    
    $response->getBody()->write(json_encode($health));
    return $response->withHeader('Content-Type', 'application/json');
});

// Keep the old /health endpoint for backward compatibility
$app->get('/health', function (Request $request, Response $response) {
    $health = [
        'status' => 'ok',
        'timestamp' => date('c'),
        'version' => '1.0.0'
    ];
    
    $response->getBody()->write(json_encode($health));
    return $response->withHeader('Content-Type', 'application/json');
});

// Static files handling (for development)
if ($_ENV['ENVIRONMENT'] === 'development') {
    $app->get('/assets/{file:.+}', function (Request $request, Response $response, array $args) {
        $file = $args['file'];
        $filePath = __DIR__ . '/assets/' . $file;
        
        if (!file_exists($filePath)) {
            return $response->withStatus(404);
        }
        
        $response->getBody()->write(file_get_contents($filePath));
        
        // Set appropriate content type
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        $contentType = match ($extension) {
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream'
        };
        
        return $response->withHeader('Content-Type', $contentType);
    });
}

$app->run();