<?php
$directorio_relativo_ficheros = "ficheros/";
/*
Se recomienda un enlace simbolico para mayor seguridad

sudo ln -fs /opt/opengnsys/images/groups/ ./ficheros/
chown www-data ficheros 
o permitir a poder subir y modificar la carpeta al usuario de apache
Y crear dentro del directorio ./ficheros/ un fichero .htaccess con el contenido:
	Options -Indexes
De esta menera no seran acesible los fichero desde el exterior
*/
?>


