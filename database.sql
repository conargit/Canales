-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Servidor: localhost
-- Tiempo de generación: 26-05-2026 a las 01:49:46
-- Versión del servidor: 5.7.44-log
-- Versión de PHP: 8.3.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

--
-- Base de datos: `whatsapp`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ajustes`
--

CREATE TABLE `ajustes` (
  `clave` varchar(255) NOT NULL,
  `valor` text,
  `id_sucursal` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Volcado de datos para la tabla `ajustes`
--

INSERT INTO `ajustes` (`clave`, `valor`, `id_sucursal`) VALUES
('modo_respuesta', 'auto', 1),
('modo_sistema', 'activo', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `campanas`
--

CREATE TABLE `campanas` (
  `id` int(11) NOT NULL,
  `id_instancia` int(11) DEFAULT NULL,
  `nombre` varchar(255) DEFAULT NULL,
  `mensaje` text,
  `destinatarios` text,
  `estado` varchar(50) NOT NULL DEFAULT 'pendiente',
  `fecha` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `chats`
--

CREATE TABLE `chats` (
  `id` int(11) NOT NULL,
  `id_instancia` int(11) DEFAULT NULL,
  `remitente` varchar(50) DEFAULT NULL,
  `mensaje` text,
  `respuesta` text,
  `modo` varchar(20) NOT NULL DEFAULT 'auto',
  `id_usuario_asignado` int(11) DEFAULT NULL,
  `fecha` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `instancias_wa`
--

CREATE TABLE `instancias_wa` (
  `id` int(11) NOT NULL,
  `id_sucursal` int(11) DEFAULT NULL,
  `nombre_identificador` varchar(255) DEFAULT NULL,
  `instance_name` varchar(255) DEFAULT NULL,
  `gateway_url` varchar(255) DEFAULT NULL,
  `api_key` varchar(255) DEFAULT NULL,
  `webhook_token` varchar(255) DEFAULT NULL,
  `estado` varchar(50) NOT NULL DEFAULT 'desconectado',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `memoria`
--

CREATE TABLE `memoria` (
  `id` int(11) NOT NULL,
  `id_sucursal` int(11) DEFAULT NULL,
  `tipo` varchar(50) DEFAULT NULL,
  `fuente` varchar(255) DEFAULT NULL,
  `contenido` longtext,
  `fecha` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `nombre` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Volcado de datos para la tabla `roles`
--

INSERT INTO `roles` (`id`, `nombre`) VALUES
(1, 'Admin'),
(2, 'Operador');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `sucursales`
--

CREATE TABLE `sucursales` (
  `id` int(11) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Volcado de datos para la tabla `sucursales`
--

INSERT INTO `sucursales` (`id`, `nombre`, `activo`, `created_at`) VALUES
(1, 'Empresa Principal', 1, '2026-05-26 04:46:05');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `id_rol` int(11) DEFAULT NULL,
  `id_sucursal` int(11) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`id`, `nombre`, `password`, `id_rol`, `id_sucursal`, `activo`, `created_at`) VALUES
(1, 'admin', '$2y$10$aOvK2n0w012p//JfdXJ9we3iUWnai8dix4up27lMNuucuxI.RVcYW', 1, 1, 1, '2026-05-26 04:48:35');

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `ajustes`
--
ALTER TABLE `ajustes`
  ADD PRIMARY KEY (`clave`),
  ADD KEY `idx_id_sucursal` (`id_sucursal`);

--
-- Indices de la tabla `campanas`
--
ALTER TABLE `campanas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_id_instancia` (`id_instancia`);

--
-- Indices de la tabla `chats`
--
ALTER TABLE `chats`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_id_instancia` (`id_instancia`),
  ADD KEY `idx_id_usuario_asignado` (`id_usuario_asignado`);

--
-- Indices de la tabla `instancias_wa`
--
ALTER TABLE `instancias_wa`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_id_sucursal` (`id_sucursal`);

--
-- Indices de la tabla `memoria`
--
ALTER TABLE `memoria`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_id_sucursal` (`id_sucursal`);

--
-- Indices de la tabla `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `sucursales`
--
ALTER TABLE `sucursales`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`),
  ADD KEY `idx_id_rol` (`id_rol`),
  ADD KEY `idx_id_sucursal` (`id_sucursal`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `campanas`
--
ALTER TABLE `campanas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `chats`
--
ALTER TABLE `chats`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `instancias_wa`
--
ALTER TABLE `instancias_wa`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `memoria`
--
ALTER TABLE `memoria`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `sucursales`
--
ALTER TABLE `sucursales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `ajustes`
--
ALTER TABLE `ajustes`
  ADD CONSTRAINT `fk_ajustes_sucursales` FOREIGN KEY (`id_sucursal`) REFERENCES `sucursales` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Filtros para la tabla `campanas`
--
ALTER TABLE `campanas`
  ADD CONSTRAINT `fk_campanas_instancias` FOREIGN KEY (`id_instancia`) REFERENCES `instancias_wa` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Filtros para la tabla `chats`
--
ALTER TABLE `chats`
  ADD CONSTRAINT `fk_chats_instancias` FOREIGN KEY (`id_instancia`) REFERENCES `instancias_wa` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_chats_usuarios` FOREIGN KEY (`id_usuario_asignado`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Filtros para la tabla `instancias_wa`
--
ALTER TABLE `instancias_wa`
  ADD CONSTRAINT `fk_instancias_sucursales` FOREIGN KEY (`id_sucursal`) REFERENCES `sucursales` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Filtros para la tabla `memoria`
--
ALTER TABLE `memoria`
  ADD CONSTRAINT `fk_memoria_sucursales` FOREIGN KEY (`id_sucursal`) REFERENCES `sucursales` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Filtros para la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD CONSTRAINT `fk_usuarios_roles` FOREIGN KEY (`id_rol`) REFERENCES `roles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_usuarios_sucursales` FOREIGN KEY (`id_sucursal`) REFERENCES `sucursales` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;
