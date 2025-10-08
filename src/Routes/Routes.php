<?php
use Slim\App;


return function (App $app, $JWT) {


    //RUTA DE LOS CONTROLADORES
    (require __DIR__ . '/../Controllers/UsersControllers/UserController.php')($app, $JWT);
    (require __DIR__ . '/../Controllers/CourtController/CourtController.php')($app, $JWT);
    (require __DIR__ . '/../Controllers/BookingsController/BookingsController.php')($app, $JWT);



};