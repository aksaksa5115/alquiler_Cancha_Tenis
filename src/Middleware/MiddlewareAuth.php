<?php

use Slim\Psr7\Response;


require_once __DIR__ . "/../Config/Database.php";

class MiddlewareAuth {

    public function __invoke($request, $handler): Response {

        $autheader = $request->getHeaderLine('Authorization');

        if (!$autheader  || !str_starts_with($autheader, 'Bearer ')){
            $response = new Response();
            $response->getBody()->write(json_encode(['error' => 'Token requerido']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401); 
        }

        $token = trim(str_replace('Bearer ', '', $autheader));

        try {
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare('SELECT id, is_admin, email, expired FROM users WHERE token = ? ');
            $stmt->execute([$token]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $response = new Response();
                $response->getBody()->write(json_encode(['error' => 'Token invalido']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
            }
            date_default_timezone_set('America/Argentina/Buenos_Aires');
            $fechaActual = new DateTime();
            $fechaToken = new DateTime($user['expired']);

            if ($fechaActual > $fechaToken){
                $response = new Response();
                $response->getBody()->write(json_encode(['error' => 'Token expirado']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
            }

            $request = $request->withAttribute('user', (object)[
                'sub' => $user['id'],
                'email' => $user['email'],
                'is_admin' => $user['is_admin']
            ]);
            $response = $handler->handle($request);
            return $response;
        }   catch (PDOException $e) {
            $response->getBody()->write(json_encode(["error" => "fallo en la conexion a la base de datos", "detalle" => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'Application/json')->withStatus(500); // internal error
        }
    }
}