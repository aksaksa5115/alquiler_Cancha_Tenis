<?php

require_once __DIR__ . "/../Config/Database.php";

use Firebase\JWT\JWT; // Importar la librería JWT de Firebase
class Helpers {

    public static function validarCorreo($correo): bool {

        return (preg_match('/^[a-zA-Z0-9._]+@[a-zA-Z0-9._]+$/', $correo));
    }

    //el formato del username solo puede contener letras y numeros y no puede tener espacios
    public static function validarUsername($username): bool {

        return (preg_match('/^[a-zA-Z0-9]{1,20}$/', $username));

    }

    //el formato del nombre solo puede contener letras y no puede tener espacios
    public static function validarNombre($nombre): bool {

        return (preg_match('/^[a-zA-Z]{3,20}$/', $nombre));
    }

    //el formato de la contraseña debe tener al menos 8 caracteres, al menos una letra mayúscula, al menos una letra minúscula,
    //al menos un número y al menos un caracter especial. No puede tener espacios
    public static function validarPassword($password): bool {

        return (preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/', $password));
    }

    public static function agregarTiempoToken($userID): void {
        try {
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare('SELECT email, is_admin FROM users WHERE id = ?');
            $stmt->execute([$userID]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            date_default_timezone_set('America/Argentina/Buenos_Aires'); // para definir zona horaria
            $expMas = time() + 300;

            $key = "secret_password_no_copy";

            $payload = [
                'admin' => $user['is_admin'],
                'sub' => $userID,
                'correo' => $user['email'],
                'exp' => $expMas
            ];
            $jwt = JWT::encode($payload, $key, 'HS256');

            $stmt = $pdo->prepare('UPDATE users SET token = ?, expired = ? WHERE id = ?');
            $stmt->execute([$jwt, date('Y-m-d H:i:s', $expMas), $userID]);
        } catch (PDOException $e){
            error_log("Fallo interno" . $e->getMessage());
            
        };
    }
}