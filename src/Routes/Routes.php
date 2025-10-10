<?php
use Slim\App;


return function (App $app) {


    //RUTA DE LOS CONTROLADORES
    (require __DIR__ . '/../Controllers/UsersControllers/UserController.php')($app);
    (require __DIR__ . '/../Controllers/CourtController/CourtController.php')($app);
    (require __DIR__ . '/../Controllers/BookingsController/BookingsController.php')($app);

};