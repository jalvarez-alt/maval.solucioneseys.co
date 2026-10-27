# MAVAL — contexto del proyecto

Documento para retomar el trabajo en un chat nuevo. Última actualización: 2026-10-18 (v1.15.0 en local; producción en v1.14.0 hasta que se despliegue).

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
- `app/bootstrap.php`: núcleo (config, PDO, sesión, CSRF, auth, permisos `can()`/`require_perm()`, migraciones, catálogos). `APP_VERSION` 1.15.0, `DB_VERSION` 10. Función `q()` para consultas preparadas, `db()` para el PDO.
- `app/pages/`: una página por módulo (tickets, inventario, computo*, importar, mantenimiento*, cotizacion*, usuarios, roles, rol_form, empresas, config_correo, gastos, estadisticas, presupuesto, categorias, cartera, factura*, ingresos, perfil, inicio).
- `app/computo.php`, `app/importar.php`, `app/masivo.php`: lógica de inventario de cómputo, importación masiva Excel/CSV y acciones masivas.
- `app/mail.php` (SMTP propio sin dependencias, ajustes en tabla `ajustes`, plantilla HTML, `despues()` envía tras responder), `app/notificaciones.php` (avisos de tickets y recordatorios de mantenimiento), `app/mantenimiento.php` (lógica del planeador), `app/cotizaciones.php` (totales, datos del emisor y PDF de cotizaciones en 2 formatos), `cron.php` (tarea diaria de recordatorios).
- `app/lib/`: `xlsx.php` (genera), `xlsx_read.php` (lee), `pdf.php` — librerías propias, sin dependencias.
- `app/layout_top.php` / `layout_bottom.php`: plantilla.
- `sql/schema.sql`: esquema (21 tablas; las migraciones de `migrar()` aplican v2 a v10 sobre bases existentes; una instalación nueva queda en v2 y migra sola a la primera visita). La BD se actualiza sola al entrar como administrador tras subir una versión nueva.
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
- Módulo `cotizaciones` (menú *Comercial*). Dos formatos: **items** (réplica de Prueba.pdf: encabezado del cliente, tabla Ítem/Referencia/Descripción/Marca/Unidad/Cant/Valor, totales, cuadros «Cordial saludo» y «Condiciones Comerciales») y **propuesta** (réplica de Prueba2.pdf: carta con Descripción, Alcances, Oferta comercial con mano de obra y Condiciones). Consecutivo único para ambos (`cot_siguiente()`, inicia en `ajustes.cot_inicio`). **Propuesta con hasta dos ofertas comerciales** (v5): `cotizacion_items.oferta` (1 o 2) y, en `cotizaciones`, `titulo1/titulo2`, `mano_obra2`, `subtotal2`, `iva2`, `total2` (los campos sin sufijo son la oferta 1). El PDF muestra «3. OFERTAS COMERCIALES» con «Oferta N: título» y un total por oferta; listados y correos muestran «$ a / $ b» (`cot_total_texto()`). Diseño del PDF con los colores del logo (azul `#0e2f56`, coral `#ff304f`; constantes `COT_*`): banner centrado con línea de acento, tablas con encabezado azul y filas alternas, títulos con cuadro coral, texto de Descripción y Alcances **justificado** (`SimplePdf::textJ`), y pie de página (franja azul con `assets/img/logo_cotizacion.png` y los datos de contacto centrados: dirección + teléfonos, correo y web; teléfonos por defecto `Cel - 3113082084`, editables). Cada oferta (y la cotización con ítems) tiene la casilla «Sumar los ítems y mostrar el total» (`cotizaciones.sumar1/sumar2`, por defecto marcada): si se desmarca, el PDF y el detalle muestran solo los valores por ítem, sin subtotal ni total. El campo «Mano de obra» ya no se ofrece; solo se conserva en cotizaciones antiguas que lo traían. Con dos ofertas, la casilla «Sumar las dos ofertas» (`cotizaciones.sumar_ofertas`) agrega «TOTAL OFERTA 1 + OFERTA 2» en el PDF y el detalle.
- PDF generado con `app/lib/pdf.php`, que ahora soporta imágenes (JPG y PNG 8 bits, con o sin alfa) y `wrap()`. Encabezado por defecto: `assets/img/banner_cotizacion.png`; se puede reemplazar desde *Configurar cotizaciones* (se guarda en `uploads/cot_banner.*`).
- Se envían por correo con el PDF adjunto desde una **cuenta SMTP propia** (claves `cot_*` en `ajustes`, `correo_config('cot')`), independiente de la de la mesa de ayuda. Permisos: *aprobar* = enviar y cambiar estado; *administrar* = configuración.

