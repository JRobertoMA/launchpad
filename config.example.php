<?php
// Plantilla de config.php. No hace falta copiarla: si config.php no existe, /admin/ pide
// definir la contraseña y lo crea solo. El fichero real está en .gitignore.
return array (
  // Hash bcrypt de la contraseña del admin. 'CHANGE_ME' fuerza el flujo de primera ejecución.
  'password_hash' => 'CHANGE_ME',
  // Respaldo por si gethostname() falla; normalmente se muestra el nombre real del host.
  'hostname' => 'raspberrypi',
);
