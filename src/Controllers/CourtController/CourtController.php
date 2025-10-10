<?php
use Firebase\JWT\JWT; // Importar la librería JWT de Firebase
use Psr\Http\Message\ResponseInterface as Response; // Importar la interfaz de respuesta de PSR
use Psr\Http\Message\ServerRequestInterface as Request; // Importar la interfaz de solicitud de PSR

require_once __DIR__ . "/../../Config/Database.php";

return function ($app){
    // chequeada
    $app->post('/court', function($request, $response){
        $user = $request->getAttribute('user');
        $data = json_decode($request->getBody(), true);

        if((int) $user->is_admin === 0){
            $response->getBody()->write(json_encode(['error' => 'requiere ser administrador']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400); //bad request            
        }
        if (!$data || !isset($data['name']) || !isset($data['description'])){
            $response->getBody()->write(json_encode(['error' => 'faltan datos']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400); //bad request
        }

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare('SELECT name FROM courts WHERE name = ?');
            $stmt->execute([trim($data['name'])]);
            $usado = (bool) $stmt->fetchColumn();

            Helpers::agregarTiempoToken($user->sub);

            if ($usado){
                $response->getBody()->write(json_encode(['error' => "El nombre ingresado ya esta en uso"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(409); // conflict
            }

            $stmt = $pdo->prepare('INSERT INTO courts (name, description) VALUES (?, ?)');
            $stmt->execute([trim($data['name']), trim($data['description'])]);

        } catch (PDOException $e){
            $response->getBody()->write(json_encode(['error' => 'fallo en la base de datos', 'detalles' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500); // error interno
        };

        $pdo = null;

        $response->getBody()->write(json_encode(['message' => 'cancha creada con exito']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200); // ok

    })->add(new MiddlewareAuth());

    // chequeada
    $app->put('/court/{courtID}', function($request, $response, Array $args){
        $user = $request->getAttribute('user');
        $courtID = $args['courtID'];
        $datos = json_decode($request->getBody(), true);

        $admin = (bool) $user->is_admin;

        if (!$admin){
            $response->getBody()->write(json_encode(['error' => 'requiere ser un usuario administrador']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403); // forbidden
        }

        // zona de variables
        $nombre = $datos['name'] ?? "";
        $descripcion = $datos['description'] ?? "";

        if (trim($nombre) === "" && trim($descripcion) === ""){
            $response->getBody()->write(json_encode(['error' => 'no se han proporcionado datos para modificar']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400); // bad request
        }

        $campos = [];
        $valores = [];

        try {
            $pdo = Database::getConnection();

            Helpers::agregarTiempoToken($user->sub);

            $stmt = $pdo->prepare('SELECT id FROM courts WHERE id = ?');
            $stmt->execute([$courtID]);
            $existe = (bool) $stmt->fetchColumn();

            if (!$existe){
                $response->getBody()->write(json_encode(['error' => 'la cancha no existe );']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400); 
            }

            if (trim($nombre) !== ""){
                
                $stmt = $pdo->prepare('SELECT name FROM courts WHERE name = ?');
                $stmt->execute([trim($nombre)]);
                $existe = (bool) $stmt->fetchColumn();
                if ($existe){
                    $response->getBody()->write(json_encode(['error' => 'el nombre ya esta asociado a alguna cancha']));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(409); // conflict
                }

                $campos[] = "name = ?";
                $valores[] = trim($nombre);
            }

            if (trim($descripcion) !== ""){
                $campos[] = "description = ?";
                $valores[] = trim($descripcion);
            }

            $valores[] = $courtID;

            $stmt = $pdo->prepare('UPDATE courts SET ' . implode(",", $campos) . ' WHERE id = ?');
            $stmt->execute($valores);

        } catch (PDOException $e){
            $response->getBody()->write(json_encode(['error' => 'error interno en la base de datos', 'detalles' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500); // internal error
        }

        $pdo = null;
        
        $response->getBody()->write(json_encode(['message' => 'cancha actualizada con exito']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200); // ok

    })->add(new MiddlewareAuth());

    // chequeado
    $app->delete('/court/{courtID}', function($request, $response, Array $args){

        $user = $request->getAttribute('user');
        $courtID = $args['courtID'];

        if((int) $user->is_admin === 0){
            $response->getBody()->write(json_encode(['error' => 'requiere ser administrador']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400); //bad request            
        }

        try {

            $pdo = Database::getConnection();

            Helpers::agregarTiempoToken($user->sub);

            $stmt = $pdo->prepare('SELECT id FROM courts WHERE id = ?');
            $stmt->execute([$courtID]);
            $existe = (bool) $stmt->fetchColumn();

            if (!$existe){
                $response->getBody()->write(json_encode(['error' => 'la cancha no existe );']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400); 
            }

            $stmt = $pdo->prepare('SELECT 1 FROM bookings b WHERE court_id = ? ');
            $stmt->execute([$courtID]);
            $existe = (bool) $stmt->fetchColumn();

            if ($existe){
                $response->getBody()->write(json_encode(['error' => 'la cancha ya esta en alguna reserva']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(409); // forbbiden
            }

            $stmt = $pdo->prepare('DELETE FROM courts WHERE id = ?');
            $stmt->execute([$courtID]);

        } catch (PDOException $e){
            $response->getBody()->write(json_encode(['error' => 'error interno en la base de datos', 'detalles' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500); // internal error
        }

        $pdo = null;

        $response->getBody()->write(json_encode(['message' => 'cancha eliminada con exito']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200); // ok

    })->add(new MiddlewareAuth());

    // chequeado
    $app->get('/court/{courtID}', function($request, $response, Array $args){
        $user = $request->getAttribute('user');
        $courtID = $args['courtID'];

        try {
            $pdo = Database::getConnection();

            Helpers::agregarTiempoToken($user->sub);

            $stmt = $pdo->prepare('SELECT name, description FROM courts WHERE id = ?');
            $stmt->execute([$courtID]);
            $datos = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$datos){
                $response->getBody()->write(json_encode(['error' => 'La cancha no existe :(']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404); // not found
            }
        
        } catch (PDOException $e){
            $response->getBody()->write(json_encode(['error' => 'error interno en la base de datos', 'detalles' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500); // internal error
        }

        $pdo = null;

        $response->getBody()->write(json_encode([
            'Resultado' => 'Informacion de la cancha obtenida!!!',
            'nombre' => $datos['name'],
            'descripcion' => $datos['description']
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200); // ok

    })->add(new MiddlewareAuth());

};