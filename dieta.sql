-- phpMyAdmin SQL Dump
-- version 4.9.0.1
-- https://www.phpmyadmin.net/
--
-- Servidor: sql207.infinityfree.com
-- Tiempo de generación: 08-06-2026 a las 11:33:16
-- Versión del servidor: 11.4.12-MariaDB
-- Versión de PHP: 7.2.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `if0_41999509_dieta`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `alimentos`
--

CREATE TABLE `alimentos` (
  `id` int(11) NOT NULL,
  `id_dietista` int(11) DEFAULT NULL,
  `nombre` varchar(150) NOT NULL,
  `marca` varchar(100) DEFAULT NULL,
  `racion_base_gr` decimal(6,2) DEFAULT 100.00,
  `kcal` decimal(6,2) NOT NULL,
  `proteinas` decimal(6,2) NOT NULL,
  `carbos` decimal(6,2) NOT NULL,
  `grasas` decimal(6,2) NOT NULL,
  `aprobado_global` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `archivos_boveda`
--

CREATE TABLE `archivos_boveda` (
  `id` int(11) NOT NULL,
  `id_cliente` int(11) NOT NULL,
  `tipo` enum('foto_frontal','foto_perfil','foto_espalda','analitica_pdf','informe_medico') NOT NULL,
  `archivo_url` varchar(255) NOT NULL,
  `fecha_subida` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `banners_sistema`
--

CREATE TABLE `banners_sistema` (
  `id` int(11) NOT NULL,
  `mensaje` text NOT NULL,
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `calendario_asignaciones`
--

CREATE TABLE `calendario_asignaciones` (
  `id` int(11) NOT NULL,
  `id_cliente` int(11) NOT NULL,
  `id_dieta` int(11) NOT NULL,
  `fecha_asignada` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `chats_mensajes`
--

CREATE TABLE `chats_mensajes` (
  `id` int(11) NOT NULL,
  `id_remitente` int(11) NOT NULL,
  `id_destinatario` int(11) NOT NULL,
  `mensaje` text DEFAULT NULL,
  `archivo_adjunto` varchar(255) DEFAULT NULL,
  `fecha_hora` timestamp NULL DEFAULT current_timestamp(),
  `leido` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `checkins_semanales`
--

CREATE TABLE `checkins_semanales` (
  `id` int(11) NOT NULL,
  `id_cliente` int(11) NOT NULL,
  `semana_inicio` date NOT NULL COMMENT 'Lunes de la semana',
  `hambre` tinyint(1) NOT NULL,
  `energia` tinyint(1) NOT NULL,
  `sueno` tinyint(1) NOT NULL,
  `cumplimiento_dieta` tinyint(1) NOT NULL,
  `animo` tinyint(1) NOT NULL,
  `observaciones` varchar(500) DEFAULT NULL,
  `fecha_registro` timestamp NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `comidas_bloques`
--

CREATE TABLE `comidas_bloques` (
  `id` int(11) NOT NULL,
  `id_dieta` int(11) NOT NULL,
  `nombre_bloque` varchar(50) NOT NULL,
  `orden` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `consultas`
--

CREATE TABLE `consultas` (
  `id` int(11) NOT NULL,
  `id_cliente` int(11) NOT NULL,
  `id_dietista` int(11) NOT NULL,
  `fecha` datetime NOT NULL,
  `duracion_min` int(11) DEFAULT 30,
  `tipo` enum('inicial','seguimiento','revision','rescate') DEFAULT 'seguimiento',
  `asistio` tinyint(1) DEFAULT 1,
  `notas_privadas` text DEFAULT NULL,
  `notas_compartidas` text DEFAULT NULL,
  `plan_siguiente` text DEFAULT NULL,
  `proxima_cita` date DEFAULT NULL,
  `fecha_creacion` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `dietas_base`
--

CREATE TABLE `dietas_base` (
  `id` int(11) NOT NULL,
  `id_dietista` int(11) NOT NULL,
  `id_cliente` int(11) DEFAULT NULL,
  `nombre` varchar(100) NOT NULL,
  `icono` varchar(20) DEFAULT '?️',
  `kcal_objetivo` decimal(8,2) NOT NULL,
  `prot_objetivo` decimal(6,2) NOT NULL,
  `carb_objetivo` decimal(6,2) NOT NULL,
  `grasas_objetivo` decimal(6,2) NOT NULL,
  `color` varchar(10) DEFAULT '#3b82f6',
  `estr_base` varchar(10) DEFAULT 'pond' COMMENT 'pond | entreno | descanso',
  `estr_deficit` int(11) DEFAULT -10 COMMENT 'porcentaje: -25..+15',
  `estr_estrategia_id` int(11) DEFAULT 0 COMMENT 'id de historial_estrategias, 0 = solo kcal'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `dieta_alimentos`
--

CREATE TABLE `dieta_alimentos` (
  `id` int(11) NOT NULL,
  `id_bloque` int(11) NOT NULL,
  `id_alimento` int(11) NOT NULL,
  `cantidad_gr` decimal(8,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `fichas_anamnesis`
--

CREATE TABLE `fichas_anamnesis` (
  `id_cliente` int(11) NOT NULL,
  `sexo` enum('Hombre','Mujer','Otro') NOT NULL,
  `fecha_nacimiento` date NOT NULL DEFAULT '2000-01-01',
  `altura_cm` decimal(5,2) NOT NULL DEFAULT 170.00,
  `factor_actividad` decimal(3,2) NOT NULL DEFAULT 1.40,
  `pasos_diarios` int(11) DEFAULT 7000,
  `dias_gym` tinyint(4) DEFAULT 3,
  `min_sesion` int(11) DEFAULT 60,
  `tipo_entreno` enum('fuerza','cardio','mixto','calistenia','otro') DEFAULT 'mixto',
  `tipo_trabajo` enum('sentado','de_pie','caminando','fisico_leve','fisico_intenso') DEFAULT 'sentado',
  `comidas_fav` text DEFAULT NULL,
  `alergias` text DEFAULT NULL,
  `comentarios` text DEFAULT NULL,
  `ultima_actualizacion` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `obj_kcal` int(11) DEFAULT 2000,
  `obj_p` decimal(5,1) DEFAULT 150.0,
  `obj_c` decimal(5,1) DEFAULT 200.0,
  `obj_g` decimal(5,1) DEFAULT 60.0,
  `factor_p` decimal(4,2) DEFAULT 2.20,
  `factor_g` decimal(4,2) DEFAULT 1.00,
  `fecha_estrategia` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `historial_estrategias`
--

CREATE TABLE `historial_estrategias` (
  `id` int(11) NOT NULL,
  `id_cliente` int(11) DEFAULT NULL,
  `id_dietista` int(11) DEFAULT NULL,
  `fecha` datetime DEFAULT current_timestamp(),
  `nombre` varchar(80) DEFAULT NULL,
  `kcal` int(11) DEFAULT NULL,
  `factor_p` decimal(4,2) DEFAULT NULL,
  `factor_g` decimal(4,2) DEFAULT NULL,
  `gramos_p` int(11) DEFAULT NULL,
  `gramos_c` int(11) DEFAULT NULL,
  `gramos_g` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `intercambios_swaps`
--

CREATE TABLE `intercambios_swaps` (
  `id` int(11) NOT NULL,
  `id_linea_alimento` int(11) NOT NULL,
  `id_alimento_swap` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `logs_admin`
--

CREATE TABLE `logs_admin` (
  `id` int(11) NOT NULL,
  `id_usuario_accion` int(11) NOT NULL,
  `accion` varchar(50) NOT NULL,
  `descripcion` text NOT NULL,
  `fecha_hora` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `medidas_corporales`
--

CREATE TABLE `medidas_corporales` (
  `id` int(11) NOT NULL,
  `id_cliente` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `cintura` decimal(5,1) DEFAULT NULL,
  `cadera` decimal(5,1) DEFAULT NULL,
  `pecho` decimal(5,1) DEFAULT NULL,
  `cuello` decimal(5,1) DEFAULT NULL,
  `hombros` decimal(5,1) DEFAULT NULL,
  `brazo_izq` decimal(5,1) DEFAULT NULL,
  `brazo_der` decimal(5,1) DEFAULT NULL,
  `muslo_izq` decimal(5,1) DEFAULT NULL,
  `muslo_der` decimal(5,1) DEFAULT NULL,
  `pantorrilla` decimal(5,1) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `fecha_registro` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `notas_dietista`
--

CREATE TABLE `notas_dietista` (
  `id` int(11) NOT NULL,
  `id_dietista` int(11) NOT NULL,
  `contenido` text DEFAULT NULL,
  `color` varchar(20) DEFAULT 'amarillo',
  `fecha_creacion` datetime DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `notificaciones`
--

CREATE TABLE `notificaciones` (
  `id` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `tipo` varchar(30) DEFAULT NULL COMMENT 'consulta|dieta|mensaje|objetivo|medida|checkin|otro',
  `texto` varchar(255) NOT NULL,
  `url` varchar(255) DEFAULT NULL,
  `leida` tinyint(1) NOT NULL DEFAULT 0,
  `fecha` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `objetivos`
--

CREATE TABLE `objetivos` (
  `id` int(11) NOT NULL,
  `id_cliente` int(11) NOT NULL,
  `id_dietista` int(11) DEFAULT NULL,
  `titulo` varchar(160) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `tipo` enum('peso','grasa','medida','custom') DEFAULT 'peso',
  `valor_inicial` decimal(7,2) DEFAULT NULL,
  `valor_objetivo` decimal(7,2) DEFAULT NULL,
  `unidad` varchar(20) DEFAULT NULL,
  `fecha_inicio` date NOT NULL,
  `fecha_limite` date DEFAULT NULL,
  `estado` enum('activo','completado','fallado','cancelado') DEFAULT 'activo',
  `fecha_completado` date DEFAULT NULL,
  `fecha_creacion` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `progresos_metricas`
--

CREATE TABLE `progresos_metricas` (
  `id` int(11) NOT NULL,
  `id_cliente` int(11) NOT NULL,
  `fecha_hora` timestamp NULL DEFAULT current_timestamp(),
  `peso_kg` decimal(5,2) NOT NULL,
  `foto_frontal` varchar(255) DEFAULT NULL,
  `notas_cliente` text DEFAULT NULL,
  `porcentaje_grasa` decimal(4,2) DEFAULT NULL,
  `pasos` int(11) DEFAULT NULL,
  `medidas_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `recetas`
--

CREATE TABLE `recetas` (
  `id` int(11) NOT NULL,
  `id_alimento` int(11) NOT NULL,
  `foto_url` varchar(255) DEFAULT NULL,
  `pasos` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `recordatorios_config`
--

CREATE TABLE `recordatorios_config` (
  `id` int(11) NOT NULL,
  `id_cliente` int(11) NOT NULL,
  `tipo` enum('pesaje','checkin','medidas','foto','agua','comidas') NOT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `hora` time DEFAULT '09:00:00',
  `dias_semana` varchar(15) DEFAULT '1,2,3,4,5,6,7',
  `ultima_notificacion` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tags_clientes`
--

CREATE TABLE `tags_clientes` (
  `id` int(11) NOT NULL,
  `id_dietista` int(11) NOT NULL,
  `id_cliente` int(11) NOT NULL,
  `nombre_tag` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL,
  `rol` varchar(20) NOT NULL COMMENT '''admin'', ''dietista'' o ''cliente''',
  `usuario` varchar(50) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `nombre_completo` varchar(150) NOT NULL,
  `foto_perfil` varchar(255) DEFAULT NULL,
  `id_dietista` int(11) DEFAULT NULL COMMENT 'FK: Dietista asignado al cliente',
  `fecha_registro` timestamp NULL DEFAULT current_timestamp(),
  `activo` tinyint(1) DEFAULT 1,
  `tema` varchar(10) NOT NULL DEFAULT 'light',
  `ultimo_login` datetime DEFAULT NULL,
  `ultima_actividad` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `alimentos`
--
ALTER TABLE `alimentos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_dietista` (`id_dietista`);

--
-- Indices de la tabla `archivos_boveda`
--
ALTER TABLE `archivos_boveda`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_cliente` (`id_cliente`);

--
-- Indices de la tabla `banners_sistema`
--
ALTER TABLE `banners_sistema`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `calendario_asignaciones`
--
ALTER TABLE `calendario_asignaciones`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cliente_fecha_dieta` (`id_cliente`,`fecha_asignada`,`id_dieta`),
  ADD KEY `id_dieta` (`id_dieta`);

--
-- Indices de la tabla `chats_mensajes`
--
ALTER TABLE `chats_mensajes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_remitente` (`id_remitente`),
  ADD KEY `id_destinatario` (`id_destinatario`);

--
-- Indices de la tabla `checkins_semanales`
--
ALTER TABLE `checkins_semanales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cliente_semana` (`id_cliente`,`semana_inicio`),
  ADD KEY `idx_cliente_fecha` (`id_cliente`,`semana_inicio`);

--
-- Indices de la tabla `comidas_bloques`
--
ALTER TABLE `comidas_bloques`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_dieta` (`id_dieta`);

--
-- Indices de la tabla `consultas`
--
ALTER TABLE `consultas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cliente` (`id_cliente`),
  ADD KEY `idx_dietista` (`id_dietista`);

--
-- Indices de la tabla `dietas_base`
--
ALTER TABLE `dietas_base`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_dietista` (`id_dietista`),
  ADD KEY `id_cliente` (`id_cliente`);

--
-- Indices de la tabla `dieta_alimentos`
--
ALTER TABLE `dieta_alimentos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_bloque` (`id_bloque`),
  ADD KEY `id_alimento` (`id_alimento`);

--
-- Indices de la tabla `fichas_anamnesis`
--
ALTER TABLE `fichas_anamnesis`
  ADD PRIMARY KEY (`id_cliente`);

--
-- Indices de la tabla `historial_estrategias`
--
ALTER TABLE `historial_estrategias`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `intercambios_swaps`
--
ALTER TABLE `intercambios_swaps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_linea_alimento` (`id_linea_alimento`),
  ADD KEY `id_alimento_swap` (`id_alimento_swap`);

--
-- Indices de la tabla `logs_admin`
--
ALTER TABLE `logs_admin`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_usuario_accion` (`id_usuario_accion`);

--
-- Indices de la tabla `medidas_corporales`
--
ALTER TABLE `medidas_corporales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cliente_fecha` (`id_cliente`,`fecha`);

--
-- Indices de la tabla `notas_dietista`
--
ALTER TABLE `notas_dietista`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_dietista` (`id_dietista`,`fecha_actualizacion`);

--
-- Indices de la tabla `notificaciones`
--
ALTER TABLE `notificaciones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_usuario_fecha` (`id_usuario`,`fecha`),
  ADD KEY `idx_usuario_leida` (`id_usuario`,`leida`);

--
-- Indices de la tabla `objetivos`
--
ALTER TABLE `objetivos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cliente` (`id_cliente`);

--
-- Indices de la tabla `recetas`
--
ALTER TABLE `recetas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_alimento` (`id_alimento`);

--
-- Indices de la tabla `recordatorios_config`
--
ALTER TABLE `recordatorios_config`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cliente_tipo` (`id_cliente`,`tipo`);

--
-- Indices de la tabla `tags_clientes`
--
ALTER TABLE `tags_clientes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_dietista` (`id_dietista`),
  ADD KEY `id_cliente` (`id_cliente`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `usuario_unique` (`usuario`),
  ADD UNIQUE KEY `email_unique` (`email`),
  ADD KEY `id_dietista` (`id_dietista`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `alimentos`
--
ALTER TABLE `alimentos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `archivos_boveda`
--
ALTER TABLE `archivos_boveda`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `banners_sistema`
--
ALTER TABLE `banners_sistema`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `calendario_asignaciones`
--
ALTER TABLE `calendario_asignaciones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `chats_mensajes`
--
ALTER TABLE `chats_mensajes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `checkins_semanales`
--
ALTER TABLE `checkins_semanales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `comidas_bloques`
--
ALTER TABLE `comidas_bloques`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `consultas`
--
ALTER TABLE `consultas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `dietas_base`
--
ALTER TABLE `dietas_base`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `dieta_alimentos`
--
ALTER TABLE `dieta_alimentos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `historial_estrategias`
--
ALTER TABLE `historial_estrategias`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `intercambios_swaps`
--
ALTER TABLE `intercambios_swaps`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `logs_admin`
--
ALTER TABLE `logs_admin`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `medidas_corporales`
--
ALTER TABLE `medidas_corporales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `notas_dietista`
--
ALTER TABLE `notas_dietista`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `notificaciones`
--
ALTER TABLE `notificaciones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `objetivos`
--
ALTER TABLE `objetivos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `progresos_metricas`
--
ALTER TABLE `progresos_metricas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `recetas`
--
ALTER TABLE `recetas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `recordatorios_config`
--
ALTER TABLE `recordatorios_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `tags_clientes`
--
ALTER TABLE `tags_clientes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `alimentos`
--
ALTER TABLE `alimentos`
  ADD CONSTRAINT `alimentos_ibfk_1` FOREIGN KEY (`id_dietista`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `archivos_boveda`
--
ALTER TABLE `archivos_boveda`
  ADD CONSTRAINT `archivos_boveda_ibfk_1` FOREIGN KEY (`id_cliente`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `calendario_asignaciones`
--
ALTER TABLE `calendario_asignaciones`
  ADD CONSTRAINT `calendario_asignaciones_ibfk_1` FOREIGN KEY (`id_cliente`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `calendario_asignaciones_ibfk_2` FOREIGN KEY (`id_dieta`) REFERENCES `dietas_base` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `chats_mensajes`
--
ALTER TABLE `chats_mensajes`
  ADD CONSTRAINT `chats_mensajes_ibfk_1` FOREIGN KEY (`id_remitente`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chats_mensajes_ibfk_2` FOREIGN KEY (`id_destinatario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `checkins_semanales`
--
ALTER TABLE `checkins_semanales`
  ADD CONSTRAINT `checkins_semanales_ibfk_1` FOREIGN KEY (`id_cliente`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `comidas_bloques`
--
ALTER TABLE `comidas_bloques`
  ADD CONSTRAINT `comidas_bloques_ibfk_1` FOREIGN KEY (`id_dieta`) REFERENCES `dietas_base` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `consultas`
--
ALTER TABLE `consultas`
  ADD CONSTRAINT `consultas_ibfk_1` FOREIGN KEY (`id_cliente`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `consultas_ibfk_2` FOREIGN KEY (`id_dietista`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `dietas_base`
--
ALTER TABLE `dietas_base`
  ADD CONSTRAINT `dietas_base_ibfk_1` FOREIGN KEY (`id_dietista`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `dietas_base_ibfk_2` FOREIGN KEY (`id_cliente`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `dieta_alimentos`
--
ALTER TABLE `dieta_alimentos`
  ADD CONSTRAINT `dieta_alimentos_ibfk_1` FOREIGN KEY (`id_bloque`) REFERENCES `comidas_bloques` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `dieta_alimentos_ibfk_2` FOREIGN KEY (`id_alimento`) REFERENCES `alimentos` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `fichas_anamnesis`
--
ALTER TABLE `fichas_anamnesis`
  ADD CONSTRAINT `fichas_anamnesis_ibfk_1` FOREIGN KEY (`id_cliente`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `intercambios_swaps`
--
ALTER TABLE `intercambios_swaps`
  ADD CONSTRAINT `intercambios_swaps_ibfk_1` FOREIGN KEY (`id_linea_alimento`) REFERENCES `dieta_alimentos` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `intercambios_swaps_ibfk_2` FOREIGN KEY (`id_alimento_swap`) REFERENCES `alimentos` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `logs_admin`
--
ALTER TABLE `logs_admin`
  ADD CONSTRAINT `logs_admin_ibfk_1` FOREIGN KEY (`id_usuario_accion`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `medidas_corporales`
--
ALTER TABLE `medidas_corporales`
  ADD CONSTRAINT `medidas_corporales_ibfk_1` FOREIGN KEY (`id_cliente`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `notificaciones`
--
ALTER TABLE `notificaciones`
  ADD CONSTRAINT `notificaciones_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `objetivos`
--
ALTER TABLE `objetivos`
  ADD CONSTRAINT `objetivos_ibfk_1` FOREIGN KEY (`id_cliente`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `recetas`
--
ALTER TABLE `recetas`
  ADD CONSTRAINT `recetas_ibfk_1` FOREIGN KEY (`id_alimento`) REFERENCES `alimentos` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `recordatorios_config`
--
ALTER TABLE `recordatorios_config`
  ADD CONSTRAINT `recordatorios_config_ibfk_1` FOREIGN KEY (`id_cliente`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `tags_clientes`
--
ALTER TABLE `tags_clientes`
  ADD CONSTRAINT `tags_clientes_ibfk_1` FOREIGN KEY (`id_dietista`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tags_clientes_ibfk_2` FOREIGN KEY (`id_cliente`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD CONSTRAINT `usuarios_ibfk_1` FOREIGN KEY (`id_dietista`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
