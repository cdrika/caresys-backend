<?php

use CodeIgniter\Boot;
use Config\Paths;

require_once __DIR__ . '/../app/Deployment/SurfaceGate.php';
$surface=(string)(getenv('CARESYS_SURFACE') ?: ($_SERVER['CARESYS_SURFACE'] ?? 'web'));
$requestPath=parse_url($_SERVER['REQUEST_URI'] ?? '/',PHP_URL_PATH);
if (!is_string($requestPath) || !\App\Deployment\SurfaceGate::allows($surface,$requestPath)) {
    http_response_code(404);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo '{"error":{"code":"NOT_FOUND"}}';
    exit;
}
// Per-process URLs override the shared development environment, without changing the main site.
if ($deploymentUrl=getenv('CARESYS_BASE_URL')) {
    $_ENV['app.baseURL']=$_SERVER['app.baseURL']=$deploymentUrl;
    putenv('app.baseURL='.$deploymentUrl);
    $_ENV['app.indexPage']=$_SERVER['app.indexPage']='';
    putenv('app.indexPage=');
}
if ($surface==='api') defined('CI_DEBUG') || define('CI_DEBUG',false);

/*
 *---------------------------------------------------------------
 * CHECK PHP VERSION
 *---------------------------------------------------------------
 */

$minPhpVersion = '8.2'; // If you update this, don't forget to update `spark`.
if (version_compare(PHP_VERSION, $minPhpVersion, '<')) {
    $message = sprintf(
        'Your PHP version must be %s or higher to run CodeIgniter. Current version: %s',
        $minPhpVersion,
        PHP_VERSION,
    );

    header('HTTP/1.1 503 Service Unavailable.', true, 503);
    echo $message;

    exit(1);
}

/*
 *---------------------------------------------------------------
 * SET THE CURRENT DIRECTORY
 *---------------------------------------------------------------
 */

// Path to the front controller (this file)
define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);

// Ensure the current directory is pointing to the front controller's directory
if (getcwd() . DIRECTORY_SEPARATOR !== FCPATH) {
    chdir(FCPATH);
}

/*
 *---------------------------------------------------------------
 * BOOTSTRAP THE APPLICATION
 *---------------------------------------------------------------
 * This process sets up the path constants, loads and registers
 * our autoloader, along with Composer's, loads our constants
 * and fires up an environment-specific bootstrapping.
 */

// LOAD OUR PATHS CONFIG FILE
// This is the line that might need to be changed, depending on your folder structure.
require FCPATH . '../app/Config/Paths.php';
// ^^^ Change this line if you move your application folder

$paths = new Paths();
if ($deploymentEnv=getenv('CARESYS_ENV_DIR')) $paths->envDirectory=$deploymentEnv;
if ($deploymentWrite=getenv('CARESYS_WRITABLE')) $paths->writableDirectory=$deploymentWrite;

// LOAD THE FRAMEWORK BOOTSTRAP FILE
require $paths->systemDirectory . '/Boot.php';

exit(Boot::bootWeb($paths));
