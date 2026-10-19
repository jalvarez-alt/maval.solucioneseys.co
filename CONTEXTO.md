# MAVAL — contexto del proyecto

Documento para retomar el trabajo en un chat nuevo. Última actualización: 2026-10-18 (v1.4.0, desplegada en producción).

## Qué es
Aplicación web **PHP 8.1+ / MySQL (PDO)**, sin Composer ni Node. Mesa de ayuda (tickets) con avisos por correo, inventario de cómputo y otros equipos (incluye aires acondicionados), planeador de mantenimiento preventivo, roles y permisos, cotizaciones con PDF y envío por correo, gastos, presupuesto, cartera (facturas y abonos) y entradas de dinero. Producción: https://maval.solucioneseys.co (Namecheap, cPanel).

## Ubicación local
- Proyecto: `C:\xampp\htdocs\maval.solucioneseys.co` (repositorio git: https://github.com/jalvarez-alt/maval.solucioneseys.co, rama `main`).
- URL local con Apache de XAMPP: `http://localhost/maval.solucioneseys.co/`
- BD local: MySQL de XAMPP, base `maval`, usuario `root`, sin contraseña, host `127.0.0.1`.
- `app/config.php` apunta a la BD **local**. La config de producción está en `app/config.remote.php.bak` (si existe en esta copia; contiene credenciales).
- **No subir `app/config.php` local al servidor.** Para producción usar el `.bak`.
- `D:\ProyectoX\maval\` contiene la primera descarga (copia vieja; la vigente es la de htdocs).

## Servidor (SFTP)
- Config en `.vscode/sftp.json` (extensión SFTP de VS Code). Ojo: ahora tiene `uploadOnSave: true` (sube al guardar); revisar antes de editar archivos sensibles.
- Despliegue por línea de comandos usado el 2026-10-18: `WinSCP.com /ini=nul /script=...` con `open sftp://USER:PASS@host:21098/ -hostkey=*` y `put` por archivo (rutas locales con `\`; hay que crear `assets/img` si no existe). Los scripts con la contraseña se borran al terminar.
- Estado de producción: **v1.4.0, BD en versión 4** (migrada el 2026-10-18). Respaldo previo (BD v2 + archivos reemplazados) quedó en la carpeta temporal de la sesión de ese día; para futuros despliegues repetir el respaldo.
- Host `199.188.201.4`, puerto `21098`, usuario `aqreqjec`, `remotePath` = `/home/aqreqjec/maval.solucioneseys.co`.
- La cuenta **no tiene shell SSH** (solo SFTP) y el MySQL remoto **no acepta conexiones externas**.
- Para exportar la BD de producción se usó un PHP temporal con token subido por SFTP, ejecutado por HTTPS y borrado de inmediato. Repetir el mismo método si hace falta una copia nueva.
- Base de producción: `aqreqjec_maval` (credenciales en el `.bak`).
- En el servidor hay otras carpetas no relacionadas (`decidex`, `decidex.solucioneseys.co`, etc.); no tocarlas.
- `.vscode/sftp.json` tiene contraseña en texto plano: no subirlo a ningún repositorio.

## Estructura
- `index.php`: enrutador. `?p=<pagina>` carga `app/pages/<pagina>.php` (lista blanca en el propio `index.php`), exige login y valida CSRF.
- `login.php`, `logout.php`, `instalar.php` (instalador; en producción ya se eliminó la ejecución, aquí queda `instalar.php.old`), `archivo.php` (entrega de adjuntos con permiso).
- `app/bootstrap.php`: núcleo (config, PDO, sesión, CSRF, auth, permisos `can()`/`require_perm()`, migraciones, catálogos). `APP_VERSION` 1.4.0, `DB_VERSION` 4. Función `q()` para consultas preparadas, `db()` para el PDO.
- `app/pages/`: una página por módulo (tickets, inventario, computo*, importar, mantenimiento*, cotizacion*, usuarios, roles, rol_form, empresas, config_correo, gastos, estadisticas, presupuesto, categorias, cartera, factura*, ingresos, perfil, inicio).
- `app/computo.php`, `app/importar.php`, `app/masivo.php`: lógica de inventario de cómputo, importación masiva Excel/CSV y acciones masivas.
- `app/mail.php` (SMTP propio sin dependencias, ajustes en tabla `ajustes`, plantilla HTML, `despues()` envía tras responder), `app/notificaciones.php` (avisos de tickets y recordatorios de mantenimiento), `app/mantenimiento.php` (lógica del planeador), `app/cotizaciones.php` (totales, datos del emisor y PDF de cotizaciones en 2 formatos), `cron.php` (tarea diaria de recordatorios).
- `app/lib/`: `xlsx.php` (genera), `xlsx_read.php` (lee), `pdf.php` — librerías propias, sin dependencias.
- `app/layout_top.php` / `layout_bottom.php`: plantilla.
- `sql/schema.sql`: esquema (21 tablas; las migraciones de `migrar()` aplican v2, v3 y v4 sobre bases existentes; una instalación nueva queda en v2 y migra sola a la primera visita). La BD se actualiza sola al entrar como administrador tras subir una versión nueva.
- `assets/`: CSS/JS (se versionan por `filemtime`); `assets/img/banner_cotizacion.png` es el encabezado por defecto de las cotizaciones. `uploads/`: adjuntos (protegidos por `.htaccess`).
- `maval.zip`: paquete de despliegue anterior. `error_log`: log de PHP del servidor.

## Base de datos (tablas)
empresas, usuarios, login_intentos, equipos, equipo_software, equipo_movimientos, tickets, ticket_comentarios, adjuntos, gasto_categorias, gastos, presupuestos, facturas, pagos, **roles, rol_permisos, ajustes, correo_log, mantenimientos, cotizaciones, cotizacion_items** (+ `app_meta`, usada para versionado del esquema).

## Roles y permisos (v1.4.0)
- Cada usuario tiene un **rol** (`usuarios.rol_id`). Roles del sistema: Administrador (acceso total, no editable), Usuario y Empresa (permisos editables). Se pueden crear roles propios en *Roles y permisos*.
- Un rol define el **alcance** de los datos (todos / solo su empresa / solo los propios) y una matriz **módulo × acción** (consultar, crear, editar, eliminar, aprobar, administrar) guardada en `rol_permisos`. Módulos y acciones válidas: constante `MODULOS` en `bootstrap.php`.
- En código: `can('modulo', 'accion')`, `require_perm()`, `alcance()`. `is_admin()` solo identifica al rol Administrador. La columna heredada `usuarios.rol` se mantiene sincronizada (admin/usuario/empresa).
- Mesa de ayuda: *administrar* = atender (estado, asignar, notas internas, recibe avisos del equipo); *aprobar* = marcar resuelto/cerrado.

## Cotizaciones (v1.4.0)
- Módulo `cotizaciones` (menú *Comercial*). Dos formatos: **items** (réplica de Prueba.pdf: encabezado del cliente, tabla Ítem/Referencia/Descripción/Marca/Unidad/Cant/Valor, totales, cuadros «Cordial saludo» y «Condiciones Comerciales») y **propuesta** (réplica de Prueba2.pdf: carta con Descripción, Alcances, Oferta comercial con mano de obra y Condiciones). Consecutivo único para ambos (`cot_siguiente()`, inicia en `ajustes.cot_inicio`). Encabezado (banner) centrado y pie de página centrado en todas las páginas con dirección + teléfonos, correo y web de la empresa (`cot_pie_contacto()`; teléfonos por defecto `Cel - 3113082084`, editables en la configuración).
- PDF generado con `app/lib/pdf.php`, que ahora soporta imágenes (JPG y PNG 8 bits, con o sin alfa) y `wrap()`. Encabezado por defecto: `assets/img/banner_cotizacion.png`; se puede reemplazar desde *Configurar cotizaciones* (se guarda en `uploads/cot_banner.*`).
- Se envían por correo con el PDF adjunto desde una **cuenta SMTP propia** (claves `cot_*` en `ajustes`, `correo_config('cot')`), independiente de la de la mesa de ayuda. Permisos: *aprobar* = enviar y cambiar estado; *administrar* = configuración.

## Correo y notificaciones
- Configuración en *Correo y notificaciones* (SMTP o `mail()`); la contraseña se guarda cifrada con una clave derivada de la config de la BD (si cambia la clave de la BD hay que reescribirla).
- Tickets: aviso al solicitante al crear, en cada cambio de estado y en cada respuesta pública; aviso al equipo de soporte. Las notas internas no se envían.
- Mantenimiento: aviso al programar, recordatorio N días antes y recordatorios cada N días mientras esté vencido. Una vez al día se dispara con la primera visita; para puntualidad configurar en cPanel un cron diario: `php /home/USUARIO/maval.solucioneseys.co/cron.php` (o la URL con token que muestra la pantalla de configuración).

## Pruebas útiles (local)
- Verificar PDF visualmente: generar el PDF desde PHP y renderizarlo con pdf.js en Edge headless (`msedge --headless=new --allow-file-access-from-files --screenshot` sobre un HTML que incrusta el PDF en base64 y usa `pdf.min.js` + `pdf.worker.min.js` locales); no hay `pdftoppm` instalado.
- Probar correos sin buzón real con un servidor SMTP falso en PHP (`stream_socket_server` en 127.0.0.1:2525) y configurar el SMTP de la app hacia él.
- Para probar permisos crear usuarios y roles de prueba y borrarlos al terminar.

## Seguridad existente
bcrypt, CSRF en formularios, bloqueo tras 8 intentos fallidos (15 min), cierre de sesión por inactividad (4 h), adjuntos servidos solo con permiso, `app/`, `sql/` y `uploads/` bloqueadas por `.htaccess`. Límite de adjuntos 10 MB (`.user.ini`).

## Flujo de trabajo acordado
1. Trabajar y probar en local (XAMPP).
2. Antes de desplegar: confirmar que `app/config.php` **no** se sube (ni `uploads/` local si se quiere conservar los adjuntos de producción).
3. Subir cambios por SFTP (extensión de VS Code), solo los archivos modificados.
4. Hacer copia de la BD y de `uploads/` antes de cambios de esquema.

## Pendientes / notas
- `.gitignore` ya excluye `app/config.php`, `config.remote.php.bak`, `.vscode/`, `uploads/*`, `error_log`, zips.
- **Pendiente tras el despliegue v1.4.0 (hacerlo como administrador en producción):** configurar la cuenta de correo de la mesa de ayuda (*Correo y notificaciones*), la de cotizaciones, los datos de la empresa y el consecutivo inicial (*Configurar cotizaciones*), y crear en cPanel el Cron Job diario `php /home/aqreqjec/maval.solucioneseys.co/cron.php`.
- Al desplegar una versión nueva: subir los archivos modificados (nunca `app/config.php`), entrar como administrador (la BD se migra sola) y hacer antes copia de la BD.
- Ideas pendientes: permisos por usuario (hoy son por rol), restringir el acceso por IP, reportes de cotizaciones por cliente.
- `INSTALACION.md` tiene la guía de instalación en cPanel (el archivo tiene problemas de codificación al leerlo desde PowerShell; está en UTF-8).
- La contraseña SFTP y la de la BD de producción fueron manejadas en esta sesión; considerar rotarlas.
