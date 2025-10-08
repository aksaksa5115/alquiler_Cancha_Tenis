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
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'OPTIONS, GET, POST, PUT, PATCH, DELETE')
        ->withHeader('Content-Type', 'application/json');
});

$app->addErrorMiddleware(true, true, true);

require_once __DIR__ . '/../Middleware/JWTMiddleware.php';
$JWT = new JWTmiddleware("secret_password_no_copy");

// Rutas
(require __DIR__ . '/../routes/routes.php')($app, $JWT);

// Ruta simple de prueba
$app->get('/', function (Request $request, Response $response) {
    $response->getBody()->write("API SLIM funcionando");
    return $response;
});

$app->run();