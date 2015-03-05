<?php
require_once __DIR__.'/../vendor/autoload.php'; 
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

define('ROOT_DIR', realpath(__DIR__.'/../'));
define('SRC_DIR', ROOT_DIR . "/src");
define('CONFIG_DIR', ROOT_DIR . "/app/config");
define('WEB_DIR', ROOT_DIR . "/web");
define('REPORTS_DIR', ROOT_DIR . "/reports");

$app = new Silex\Application(); 
$app['debug'] = true;

$app->register(new Silex\Provider\SessionServiceProvider());
$app->register(new Silex\Provider\TwigServiceProvider(), array('twig.path' => SRC_DIR . '/views'));

$app->before(function (Request $request) use ($app) {
    if(!preg_match("/^\/login|^\/logout|^\/upload/i", $request->getPathInfo())) {
        if(!$app['session']->get('user')) {
            return $app->redirect('/login');
        }
    }
});

$app->get('/logout', function () use ($app) {
    $app['session']->set('user', null);
    $app['session']->save();
    return $app->redirect('/login');
});

$app->get('/login', function () use ($app) {
    $username = $app['request']->server->get('PHP_AUTH_USER', false);
    $password = $app['request']->server->get('PHP_AUTH_PW');
    $credentials = json_decode(file_get_contents(CONFIG_DIR."/credentials.json"), true);
    if ($username === $credentials['username'] && $password === $credentials['password']) {
        $app['session']->set('user', array('username' => $username));
        return $app->redirect('/');
    }

    $response = new Response();
    $response->headers->set('WWW-Authenticate', sprintf('Basic realm="%s"', 'GoIntegro Code Coverage Reports'));
    $response->setStatusCode(401, 'Please sign in.');
    return $response;
});


$app->get('/', function () use ($app) {
    $reports = array();
    if ($handle = opendir(REPORTS_DIR)) {
        while (false !== ($entry = readdir($handle))) {
            if(!in_array($entry, array('.', '..', '.gitkeep'))) {
                $filename = REPORTS_DIR."/".$entry."/report/index.html";
                $datetime = new DateTime();
                $datetime->setTimestamp(filemtime($filename));
                $datetime->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'));
                $reports[] = array(
                    'name' => ucfirst($entry),
                    'url'  => "/report/".$entry."/index.html",
                    'last_update' => $datetime->format('d-m-Y H:i:s')
                );
            }
        }
    }
    return $app['twig']->render('report_list.twig', array(
        'reports' => $reports
    ));
});

$app->get('/report/{project}/{file}', function ($project, $file) use ($app) {
    return file_get_contents(REPORTS_DIR . '/' . $project . '/report/' . $file);
});
$app->get('/report/{project}/css/{file}', function ($project, $file) use ($app) {
    $response = new Response(file_get_contents(REPORTS_DIR . '/' . $project . '/report/css/' . $file));
    $response->headers->set('Content-Type', 'text/css');
    return $response;
});
$app->get('/report/{project}/js/{file}', function ($project, $file) use ($app) {
    $response = new Response(file_get_contents(REPORTS_DIR . '/' . $project . '/report/js/' . $file));
    $response->headers->set('Content-Type', 'text/javascript');
    return $response;
});


$app->post('/upload/{project}', function(Request $request, $project) use($app) { 
    $file = $request->files->get('file');
    if ($file !== null) {
        $path = REPORTS_DIR . '/' . strtolower($project);
        $file->move($path, $file->getClientOriginalName());
        $filename = $path."/".$file->getClientOriginalName();
        exec('rm -rf '.$path."/report");
        $phar = new PharData($filename);
        $phar->extractTo($path); 
        unlink($filename);
        $response = "file uploaded successfully: " . $file->getClientOriginalName();
        return new Response($response); 

    } else {
        return new Response("Error, no se envio archivo");
    }    
}); 

$app->run(); 