<?php
use Psr\Http\Message\ResponseInterface as Response; // Importar la interfaz de respuesta de PSR
use Psr\Http\Message\ServerRequestInterface as Request; // Importar la interfaz de solicitud de PSR

require_once __DIR__ . "/../../Config/Database.php";

require_once __DIR__ . "/../../Helpers/Helpers.php";



return function ($app){

    $app->post('/booking', function($request, $response){
        $user = $request->getAttribute('user');

        $data = json_decode($request->getBody(), true);

        if (!$data || !isset($data['users']) || !isset($data['CourtName']) || !isset($data['bloques']) || !isset($data['start_time'])){
            $response->getBody()->write(json_encode(['error' => 'faltan datos']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400); // bad request
        }

        $jugadores = $data['users'] ?? [];
        $canchaNombre = $data['CourtName'] ?? "";

        if (!is_array($jugadores)) {
            $response->getBody()->write(json_encode(['error' => 'users debe ser un array de IDs']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        if (in_array($user->sub, $jugadores)){
            $response->getBody()->write(json_encode(['error' => 'no puedes colocarte a ti mismo como compañero']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        date_default_timezone_set('America/Argentina/Buenos_Aires'); // para definir zona horaria

        $count = count($jugadores);
        $blocks = (int) $data['bloques'] ?? -1;
        $fechaInicio = new DateTime($data['start_time']) ?? ""; 

        if ($count === 1){
            $modo = 'singles';
        } elseif ($count === 3) {
            $modo = 'doubles';
        } else {
            $response->getBody()->write(json_encode(['error' => 'solo puedes elegir 1 o 3 jugadores mas']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400); // bad request
        }

        if ($blocks < 1 || $blocks > 6) {
            $response->getBody()->write(json_encode(['error' => 'La cantidad de bloques debe ser entre 1 y 6']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400); // bad request
        }

        $fechaFin = clone $fechaInicio;
        $fechaFin->modify('+' . ($blocks * 30) . ' minutes');

        $fechaLimite = new DateTime($fechaInicio->format('Y-m-d') . ' 22:00:00');

        if ($fechaFin > $fechaLimite){
            $response->getBody()->write(json_encode(['error' => 'la duracion supera la fecha maxima preestablecida']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(409); // conflict 
        }

        try {
            $pdo = Database::getConnection();

            Helpers::agregarTiempoToken($user->sub);

            $stmt = $pdo->prepare('SELECT id FROM courts WHERE name = ?');
            $stmt->execute([$canchaNombre]);
            $courtID = (int) $stmt->fetchColumn();

            if ($courtID === 0) {
                $response->getBody()->write(json_encode(['error' => 'la cancha no existe']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404); //not found;
            }
            $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
            foreach($jugadores as $jugadorID){
                $stmt->execute([$jugadorID]);
                $existe = (bool) $stmt->fetchColumn();

                if (!$existe){
                    $response->getBody()->write(json_encode(['error' => "El jugador con id $jugadorID no existe"]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }

            }

            // revisar que todos los jugadores esten libres para la reserva
            $stmt = $pdo->prepare('SELECT 1 FROM booking_participants bp
            INNER JOIN bookings b ON b.id = bp.booking_id
            WHERE bp.user_id = ? AND b.booking_datetime < ? 
            AND DATE_ADD(b.booking_datetime, INTERVAL (b.duration_blocks * 30 ) MINUTE) > ?  ');

            // antes me agrego a mi mismo en el arreglo para verificar que no tengo una reserva
            $jugadores[] = $user->sub; 
            foreach($jugadores as $jugadorID){
                $stmt->execute([$jugadorID, $fechaFin->format('Y-m-d H:i:s'), $fechaInicio->format('Y-m-d H:i:s')]);
                $ocupado = $stmt->fetchColumn();

                if ($ocupado){
                    $response->getBody()->write(json_encode(['error' => "El usuario con id $jugadorID ya tiene una reserva en ese horario"]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
                }
            }

            $stmt = $pdo->prepare('INSERT INTO bookings (created_by, court_id, booking_datetime, duration_blocks)
            VALUE (?, ?, ?, ?)');
            $stmt->execute([$user->sub, $courtID, $fechaInicio->format('Y-m-d H:i:s'), $blocks ]);

            $stmt = $pdo->prepare('SELECT id FROM bookings WHERE created_by = ? and booking_datetime = ?');
            $stmt->execute([$user->sub, $fechaInicio->format('Y-m-d H:i:s')]);
            $reservaID = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare('INSERT INTO booking_participants (booking_id, user_id) VALUES (?, ?)');
            foreach ($jugadores as $id) {
                $stmt->execute([$reservaID, $id]);
            }


        } catch (PDOException $e){
            $response->getBody()->write(json_encode(['error' => 'error interno en la base de datos', 'detalles' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500); // internal error
        }

        $pdo = null;

        $response->getBody()->write(json_encode(['message' => 'reserva creada con exito!!']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

    })->add(new MiddlewareAuth());


    $app->delete('/booking/{id}', function($request, $response, $args){
        $user = $request->getAttribute('user');
        $bookingID = (int) $args['id'];

        $admin = (bool) $user->is_admin;

        try {
            $pdo = Database::getConnection();

            Helpers::agregarTiempoToken($user->sub);

            $stmt = $pdo->prepare('SELECT created_by FROM bookings WHERE id = ?');
            $stmt->execute([$bookingID]);
            $valido = (int) $stmt->fetchColumn();

            if ($valido === 0){
                $response->getBody()->write(json_encode(['error' => 'la reserva no existe']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404); //bad request
            }

            if (!$admin && $valido !== (int) $user->sub){
                $response->getBody()->write(json_encode(['error' => 'no eres administrador ni dueño de la reserva a eliminar']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(403); //unauthorized
            };

            $stmt = $pdo->prepare('DELETE FROM booking_participants WHERE booking_id = ?');
            $stmt->execute([$bookingID]);

            $stmt = $pdo->prepare('DELETE FROM bookings WHERE id = ?');
            $stmt->execute([$bookingID]);

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'fallo en la base de datos', 'detalles' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500); // internal error
        }
        $pdo = null;
        
        $response->getBody()->write(json_encode(['message' => 'reserva eliminada']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200); 

    })->add(new MiddlewareAuth());

    //verificado
    $app->get('/booking', function($request, $response, $args){
        $args = $request->getQueryParams();
        $fechaBusqueda = $args['date'] ?? "";

        try {
            $pdo = Database::getConnection();

            $query = 'SELECT b.created_by, b.court_id, b.booking_datetime, b.duration_blocks, c.name FROM bookings b 
            INNER JOIN courts c ON b.court_id = c.id
            WHERE 1 = 1';

            $values = [];
            if ($fechaBusqueda !== ""){
                $query .= ' AND DATE(b.booking_datetime) = ?';
                $values[] = $fechaBusqueda; // solo se guarda si hay fecha
            }

            $query .= ' ORDER BY c.name ASC, b.booking_datetime ASC';
            $stmt = $pdo->prepare($query);
            $stmt->execute($values); // si no hay fecha, funciona igual porque ta vacio
            $reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$reservas){
                $response->getBody()->write(json_encode(['message' => 'No se encontro ninguna reserva']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
            }

            foreach($reservas as $reserva){
                $resultado[] = [
                "Reserva hecha por el usuario con id" => $reserva['created_by'],
                "En la cancha" => $reserva['name'],
                "fecha inicio" => $reserva['booking_datetime'],
                "cantidad de bloques" => $reserva['duration_blocks']
                ];
            }

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'fallo en la base de datos', 'detalles' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500); // internal error
        }
        $pdo = null;

        $response->getBody()->write(json_encode([$resultado]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200); // ok
        

    });

    //verificado
    $app->put('/booking_participant/{id}', function($request, $response, $args){
        $user = $request->getAttribute('user');
        $data = json_decode($request->getBody(), true);


        if (!$data || !isset($data['nuevos']) || !isset($data['viejos'])){
            $response->getBody()->write(json_encode(['error' => 'faltan datos']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400); // bad request
        }

        if (!is_array($data['nuevos']) || !is_array($data['viejos'])) {
            $response->getBody()->write(json_encode(['error' => 'nuevos y viejos deben ser un arrays de IDs']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $reserva = (int) $args['id'];
        $jugadoresNuevos = $data['nuevos'] ?? [];
        $jugadoresViejos = $data['viejos'] ?? [];

        if (count($jugadoresNuevos) !== count($jugadoresViejos)){
            $response->getBody()->write(json_encode(['error' => 'no puedes meter / sacar mas jugadores de los que sacas / metes']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        if (in_array($user->sub, $jugadoresNuevos) || in_array($user->sub, $jugadoresViejos)){
            $response->getBody()->write(json_encode(['error' => 'no puedes agregarte o eliminarte de la reserva']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
        }

        if (empty($jugadoresNuevos) && empty($jugadoresViejos)) {
            $response->getBody()->write(json_encode(['message' => 'No hay nada']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        }

        try {
            $pdo = Database::getConnection();

            Helpers::agregarTiempoToken($user->sub);

            //verificar que exista la reserva y pertenezca al usuario
            $stmt = $pdo->prepare('SELECT 1 FROM bookings WHERE id = ? AND created_by = ?');
            $stmt->execute([$reserva, $user->sub]);
            $existe = (bool) $stmt->fetchColumn();

            if (!$existe){
                $response->getBody()->write(json_encode(['error' => 'la reserva no existe o no eres el dueño']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            //verificar que todos los jugadores existan
            $stmt = $pdo->prepare('SELECT 1 FROM users WHERE id = ?');
            foreach($jugadoresNuevos as $jugadorID){
                $stmt->execute([$jugadorID]);
                $existe = (bool) $stmt->fetchColumn();

                if (!$existe){
                    $response->getBody()->write(json_encode(['error' => "El usuario con id $jugadorID no existe"]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
                }
            }

            foreach($jugadoresViejos as $jugadorID){
                $stmt->execute([$jugadorID]);
                $existe = (bool) $stmt->fetchColumn();

                if (!$existe){
                    $response->getBody()->write(json_encode(['error' => "El usuario con id $jugadorID no existe"]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
                }
            }

            //traigo fechas de inicio y fin de la reserva
            $stmt = $pdo->prepare('SELECT booking_datetime, duration_blocks FROM bookings WHERE id = ?');
            $stmt->execute([$reserva]);
            $datos = $stmt->fetch(PDO::FETCH_ASSOC);


            // verificar que los usuarios nuevos esten disponibles
            $stmt = $pdo->prepare('SELECT 1 FROM booking_participants bp
            INNER JOIN bookings b ON b.id = bp.booking_id
            WHERE bp.user_id = ? AND b.booking_datetime < ? 
            AND DATE_ADD(b.booking_datetime, INTERVAL (b.duration_blocks * 30 ) MINUTE) > ?  ');
            
            $inicio = new DateTime($datos['booking_datetime']);
            $fechaFin = clone $inicio;
            $fechaFin->modify('+' . ((int) $datos['duration_blocks'] * 30) . ' minutes');
            foreach($jugadoresNuevos as $jugadorID){
                $stmt->execute([$jugadorID, $fechaFin->format('Y-m-d H:i:s'), $inicio->format('Y-m-d H:i:s')]);
                $ocupado = $stmt->fetchColumn();

                if ($ocupado){
                    $response->getBody()->write(json_encode(['error' => "El usuario con id $jugadorID ya tiene una reserva en ese horario"]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
                }
            }

            //verificar que los usuarios viejos pertenezcan a la reserva dada
            $stmt = $pdo->prepare('SELECT 1 FROM booking_participants WHERE user_id = ? AND booking_id = ?');
            foreach($jugadoresViejos as $jugadorID){
                $stmt->execute([$jugadorID, $reserva]);
                $existe = (bool) $stmt->fetchColumn();
                if (!$existe){
                    $response->getBody()->write(json_encode(['error' => "El usuario con id $jugadorID no esta en la reserva"]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }                
            }

            //eliminar los usuarios viejos y meter a los nuevos

            $stmt = $pdo->prepare('DELETE FROM booking_participants WHERE booking_id = ? AND user_id = ?');
            foreach($jugadoresViejos as $jugadorID){
                $stmt->execute([$reserva, $jugadorID]);
            }

            $stmt = $pdo->prepare('INSERT INTO booking_participants (booking_id, user_id) VALUES (?, ?)');
            foreach ($jugadoresNuevos as $jugadorID) {
                $stmt->execute([$reserva, $jugadorID]);
            }



        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'fallo en la base de datos', 'detalles' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500); // internal error
        }
        $pdo = null;

        $response->getBody()->write(json_encode(['message' => 'usuarios cambiados con exito']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200); // ok

    })->add(new MiddlewareAuth());
};