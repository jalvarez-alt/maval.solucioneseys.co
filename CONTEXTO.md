# MAVAL — contexto del proyecto

Documento para retomar el trabajo en un chat nuevo. Última actualización: 2026-10-18.

## Qué es
Aplicación web **PHP 8.1+ / MySQL (PDO)**, sin Composer ni Node. Mesa de ayuda (tickets), inventario de cómputo y otros equipos, gastos, presupuesto, cartera (facturas y abonos) y entradas de dinero. Producción: https://maval.solucioneseys.co (Namecheap, cPanel).

## Ubicación local
- Proyecto: `C:\xampp\htdocs\maval.solucioneseys.co` (tiene `.git` inicializado, sin commits).
- URL local con Apache de XAMPP: `http://localhost/maval.solucioneseys.co/`
- BD local: MySQL de XAMPP, base `maval`, usuario `root`, sin contraseña, host `127.0.0.1`.
- `app/config.php` apunta a la BD **local**. La config de producción está en `app/config.remote.php.bak` (si existe en esta copia; contiene credenciales).
- **No subir `app/config.php` local al servidor.** Para producción usar el `.bak`.
- `D:\ProyectoX\maval\` contiene la primera descarga (copia vieja; la vigente es la de htdocs).

## Servidor (SFTP)
- Config en `.vscode/sftp.json` (extensión SFTP de VS Code), `uploadOnSave: false`.
- Host `199.188.201.4`, puerto `21098`, usuario `aqreqjec`, `remotePath` = `/home/aqreqjec/maval.solucioneseys.co`.
- La cuenta **no tiene shell SSH** (solo SFTP) y el MySQL remoto **no acepta conexiones externas**.
- Para exportar la BD de producción se usó un PHP temporal con token subido por SFTP, ejecutado por HTTPS y borrado de inmediato. Repetir el mismo método si hace falta una copia nueva.
- Base de producción: `aqreqjec_maval` (credenciales en el `.bak`).
- En el servidor hay otras carpetas no relacionadas (`decidex`, `decidex.solucioneseys.co`, etc.); no tocarlas.
- `.vscode/sftp.json` tiene contraseña en texto plano: no subirlo a ningún repositorio.

## Estructura
- `index.php`: enrutador. `?p=<pagina>` carga `app/pages/<pagina>.php` (lista blanca en el propio `index.php`), exige login y valida CSRF.
- `login.php`, `logout.php`, `instalar.php` (instalador; en producción ya se eliminó la ejecución, aquí queda `instalar.php.old`), `archivo.php` (entrega de adjuntos con permiso).
- `app/bootstrap.php`: núcleo (config, PDO, sesión, CSRF, auth, utilidades). `APP_VERSION` 1.2.0. Función `q()` para consultas preparadas, `db()` para el PDO.
- `app/pages/`: una página por módulo (tickets, inventario, computo*, importar, usuarios, empresas, gastos, estadisticas, presupuesto, categorias, cartera, factura*, ingresos, perfil, inicio).
- `app/computo.php`, `app/importar.php`, `app/masivo.php`: lógica de inventario de cómputo, importación masiva Excel/CSV y acciones masivas.
- `app/lib/`: `xlsx.php` (genera), `xlsx_read.php` (lee), `pdf.php` — librerías propias, sin dependencias.
- `app/layout_top.php` / `layout_bottom.php`: plantilla.
- `sql/schema.sql`: esquema (14 tablas). La BD se actualiza sola al entrar como administrador tras subir una versión nueva.
- `assets/`: CSS/JS (se versionan por `filemtime`). `uploads/`: adjuntos (protegidos por `.htaccess`).
- `maval.zip`: paquete de despliegue anterior. `error_log`: log de PHP del servidor.

## Base de datos (tablas)
empresas, usuarios, login_intentos, equipos, equipo_software, equipo_movimientos, tickets, ticket_comentarios, adjuntos, gasto_categorias, gastos, presupuestos, facturas, pagos (+ `app_meta`, usada para versionado del esquema).

## Roles
- **Administrador**: acceso total.
- **Usuario**: sus tickets y los equipos asignados.
- **Empresa**: tickets e inventario de toda su empresa.

## Seguridad existente
bcrypt, CSRF en formularios, bloqueo tras 8 intentos fallidos (15 min), cierre de sesión por inactividad (4 h), adjuntos servidos solo con permiso, `app/`, `sql/` y `uploads/` bloqueadas por `.htaccess`. Límite de adjuntos 10 MB (`.user.ini`).

## Flujo de trabajo acordado
1. Trabajar y probar en local (XAMPP).
2. Antes de desplegar: confirmar que `app/config.php` **no** se sube (ni `uploads/` local si se quiere conservar los adjuntos de producción).
3. Subir cambios por SFTP (extensión de VS Code), solo los archivos modificados.
4. Hacer copia de la BD y de `uploads/` antes de cambios de esquema.

## Pendientes / notas
- Sin commits en git todavía; añadir `.gitignore` con `app/config.php`, `app/config.remote.php.bak`, `.vscode/sftp.json`, `uploads/*`, `error_log`, `maval.zip`.
- `INSTALACION.md` tiene la guía de instalación en cPanel (el archivo tiene problemas de codificación al leerlo desde PowerShell; está en UTF-8).
- La contraseña SFTP y la de la BD de producción fueron manejadas en esta sesión; considerar rotarlas.
