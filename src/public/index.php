<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../../vendor/autoload.php';

$app = AppFactory::create();

// Middlewares Slim
$app->addRoutingMiddleware();
$app->addBodyParsingMiddleware();


$app->add(function ($request, $handler) {
    $response = $handler->handle($request);

    return $response
        ->withHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
        ->withHeader('Access-Control-Allow-Credentials', 'true');
});

// Preflight CORS
$app->options('/{routes:.+}', function ($request, $response) {
    return $response;
});

$app->addErrorMiddleware(true, true, true);


// Rutas
(require __DIR__ . '/../routes/routes.php')($app);

// Ruta simple de prueba
$app->get('/', function (Request $request, Response $response) {
    $response->getBody()->write("API SLIM funcionando");
    return $response;
});

$app->run();