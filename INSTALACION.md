# MAVAL — Guía de instalación en Namecheap (cPanel)

Aplicación PHP 8 + MySQL. No requiere Composer ni Node: se sube tal cual.

## Requisitos
- Hosting compartido Namecheap (Stellar / Stellar Plus / Business) con cPanel.
- PHP 8.1 o superior (cPanel → **Select PHP Version**) con extensiones `pdo_mysql` y `mbstring` activas.

## 1. Crear la base de datos
1. cPanel → **MySQL® Databases**.
2. *Create New Database*: por ejemplo `maval` (quedará como `usuariocpanel_maval`).
3. *Add New User*: usuario y contraseña segura (guárdelos).
4. *Add User To Database*: asigne el usuario a la base con **ALL PRIVILEGES**.

## 2. Subir los archivos
1. cPanel → **File Manager**.
2. Elija dónde vivirá la app:
   - En el dominio principal: `public_html/`
   - En un subdominio (recomendado, p. ej. `app.sudominio.com`): créelo en cPanel → **Domains** y use su carpeta.
3. Suba `maval.zip` y use **Extract**. Mueva el contenido de la carpeta `maval/` a la raíz elegida (deben quedar `index.php`, `app/`, `assets/`, etc. directamente ahí).
4. Active **Show Hidden Files** y confirme que existen `.htaccess` y `.user.ini`.
5. Permisos: carpetas `755`, archivos `644`. La carpeta `uploads/` y `app/` deben ser escribibles por PHP (755 suele bastar en Namecheap).

## 3. Ejecutar el instalador
1. Abra `https://sudominio.com/instalar.php`.
2. Datos de base de datos: servidor `localhost`, puerto `3306`, nombre y usuario completos (con el prefijo de cPanel).
3. Escriba el nombre y NIT de sus **dos empresas** (para cartera y gastos) y los datos del **administrador**.
4. Pulse **Instalar**.
5. **Elimine `instalar.php`** desde el File Manager.

## 4. SSL
cPanel → **SSL/TLS Status** → *Run AutoSSL*. Luego, en `.htaccess`, quite el `#` de las dos líneas de “Forzar HTTPS”.

## 5. Primeros pasos
1. **Empresas** → registre las empresas cliente (las que atiende la mesa de ayuda).
2. **Usuarios** → cree usuarios con su rol:
   - **Administrador**: acceso total (soporte, inventario, gastos, cartera, administración).
   - **Usuario**: crea y sigue sus tickets; ve los equipos que tiene asignados.
   - **Empresa**: ve y crea tickets de toda su empresa; consulta el inventario de su empresa.
3. **Inventario** → registre computadores, celulares, impresoras, cámaras… (el código se genera solo: LAP-0001, CEL-0001…).
4. **Estadísticas y presupuesto → Presupuesto** → defina el presupuesto mensual por categoría.
5. **Gastos** → registre servicios públicos, víveres, pagos a proveedores, con su soporte.
6. **Cartera** → registre facturas de cada empresa y sus abonos; **Entradas de dinero** muestra el recaudo mensual y acumulado.

## Actualizar una instalación existente
1. Haga una copia de seguridad (ver abajo).
2. Suba el nuevo `maval.zip`, extráigalo y reemplace los archivos **excepto** `app/config.php` y la carpeta `uploads/`.
3. Elimine de nuevo `instalar.php`.
4. Ingrese como administrador: la base de datos se actualiza sola la primera vez (no se pierde información; los “Computador de escritorio” pasan a tipo “Escritorio”).

## Inventario de cómputo
- **Inventario de cómputo** → *Dashboard*: indicadores y gráficas que cambian con los filtros; al hacer clic en una barra se abre el listado ya filtrado.
- *Equipos*: búsqueda y filtros (propietario, tipo, marca, S.O., licencia de S.O./ofimática/antivirus, renovación, estado, rango de fecha de compra). **Excel del filtro** descarga un .xlsx con dos hojas (Equipos y Software); **PDF del filtro** descarga el informe en hoja carta horizontal.
- Cada licencia se marca como **Legal**, **No legal** o **Libre** (gratuito/código abierto).
- Celulares, impresoras, cámaras y equipos de red quedan en **Otros equipos**.

## Importación masiva (Excel o CSV)
1. En **Inventario de cómputo** u **Otros equipos**, pulse **Importar**.
2. **Descargar plantilla**: trae las columnas, listas desplegables (tipo, licencias, renovación, estado) y una hoja de instrucciones. Borre las filas de EJEMPLO.
3. Suba el archivo (.xlsx o .csv). La aplicación reconoce encabezados con otros nombres (p. ej. «Disco duro», «S.O.», «Office», «No. Serie») y valores como Sí/No, Pirata, Gratis, Laptop o PC.
4. Revise la vista previa: equipos **nuevos**, los que se **actualizarán** (mismo código o serial) y las **filas con error**, que puede descargar en Excel para corregirlas. Nada se guarda hasta pulsar **Importar**.
5. Las celdas vacías nunca borran datos existentes. El Excel exportado desde el listado también se puede editar y volver a importar.
6. Columna **Usuario mesa de ayuda**: escriba el correo (o el nombre exacto) de un usuario registrado en la aplicación. Ese usuario verá el equipo en su inventario y podrá reportar tickets sobre él. Escriba «ninguno» para quitarlo.

## Acciones masivas (administrador)
En los listados de **Inventario de cómputo** y **Otros equipos** marque las casillas de los equipos (o la casilla del encabezado y luego «Seleccionar los N equipos del filtro»). Aparece una barra para:
- **Asignar** los equipos a un usuario de la mesa de ayuda (o quitarlo).
- **Cambiar el estado** (Activo, En reparación, Bodega, Dado de baja).
- **Eliminar**: pide escribir ELIMINAR para confirmar. Se borran los equipos con su software e historial; los tickets se conservan sin equipo vinculado. Si el equipo solo salió de servicio, es mejor cambiar su estado a «Dado de baja».
Las acciones solo afectan equipos del listado y filtro que está viendo.

## Copias de seguridad
- Base de datos: cPanel → **Backup** → *Download a MySQL Database Backup* (o phpMyAdmin → Exportar).
- Archivos adjuntos: carpeta `uploads/`.
Hágalo al menos una vez por semana.

## Seguridad incluida
Contraseñas cifradas (bcrypt), protección CSRF en todos los formularios, bloqueo tras 8 intentos fallidos de ingreso (15 min), cierre de sesión por inactividad (4 h), adjuntos servidos solo a quien tiene permiso, y carpetas `app/`, `sql/` y `uploads/` bloqueadas al acceso directo.

## Solución de problemas
- **Error 500 al abrir**: revise la versión de PHP (≥ 8.1). Si persiste, renombre `.htaccess` temporalmente para descartar una directiva no soportada.
- **“No se pudo escribir app/config.php”**: dé permiso de escritura a la carpeta `app/` durante la instalación.
- **No suben adjuntos grandes**: el límite es 10 MB por archivo; ajuste `.user.ini` si su plan lo permite.
- Para ver errores detallados temporalmente, edite `app/config.php` y ponga `'debug' => true` (vuelva a `false` después).