## Por facturar (v1.15.0)
- Módulo `porfacturar` (menú *Finanzas → Por facturar*, con contador de pendientes): lista de trabajos/servicios realizados que aún no tienen factura. Tabla `por_facturar` (BD v10): fecha, cliente y NIT, concepto, detalle, valor, referencia, empresa que factura, fecha prevista de facturación, estado (Pendiente / Facturado / Descartado), enlace a la factura (`factura_id`) o número manual (`factura_numero`) y a la cotización (`cotizacion_id`). Páginas `por_facturar` (indicadores, filtros, Excel, pendiente por cliente) y `por_facturar_form`.
- **Facturar**: el botón abre `factura_form?pendiente=ID` ya precargado (empresa, cliente, NIT, concepto, subtotal = valor); al guardar la factura el pendiente pasa a *Facturado* y queda enlazado. También se puede marcar *ya facturado por fuera* (con número opcional), descartar y reabrir.
- Una cotización **Aceptada** muestra «Pasar a por facturar» (precarga cliente, valor total y referencia) y luego «Ya está en Por facturar». El inicio muestra el total pendiente y cuántos tienen fecha vencida.
- Permisos del módulo: ver / crear / editar / eliminar / *aprobar* (facturar, marcar facturado, descartar, reabrir). Facturar además exige `cartera.crear`. Pendiente de desplegar.

## Categorías desde el presupuesto (v1.14.0)
- En *Estadísticas y presupuesto → Presupuesto* las categorías (Agua y alcantarillado, Arriendo…) se pueden **renombrar** (lápiz), **agregar** («Agregar categoría») y **quitar** (papelera) sin salir de la página y sin perder lo digitado en la tabla: las acciones `cat_crear`, `cat_renombrar` y `cat_quitar` responden JSON (en `presupuesto.php`). Permisos del módulo *categorias* (crear / editar / eliminar). Quitar: si la categoría no tiene gastos se elimina (su presupuesto se borra en cascada); si tiene gastos solo se desactiva. Son las mismas categorías de *Categorías de gasto* y de Gastos. Sin cambios de BD. Pendiente de desplegar.

## Cartera por cliente y estados de cuenta (v1.13.0, traída de producción)
- Página `?p=cartera_cliente` (`app/pages/cartera_cliente.php`, lógica en `app/cartera_estado.php`): agrupa por cliente las facturas pendientes (el cliente se identifica por su nombre normalizado, `car_cli_clave()`, y se unen nombres con el mismo NIT) y arma el **estado de cuenta** que se envía por correo; cada envío queda en la tabla `cartera_envios` (BD v9: fecha, cliente, destinatarios, asunto, mensaje, facturas, saldo, resultado). Permiso nuevo `cartera.administrar` = enviar estados de cuenta. `enviar_correo()` acepta `desde_nombre` y `correo_plantilla()` un pie personalizado; el registro `correo_log` ya no se purga a los 90 días. Cartera muestra «Por cliente» y los clientes con mayor saldo enlazan a esa página.

## Texto final de la cotización con ítems (v1.12.0)
- Campo `cotizaciones.texto_final` (HTML normalizado, mismo editor con formato que «Alcances»: `cot_editor_rt()` en `cotizacion_form.php`, reutilizable). Se imprime justificado después de los totales y **antes** de los cuadros «Cordial saludo / Condiciones Comerciales» (pasa de página si hace falta; los cuadros no se parten) y se ve en el detalle. Valor por defecto para cotizaciones nuevas: *Configurar cotizaciones → Texto al final* (`ajustes.cot_texto_final`); las cotizaciones existentes quedan sin texto.

## Cartera por Excel (v1.11.0)
- En *Cartera*: **Excel** ahora exporta un .xlsx real (Empresa, Factura, Cliente, NIT, Emisión, Vencimiento, Concepto, Subtotal, IVA, Retenciones, Total, Abonado, Saldo, Días vencida, Estado) y **Importar Excel** (permiso *crear*): `?p=cartera_importar`, mismo flujo que empresas (subir → vista previa → confirmar). Columnas de la plantilla: Empresa, Factura, Cliente, NIT, Emisión, Vencimiento, Concepto, Subtotal, IVA, Retenciones, Total, Abonado, Saldo, Fecha abono, Notas. La empresa que factura (propia) viene de la columna o de un selector al subir. Total = Subtotal + IVA − Retenciones (si solo trae Total, el subtotal se calcula); «Abonado» (o «Saldo») registra un abono (método Otro, nota «Importado desde Excel»). Factura existente = misma empresa + número: se actualiza solo con lo que venga con valor y los abonos solo se agregan (nunca se borran ni reducen). Lógica en `app/cartera_importar.php`.

