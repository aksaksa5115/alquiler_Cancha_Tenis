<?php

require_once __DIR__ . "/../Config/Database.php";
class Helpers {

    public static function validarCorreo($correo): bool {

        return (preg_match('/^[a-zA-Z0-9._]+@[a-zA-Z0-9._]+$/', $correo));
    }

    //el formato del nombre solo puede contener letras
    public static function validarNombre($nombre): bool {

        return (preg_match('/^[a-zA-Z ]{3,20}$/', $nombre));
    }

    //el formato de la contraseña debe tener al menos 8 caracteres, al menos una letra mayúscula, al menos una letra minúscula,
    //al menos un número y al menos un caracter especial. No puede tener espacios
    public static function validarPassword($password): bool {

        return (preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/', $password));
    }

    public static function agregarTiempoToken($userID): void {
        try {
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare('SELECT email, is_admin, expired FROM users WHERE id = ?');
            $stmt->execute([$userID]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            date_default_timezone_set('America/Argentina/Buenos_Aires'); // para definir zona horaria
            $expMas = new DateTime($user['expired']);
            $expMas->modify('+5 minutes');
            $fechaNueva = $expMas->format('Y-m-d H:i:s');

            $stmt = $pdo->prepare('UPDATE users SET expired = ? WHERE id = ?');
            $stmt->execute([$fechaNueva, $userID]);
        } catch (PDOException $e){
            error_log("Fallo interno" . $e->getMessage());
            
        };
    }
}