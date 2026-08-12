<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

requireMethod('GET');
currentEmployeeUser();
jsonResponse(true, 'Reservation options retrieved.', employeeReservationService()->options());
