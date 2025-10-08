<?php
use Firebase\JWT\JWT; // Importar la librería JWT de Firebase
use Psr\Http\Message\ResponseInterface as Response; // Importar la interfaz de respuesta de PSR
use Psr\Http\Message\ServerRequestInterface as Request; // Importar la interfaz de solicitud de PSR

require_once __DIR__ . "/../../Config/Database.php";

require_once __DIR__ . "/../../Helpers/Helpers.php";

return function ($app, $JWT) {

    //Registrar un usuario (chequeado)
    $app->post('/users', function($request, $response){
        $data = json_decode($request->getBody(), true);

        //chequeamos que no falte informacion
        if (!$data || !isset($data['email']) || !isset($data['firstName']) || !isset(($data['lastName'])) || !isset($data['password'])){
            $response->getBody()->write(json_encode(["error" => "faltan datos"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400); //bad request
        };

        //eliminamos espacios en blancos en caso de que se encuentre alguno
        $correo = trim($data['email']);
        $primerNombre = trim($data['firstName']);
        $segundoNombre = trim($data['lastName']);
        $contraseña = trim($data['password']);

        if (!Helpers::validarCorreo($correo)){
            $response->getBody()->write(json_encode(["error" => "El correo solo puede contener letras, numeros y debe haber un @ . _."]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400); //bad request
        }

        if (!Helpers::validarNombre($primerNombre)){
            $response->getBody()->write(json_encode(["error" => "El nombre debe tener entre 3 y 20 caracteres y solo puede contener letras."]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400); //bad request
        }

        if (!Helpers::validarNombre($segundoNombre)){
            $response->getBody()->write(json_encode(["error" => "El nombre debe tener entre 3 y 20 caracteres y solo puede contener letras."]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400); //bad request
        }

        if (!Helpers::validarPassword($contraseña)){
            $response->getBody()->write(json_encode(['error' => 'La clave debe tener al menos 8 caracteres, incluyendo mayúsculas, minúsculas, números y caracteres especiales.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400); // bad request
        }

        try {
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare("SELECT * from users WHERE email = ?");
            $stmt->execute([$correo]);

            if($stmt->fetch()) {
                $response->getBody()->write(json_encode(['error' => 'correo electronico ya existente']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(401); //trolo
            }

            $hashed_password = password_hash($contraseña, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO users (email, first_name, last_name, password, is_admin ) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$correo, $primerNombre, $segundoNombre, $hashed_password, 1]);
            $response->getBody()->write(json_encode(['message' => 'Usuario cargado con exito']));

            $pdo = null;
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200); //ok
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'fallo en la base de datos', 'detalles: ' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500); // error interno
        };
    });

    //Iniciar sesion (chequeado)
    $app->post('/login', function($request, $response) {
        $data = json_decode($request->getBody(), true);

        if(!$data || !isset($data['email']) || !isset($data['password'])){
            $response->getBody()->write(json_encode(['error' => 'faltan datos']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400); // bad request
        }

        $correo = trim($data['email']);
        $contraseña = trim($data['password']);

        try {
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare('SELECT id, email, is_admin, password FROM users WHERE email = ?');
            $stmt->execute([$correo]);
            $users = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$users || !password_verify($contraseña, $users['password'])){
                $response->getBody()->write(json_encode(["error" => "Usuario o contraseña incorrectos"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(401); // Unauthorized
            }

            date_default_timezone_set('America/Argentina/Buenos_Aires'); // para definir zona horaria
            $expira = time() + 300;
            $key = "secret_password_no_copy";
            $payload = [
                'admin' => $users['is_admin'],
                'sub' => $users['id'],
                'correo' => $users['email'],
                'exp' => $expira
            ];
            $jwt = JWT::encode($payload, $key, 'HS256');

            $stmt = $pdo->prepare('UPDATE users SET token = ?, expired = ? WHERE email = ?');
            $stmt->execute([$jwt, date('Y-m-d H:i:s', $expira), $users['email']]);

            $pdo = null;

            $response->getBody()->write
            (json_encode(["token" => $jwt, "expira" => date('Y-m-d H:i:s', $expira), "usuario" => $users['email']]));
            
            return $response->withHeader('Content-Type', 'Application/json')->withStatus(200); // ok
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(["error" => "fallo en la conexion a la base de datos", "detalle" => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'Application/json')->withStatus(500); // internal error
        }
    });

    // cerrar sesion (chequeado)
    $app->post('/logout', function($request, $response) {
        $user = $request->getAttribute('jwt');

        $userID = $user->sub;

        try {
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare('UPDATE users SET token = null, expired = null WHERE id = ? ');
            $stmt->execute([$userID]);

            $response->getBody()->write(json_encode(['message' => 'usuario deslogueado']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200); // ok
        } catch (PDOException $e){
            $response->getBody()->write(json_encode(['error' => 'fallo en la conexion a la base de datos', 'detalles' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500); // error interno
        }
    })->add($JWT);

    // modificar datos de un usuario (chequeado)
    $app->patch('/user/{id}', function($request, $response, $args){
        $user = $request->getAttribute('jwt');
        $data = $request->getParsedBody();
        $idModificar = $args['id'];

        if (!$data || !is_array($data)){
            $response->getBody()->write(json_encode(['error' => 'se requiere al menos un dato para modificar']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400); // bad request
        }
        $userID = $user->sub;
        //zona de variables
        $primer_nombre = $data['firstName'] ?? "";
        $ultimo_nombre = $data['lastName'] ?? "";
        $contraseña = $data['password'] ?? "";
        $email = $data['email'] ?? "";

        if (trim($primer_nombre) === "" && trim($ultimo_nombre) === "" && trim($contraseña) === "" && trim($email) === ""){
            $response->getBody()->write(json_encode(['error' => 'no se han proporcionado datos para modificar']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400); // bad request;
        }
        // estos arreglos se usaran para armar la consulta UPDATE
        $campos = [];
        $valores = [];
        $jwt = null;

        try {
            $pdo = Database::getConnection();

            Helpers::agregarTiempoToken($userID);

            // chequear si es el mismo usuario que se quiere modificar o si es un administrador
            $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
            $stmt->execute([$userID]);
            $admin = (bool) $stmt->fetchColumn();

            if (!$admin && $userID !== $idModificar){
                $response->getBody()->write(json_encode(['error' => 'no eres administrador ni el usuario al que se quiere modificar']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(401); //unauthorized
            }

            //a partir de aca seran todos if viendo que pingo quiere actualizar el usuario

            if (trim($primer_nombre) !== ""){
                if (!Helpers::validarNombre($primer_nombre)){
                    $response->getBody()->write(json_encode(["error" => "El nombre de usuario debe tener entre 1 y 20 caracteres y solo puede contener letras."]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400); //bad request
                }
                $campos[] = "first_name = ?";
                $valores[] = trim($primer_nombre);
            }

            if (trim($ultimo_nombre) !== ""){
                if (!Helpers::validarNombre($ultimo_nombre)){
                    $response->getBody()->write(json_encode(["error" => "El nombre de usuario debe tener entre 1 y 20 caracteres y solo puede contener letras."]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400); //bad request
                }
                $campos[] = "last_name = ?";
                $valores[] = trim($ultimo_nombre);
            }

            if (trim($contraseña) !== ""){
                if (!Helpers::validarPassword($contraseña)){
                    $response->getBody()->write(json_encode(['error' => 'La clave debe tener al menos 8 caracteres, incluyendo mayúsculas, minúsculas, números y caracteres especiales.']));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400); // bad request
                }

                $campos[] = "password = ?";
                $valores[] = password_hash($contraseña, PASSWORD_BCRYPT);
            }

            if (trim($email) !== ""){
                if (!Helpers::validarCorreo($contraseña)){
                    $response->getBody()->write(json_encode(["error" => "El correo solo puede contener letras, numeros y debe haber un @ . _."]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400); // bad request
                }
                $stmt = $pdo->prepare('SELECT email FROM users WHERE email = ?');
                $stmt->execute([trim($email)]);
                $existe = (bool) $stmt->fetchColumn();
                if ($existe){
                    $response->getBody()->write(json_encode(['error' => 'El correo ya esta asociado a alguna cuenta']));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400); // bad request
                }
                $campos[] = "email = ?";
                $valores[] = trim($email);
            }

            $valores[] = $idModificar;

            $stmt = $pdo->prepare('UPDATE users SET ' . implode(", ", $campos) . " WHERE id = ?");
            $stmt->execute($valores);

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(["error" => "Error al conectar a la base de datos.",
            'detalle' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        $pdo = null;
        $response->getBody()->write(json_encode(["message" => "Usuario actualizado con exito"]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200); // ok
    })->add($JWT);


    // chequeado
    $app->get('/bienvenida', function (Request $request, Response $response)  {
        $user = $request->getAttribute('jwt');
        $response->getBody()->write(json_encode([
            'mensaje' => 'Bienvenido ' . $user->correo . ' con ID ' . $user->sub,
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    })->add($JWT);



    // chequeado
    $app->delete('/user/{id}', function($request, $response, $args){
        $user = $request->getAttribute('jwt');
        $userID = $user->sub;
        $idEliminar = $args['id'];

        try {
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
            $stmt->execute([$userID]);
            $admin = (bool) $stmt->fetchColumn();

            if (!$admin && $userID !== $idEliminar){
                $response->getBody()->write(json_encode(['error' => 'no eres administrador ni el usuario al que se quiere modificar']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(401); //unauthorized
            }
            
            // chequear si es administrador
            $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
            $stmt->execute([$idEliminar]);
            $admin = (bool) $stmt->fetchColumn();

            if ($admin){
                $response->getBody()->write(json_encode(['error' => 'no es posible eliminar administradores']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400); // bad request

            }

            // chequear si no esta en alguna reserva
            $stmt = $pdo->prepare('SELECT 1
                FROM bookings b
                INNER JOIN booking_participants bp ON b.id = bp.booking_id
                WHERE b.created_by = ? OR bp.user_id = ?
                LIMIT 1');
            $stmt->execute([$idEliminar, $idEliminar]);
            $reserva = (bool) $stmt->fetchColumn();

            if ($reserva){
                $response->getBody()->write(json_encode(['error' => 'el usuario esta en una o mas reservas']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400); // bad request
            }

            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$idEliminar]);

            $pdo = null;

            $response->getBody()->write(json_encode(['message' => 'usuario borrado con exito']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (PDOException $e){
            $response->getBody()->write(json_encode(["error" => "error interno en la base de datos", "detalles" => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);

        }

    })->add($JWT);

    // chequeado
    $app->get('/user/{id}', function (Request $request, Response $response, $args)  {
        $user = $request->getAttribute('jwt');
        $id = $args['id'];

        try {
            $pdo = Database::getConnection();

            Helpers::agregarTiempoToken($user->sub);

            $stmt = $pdo->prepare('SELECT first_name, last_name, password, is_admin FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $datos = $stmt->fetch(PDO::FETCH_ASSOC);

            $admin = "no";
            if ($datos['is_admin']){
                $admin = "si";
            }

        } catch (PDOException $e){
            $response->getBody()->write(json_encode(["error" => "fallo interno en la base de datos", "detalles" => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json');
        }
        
        $response->getBody()->write(json_encode([
            'informacion del usuario recuperada',
            "primer nombre" => $datos['first_name'],
            "segundo nombre" => $datos['last_name'],
            "correo electronico" => $user->correo,
            "contraseña" => $datos['password'],
            "administrador" => $admin
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    })->add($JWT);

    // chequeado
    $app->get('/users', function ($request, $response){
        $user = $request->getAttribute('jwt');
        $texto = $args['text'] ?? '';

        try {
            $pdo = Database::getConnection();

            Helpers::agregarTiempoToken($user->sub);

            $query = 'SELECT first_name, last_name, email, is_admin FROM users WHERE 1 = 1';
            if ($texto !== ''){
                $query .= ' AND first_name LIKE ? OR last_name LIKE ? OR email LIKE ?';
                
                $stmt = $pdo->prepare($query);
                $stmt->execute([$texto, $texto, $texto]);
            } else {
                $stmt = $pdo->prepare($query);
                $stmt->execute();
            }
            $datos = $stmt->fetch(PDO::FETCH_ASSOC);
            $admin = "no";
            if ($datos['is_admin']){
                $admin = "si";
            }

            $pdo = null;
        } catch (PDOException $e){
            $response->getBody()->write(json_encode(["error" => "fallo interno en la base de datos", "detalles" => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode([
            "Resultado: " => "Información de usuarios obtenida!! ",
            "primer nombre :" => $datos['first_name'],
            "ultimo nombre :" => $datos['last_name'],
            "correo electronico :" => $datos['email'],
            "administrador :" => $admin
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200); // ok
    })->add($JWT);
};