## Empresas por Excel (v1.10.0)
- En *Empresas*: botón **Excel** (exporta el listado, respeta el filtro Propias/Clientes) y **Importar Excel** (permiso *crear*): `?p=empresas_importar`, flujo subir → vista previa (nuevas / actualizar / omitir / error, descarga de filas con error) → confirmar, igual que la importación de equipos. Columnas: Razón social, NIT, Tipo (Cliente/Propia), Contacto, Teléfono, Correo, Dirección, Estado (Activa/Inactiva). Reconoce las existentes por NIT (ignora puntos y dígito de verificación) o por nombre; al actualizar, las celdas vacías no borran datos; una propia con facturas no puede pasar a cliente. Lógica en `app/empresas_importar.php`. Sin permiso *editar* las existentes solo se omiten.

## Descargar ítems en Excel (v1.9.2)
- Mientras la cotización está en **Borrador**, el detalle muestra «Ítems en Excel» (`?p=cotizacion&id=N&xlsx=1`): descarga Referencia, Descripción, Marca, Unidad, Cant, Valor unitario y Valor total (con columna Oferta si la propuesta tiene dos). El archivo se puede volver a importar tal cual. Si la cotización ya no es borrador la descarga se rechaza. Sin la extensión zip de PHP (XAMPP local) baja como CSV.

## Reordenar ítems (v1.9.1)
- En la tabla de ítems de cada oferta hay una casilla con la posición (escribir un número mueve el ítem allí), flechas subir/bajar y una agarradera para arrastrar. El orden de las filas es el orden que se guarda (`cotizacion_items.orden`) y el que sale en el PDF. Los ítems no pasan de una oferta a otra.

## Importar ítems desde archivo (v1.9.0)
- En el formulario de cotización (ambos formatos y cada oferta) el botón «Importar ítems desde archivo» sube un .xlsx o .csv con Referencia, Descripción, Marca, Unidad, Cant y Valor unitario. El endpoint JSON está en `cotizacion_form.php` (`accion=importar_items`) y la lectura en `cot_importar_items()` (reconoce encabezados en cualquier orden; sin encabezados usa el orden de la plantilla; máx. 500 filas; avisa de filas omitidas). Modo «agregar al final» o «reemplazar». Plantilla: `?p=cotizacion_form&plantilla=1`. Leer .xlsx requiere la extensión zip de PHP (en el XAMPP local no está: allí solo funciona CSV). En la propuesta, marca y referencia se imprimen en una línea gris bajo la descripción.

## Texto con formato (v1.8.0)
- «2. Alcances de la propuesta» usa un editor `contenteditable` (barra: tipo de letra Arial/Times/Courier, tamaño, negrilla, viñetas, numeración, quitar formato). `app/richtext.php` reduce el HTML del navegador a un subconjunto seguro (`cot_rich_normalizar`, se guarda en `cotizaciones.alcances`; el texto plano antiguo sigue funcionando), lo muestra (`cot_rich_html`) y lo dibuja en el PDF justificado (`cot_rich_pdf`). `SimplePdf` ahora incluye Times y Courier (`textF`, `widthF`; las medidas de Times son tablas AFM aproximadas).
- Para dar formato a otro campo (p. ej. Descripción) basta repetir el bloque del editor y usar `cot_rich_pdf` en el PDF.

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
- **Sincronizado el 2026-10-25:** producción estaba en v1.13.0 (cambios hechos fuera de este equipo y sin pasar por git); se trajeron al proyecto local los 9 archivos que diferían (todos adiciones sobre la v1.12.1). Local y producción son idénticos salvo `app/config.php`.
- **Pendiente tras el despliegue v1.4.0 (hacerlo como administrador en producción):** configurar la cuenta de correo de la mesa de ayuda (*Correo y notificaciones*), la de cotizaciones, los datos de la empresa y el consecutivo inicial (*Configurar cotizaciones*), y crear en cPanel el Cron Job diario `php /home/aqreqjec/maval.solucioneseys.co/cron.php`.
- Al desplegar una versión nueva: subir los archivos modificados (nunca `app/config.php`), entrar como administrador (la BD se migra sola) y hacer antes copia de la BD.
- Ideas pendientes: permisos por usuario (hoy son por rol), restringir el acceso por IP, reportes de cotizaciones por cliente.
- `INSTALACION.md` tiene la guía de instalación en cPanel (el archivo tiene problemas de codificación al leerlo desde PowerShell; está en UTF-8).
- La contraseña SFTP y la de la BD de producción fueron manejadas en esta sesión; considerar rotarlas.
