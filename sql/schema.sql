-- MAVAL - Esquema de base de datos (MySQL 5.7+ / MariaDB 10.3+)

CREATE TABLE IF NOT EXISTS empresas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL,
  nit VARCHAR(30) NULL,
  tipo VARCHAR(10) NOT NULL DEFAULT 'cliente',
  telefono VARCHAR(40) NULL,
  email VARCHAR(150) NULL,
  direccion VARCHAR(200) NULL,
  contacto VARCHAR(120) NULL,
  activo TINYINT NOT NULL DEFAULT 1,
  creado DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuarios (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  email VARCHAR(150) NOT NULL,
  password VARCHAR(255) NOT NULL,
  rol VARCHAR(10) NOT NULL DEFAULT 'usuario',
  rol_id INT UNSIGNED NULL,
  empresa_id INT UNSIGNED NULL,
  cargo VARCHAR(100) NULL,
  telefono VARCHAR(40) NULL,
  activo TINYINT NOT NULL DEFAULT 1,
  ultimo_acceso DATETIME NULL,
  creado DATETIME NULL,
  UNIQUE KEY uq_usuarios_email (email),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_intentos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(150) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  fecha DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS equipos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(40) NOT NULL,
  tipo VARCHAR(40) NOT NULL,
  marca VARCHAR(80) NULL,
  modelo VARCHAR(120) NULL,
  serial VARCHAR(120) NULL,
  empresa_id INT UNSIGNED NULL,
  usuario_id INT UNSIGNED NULL,
  responsable VARCHAR(120) NULL,
  cargo VARCHAR(100) NULL,
  correos VARCHAR(500) NULL,
  ubicacion VARCHAR(120) NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'Activo',
  fecha_compra DATE NULL,
  valor DECIMAL(15,2) NULL,
  garantia_hasta DATE NULL,
  imei VARCHAR(40) NULL,
  linea VARCHAR(40) NULL,
  ip VARCHAR(45) NULL,
  procesador VARCHAR(120) NULL,
  ram VARCHAR(40) NULL,
  disco VARCHAR(80) NULL,
  renovacion VARCHAR(10) NULL,
  btu INT NULL,
  tipo_aire VARCHAR(30) NULL,
  tecnologia VARCHAR(12) NULL,
  refrigerante VARCHAR(12) NULL,
  voltaje VARCHAR(20) NULL,
  serial_exterior VARCHAR(120) NULL,
  especificaciones TEXT NULL,
  notas TEXT NULL,
  creado DATETIME NULL,
  actualizado DATETIME NULL,
  UNIQUE KEY uq_equipos_codigo (codigo),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS equipo_software (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  equipo_id INT UNSIGNED NOT NULL,
  categoria VARCHAR(12) NOT NULL,
  nombre VARCHAR(150) NOT NULL,
  version VARCHAR(60) NULL,
  tipo_licencia VARCHAR(40) NULL,
  licencia VARCHAR(10) NOT NULL DEFAULT 'Legal',
  vence DATE NULL,
  FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS equipo_movimientos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  equipo_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NULL,
  descripcion VARCHAR(500) NOT NULL,
  fecha DATETIME NOT NULL,
  FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tickets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(20) NULL,
  asunto VARCHAR(200) NOT NULL,
  descripcion TEXT NOT NULL,
  categoria VARCHAR(40) NOT NULL,
  prioridad VARCHAR(10) NOT NULL DEFAULT 'Media',
  estado VARCHAR(20) NOT NULL DEFAULT 'Abierto',
  solicitante_id INT UNSIGNED NULL,
  empresa_id INT UNSIGNED NULL,
  equipo_id INT UNSIGNED NULL,
  asignado_id INT UNSIGNED NULL,
  creado DATETIME NOT NULL,
  actualizado DATETIME NULL,
  cerrado DATETIME NULL,
  FOREIGN KEY (solicitante_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL,
  FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE SET NULL,
  FOREIGN KEY (asignado_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_comentarios (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NULL,
  mensaje TEXT NOT NULL,
  interno TINYINT NOT NULL DEFAULT 0,
  creado DATETIME NOT NULL,
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adjuntos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  entidad VARCHAR(20) NOT NULL,
  entidad_id INT UNSIGNED NOT NULL,
  nombre_original VARCHAR(255) NOT NULL,
  archivo VARCHAR(100) NOT NULL,
  tamano INT UNSIGNED NOT NULL DEFAULT 0,
  subido_por INT UNSIGNED NULL,
  creado DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gasto_categorias (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL,
  activo TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gastos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL,
  empresa_id INT UNSIGNED NULL,
  categoria_id INT UNSIGNED NOT NULL,
  proveedor VARCHAR(150) NULL,
  concepto VARCHAR(255) NOT NULL,
  valor DECIMAL(15,2) NOT NULL,
  metodo_pago VARCHAR(40) NULL,
  referencia VARCHAR(80) NULL,
  notas TEXT NULL,
  creado_por INT UNSIGNED NULL,
  creado DATETIME NULL,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL,
  FOREIGN KEY (categoria_id) REFERENCES gasto_categorias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presupuestos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  anio SMALLINT NOT NULL,
  mes TINYINT NOT NULL,
  categoria_id INT UNSIGNED NOT NULL,
  valor DECIMAL(15,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_presupuesto (anio, mes, categoria_id),
  FOREIGN KEY (categoria_id) REFERENCES gasto_categorias(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS facturas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  numero VARCHAR(40) NOT NULL,
  cliente_nombre VARCHAR(150) NOT NULL,
  cliente_nit VARCHAR(30) NULL,
  fecha_emision DATE NOT NULL,
  fecha_vencimiento DATE NOT NULL,
  concepto VARCHAR(255) NULL,
  subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
  iva DECIMAL(15,2) NOT NULL DEFAULT 0,
  retenciones DECIMAL(15,2) NOT NULL DEFAULT 0,
  total DECIMAL(15,2) NOT NULL DEFAULT 0,
  anulada TINYINT NOT NULL DEFAULT 0,
  notas TEXT NULL,
  creado_por INT UNSIGNED NULL,
  creado DATETIME NULL,
  UNIQUE KEY uq_factura_numero (empresa_id, numero),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pagos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  factura_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  valor DECIMAL(15,2) NOT NULL,
  metodo VARCHAR(40) NULL,
  referencia VARCHAR(80) NULL,
  notas VARCHAR(255) NULL,
  creado_por INT UNSIGNED NULL,
  creado DATETIME NULL,
  FOREIGN KEY (factura_id) REFERENCES facturas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_meta (
  clave VARCHAR(40) NOT NULL PRIMARY KEY,
  valor VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v3: roles y permisos (los roles Administrador, Usuario y Empresa los crea la aplicación al iniciar)
CREATE TABLE IF NOT EXISTS roles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(10) NULL,
  nombre VARCHAR(60) NOT NULL,
  descripcion VARCHAR(255) NULL,
  alcance VARCHAR(10) NOT NULL DEFAULT 'propios',
  sistema TINYINT NOT NULL DEFAULT 0,
  creado DATETIME NULL,
  UNIQUE KEY uq_roles_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rol_permisos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  rol_id INT UNSIGNED NOT NULL,
  modulo VARCHAR(30) NOT NULL,
  accion VARCHAR(15) NOT NULL,
  UNIQUE KEY uq_rol_permiso (rol_id, modulo, accion),
  FOREIGN KEY (rol_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v3: ajustes (correo, notificaciones) y registro de correos enviados
CREATE TABLE IF NOT EXISTS ajustes (
  clave VARCHAR(40) NOT NULL PRIMARY KEY,
  valor TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS correo_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  fecha DATETIME NOT NULL,
  destinatarios VARCHAR(500) NOT NULL,
  asunto VARCHAR(255) NOT NULL,
  ok TINYINT NOT NULL DEFAULT 0,
  error VARCHAR(500) NULL,
  motivo VARCHAR(30) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v3: planeador de mantenimiento preventivo
CREATE TABLE IF NOT EXISTS mantenimientos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  equipo_id INT UNSIGNED NOT NULL,
  fecha_programada DATE NOT NULL,
  estado VARCHAR(12) NOT NULL DEFAULT 'Programado',
  periodicidad_meses SMALLINT NOT NULL DEFAULT 0,
  aviso_dias SMALLINT NOT NULL DEFAULT 7,
  tecnico_id INT UNSIGNED NULL,
  actividades VARCHAR(500) NULL,
  observaciones TEXT NULL,
  fecha_realizada DATE NULL,
  programacion_enviada DATETIME NULL,
  aviso_enviado DATETIME NULL,
  pendiente_enviado DATETIME NULL,
  creado_por INT UNSIGNED NULL,
  creado DATETIME NULL,
  actualizado DATETIME NULL,
  FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v4: cotizaciones
CREATE TABLE IF NOT EXISTS cotizaciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  consecutivo INT UNSIGNED NOT NULL,
  formato VARCHAR(10) NOT NULL DEFAULT 'items',
  fecha DATE NOT NULL,
  ciudad VARCHAR(80) NULL,
  empresa_id INT UNSIGNED NULL,
  cliente_nombre VARCHAR(150) NOT NULL,
  cliente_nit VARCHAR(30) NULL,
  cliente_direccion VARCHAR(200) NULL,
  cliente_telefono VARCHAR(40) NULL,
  cliente_email VARCHAR(150) NULL,
  atencion VARCHAR(120) NULL,
  descripcion TEXT NULL,
  alcances TEXT NULL,
  subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
  mano_obra DECIMAL(15,2) NOT NULL DEFAULT 0,
  iva_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  iva DECIMAL(15,2) NOT NULL DEFAULT 0,
  total DECIMAL(15,2) NOT NULL DEFAULT 0,
  validez VARCHAR(100) NULL,
  tiempo_entrega VARCHAR(100) NULL,
  forma_pago VARCHAR(150) NULL,
  notas TEXT NULL,
  estado VARCHAR(10) NOT NULL DEFAULT 'Borrador',
  enviada DATETIME NULL,
  enviada_a VARCHAR(500) NULL,
  creado_por INT UNSIGNED NULL,
  creado DATETIME NULL,
  actualizado DATETIME NULL,
  UNIQUE KEY uq_cot_consecutivo (consecutivo),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cotizacion_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cotizacion_id INT UNSIGNED NOT NULL,
  orden SMALLINT NOT NULL DEFAULT 0,
  referencia VARCHAR(60) NULL,
  descripcion VARCHAR(500) NOT NULL,
  marca VARCHAR(60) NULL,
  unidad VARCHAR(20) NULL,
  cantidad DECIMAL(12,2) NOT NULL DEFAULT 1,
  valor_unitario DECIMAL(15,2) NOT NULL DEFAULT 0,
  valor_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
