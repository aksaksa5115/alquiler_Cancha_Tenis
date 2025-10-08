<?php
use Firebase\JWT\JWT; // Importar la librería JWT de Firebase
use Psr\Http\Message\ResponseInterface as Response; // Importar la interfaz de respuesta de PSR
use Psr\Http\Message\ServerRequestInterface as Request; // Importar la interfaz de solicitud de PSR

require_once __DIR__ . "/../../Config/Database.php";



return function ($app, $JWT){

    $app->post('/booking', function($request, $response){
        $user = $request->getAttribute('jwt');

        $data = json_decode($request->getBody(), true);

        $jugadores = $data['users'] ?? [];
        $canchaNombre = $data['CourtName'];

        date_default_timezone_set('America/Argentina/Buenos_Aires'); // para definir zona horaria

        $count = count($jugadores);
        $blocks = (int) $data['bloques'];
        $fechaInicio = new DateTime($data['start_time']); 

        if ($count === 1){
            $modo = 'singles';
        } elseif ($count === 3) {
            $modo = 'doubles';
        } else {
            $response->getBody()->write(json_encode(['error' => 'solo puedes elegir 1 o 3 jugadores mas']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400); // bad request
        }

        if ($blocks >= 1 || $blocks <= 6) {
            $response->getBody()->write(json_encode(['error' => 'La cantidad de bloques debe ser entre 1 y 6']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400); // bad request
        }

        $fechaFin = clone $fechaInicio;
        $fechaFin->modify('+' . ($blocks * 30) . ' minutes');

        $fechaLimite = new DateTime($fechaInicio->format('Y-m-d') . ' 22:00:00');

        if ($fechaFin > $fechaLimite){
            $response->getBody()->write(json_encode(['error' => 'la duracion supera la fecha maxima preestablecida']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403); // conflict 
        }

        try {
            $pdo = Database::getConnection();

            Helpers::agregarTiempoToken($user->sub);

            $stmt = $pdo->prepare('SELECT id FROM courts WHERE name = ?');
            $stmt->execute([$canchaNombre]);
            $courtID = (int) $stmt->fetchColumn();

            // revisar que todos los jugadores esten libres para la reserva
            $stmt = $pdo->prepare('SELECT 1 FROM booking_participants bp
            INNER JOIN bookings b ON b.id = bp.booking_id
            WHERE bp.user_id = ? AND b.booking_datetime < ? AND ');

            $stmt = $pdo->prepare('INSERT INTO bookings (created_by, court_id, booking_datetime, duration_blocks)
            VALUE (?, ?, ?, ?)');
            $stmt->execute([$user->sub, $courtID, $fechaInicio, $blocks ]);

            $stmt = $pdo->prepare('SELECT id FROM bookings WHERE created_by = ? and booking_datetime = ?');
            $stmt->execute([$user->sub, $fechaInicio]);
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

    })->add($JWT);


    $app->delete('/booking/{id}', function($request, $response, $args){
        $user = $request->getAttribute('jwt');
        $bookingID = $args['id'];

        $admin = (bool) $user->admin;

        try {
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare('SELECT created_by FROM bookings WHERE id = ?');
            $stmt->execute([$bookingID]);
            $valido = (int) $stmt->fetchColumn();

            if ($valido === 0){
                $response->getBody()->write(json_encode(['error' => 'la reserva no existe']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400); //bad request
            }

            if (!$admin && $valido !== (int) $user->sub){
                $response->getBody()->write(json_encode(['error' => 'no eres administrador ni dueño de la reserva a eliminar']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(403); //unauthorized
            };

            $stmt = $pdo->prepare('DELETE bookings WHERE id = ?');
            $stmt->execute([$bookingID]);

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'fallo en la base de datos', 'detalles' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500); // internal error
        }
        $pdo = null;
        
        $response->getBody()->write(json_encode(['message' => 'reserva eliminada']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200); 

    })->add($JWT);


    $app->get('/booking', function($request, $response){
        $user = $request->getAttribute('jwt');
        $args = $request->getQueryParams();
        $fechaBusqueda = $args['date'] ?? "";

        try {
            $pdo = Database::getConnection();

            $query = 'SELECT b.created_by, b.court_id, b.booking_datetime, b.duration_blocks, c.name FROM bookings b 
            INNER JOIN courts c ON b.court_id = c.id
            WHERE 1 = 1';
            if ($fechaBusqueda !== ""){
                $query .= ' AND DATE(b.booking_datetime) = ?';
                $values[] = $fechaBusqueda; // solo se guarda si hay fecha
            }

            $query .= ' ORDER BY c.name ASC, b.booking_datetime ASC';
            $stmt = $pdo->prepare($query);
            $stmt->execute($values); // si no hay fecha, funciona igual porque ta vacio
            $reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $response->getBody()->write(json_encode([
                "Reserva hecha por" => $reservas['created_by'],
                "En la cancha" => $reservas['name'],
                "fecha inicio" => $reservas['booking_datetime'],
                "cantidad de bloques" => $reservas['duration_blocks']
            ]));

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'fallo en la base de datos', 'detalles' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500); // internal error
        }
        

    });
};