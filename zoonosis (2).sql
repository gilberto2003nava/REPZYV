-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 27-01-2026 a las 02:17:49
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `zoonosis`
--

DELIMITER $$
--
-- Procedimientos
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_desactivar_accesos_expirados` ()   BEGIN
    UPDATE accesos_temporales
    SET activo = 0
    WHERE activo = 1 AND fecha_fin < NOW();
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_limpiar_bloqueos_expirados` ()   BEGIN
    UPDATE usuarios 
    SET bloqueado_hasta = NULL, intentos_fallidos = 0
    WHERE bloqueado_hasta IS NOT NULL AND bloqueado_hasta < NOW();
    
    UPDATE bloqueos_usuarios
    SET bloqueado_hasta = NULL
    WHERE bloqueado_hasta IS NOT NULL AND bloqueado_hasta < NOW();
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `accesos_temporales`
--

CREATE TABLE `accesos_temporales` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `fecha_inicio` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_fin` datetime NOT NULL,
  `motivo` text DEFAULT NULL,
  `otorgado_por` int(11) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_modificacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `accesos_temporales`
--

INSERT INTO `accesos_temporales` (`id`, `usuario_id`, `fecha_inicio`, `fecha_fin`, `motivo`, `otorgado_por`, `activo`, `fecha_creacion`, `fecha_modificacion`) VALUES
(1, 6, '2026-01-26 12:26:48', '2026-01-27 19:26:48', 'no', 4, 0, '2026-01-26 18:26:48', '2026-01-26 18:26:54'),
(2, 6, '2026-01-26 12:26:54', '2026-01-27 19:26:54', 'no', 4, 1, '2026-01-26 18:26:54', '2026-01-26 18:26:54');

--
-- Disparadores `accesos_temporales`
--
DELIMITER $$
CREATE TRIGGER `tr_accesos_temporales_insert` AFTER INSERT ON `accesos_temporales` FOR EACH ROW BEGIN
    INSERT INTO bitacora (usuario_id, accion, detalles, exito)
    VALUES (
        NEW.otorgado_por,
        'acceso_temporal_otorgado',
        CONCAT('Usuario: ', (SELECT nombre FROM usuarios WHERE id = NEW.usuario_id), 
               ' | Hasta: ', NEW.fecha_fin, 
               ' | Motivo: ', COALESCE(NEW.motivo, 'N/A')),
        1
    );
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `archivos`
--

CREATE TABLE `archivos` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `nombre_archivo` varchar(255) NOT NULL,
  `nombre_original` varchar(255) NOT NULL,
  `tipo` enum('expediente','oficio','cronograma') NOT NULL,
  `jurisdiccion` varchar(50) NOT NULL,
  `carpeta_id` int(11) DEFAULT NULL,
  `municipio` varchar(100) DEFAULT NULL,
  `fecha_subida` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `biologico_canino`
--

CREATE TABLE `biologico_canino` (
  `id` int(11) NOT NULL,
  `jurisdiccion` varchar(100) NOT NULL,
  `anio` int(11) NOT NULL,
  `mes` int(11) NOT NULL,
  `biologico` varchar(100) DEFAULT 'RABIFFA',
  `presentacion` varchar(100) DEFAULT 'FCO 20 DOSIS',
  `existencia_inicio_dosis` int(11) DEFAULT 0,
  `existencia_inicio_lote` varchar(50) DEFAULT '',
  `existencia_inicio_caducidad` date DEFAULT NULL,
  `dosis_recibidas` int(11) DEFAULT 0,
  `dosis_recibidas_lote` varchar(50) DEFAULT '',
  `dosis_recibidas_caducidad` date DEFAULT NULL,
  `total_dosis` int(11) DEFAULT 0,
  `dosis_administradas` int(11) DEFAULT 0,
  `desperdicio_dosis` int(11) DEFAULT 0,
  `existencia_fin_dosis` int(11) DEFAULT 0,
  `existencia_fin_lote` varchar(50) DEFAULT '',
  `existencia_fin_caducidad` date DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `fecha_registro` datetime DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `bitacora`
--

CREATE TABLE `bitacora` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `accion` varchar(255) NOT NULL,
  `detalles` text DEFAULT NULL,
  `exito` tinyint(1) DEFAULT 0,
  `fecha` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `bitacora`
--

INSERT INTO `bitacora` (`id`, `usuario_id`, `accion`, `detalles`, `exito`, `fecha`) VALUES
(286, 4, 'logout', 'Cierre de sesión', 1, '2026-01-21 20:04:32'),
(287, 4, 'logout', 'Cierre de sesión', 1, '2026-01-21 20:04:34'),
(288, 4, 'logout', 'Cierre de sesión', 1, '2026-01-21 20:04:37'),
(289, 4, 'logout', 'Cierre de sesión', 1, '2026-01-21 20:04:38'),
(290, 4, 'logout', 'Cierre de sesión', 1, '2026-01-21 20:04:38'),
(291, 4, 'logout', 'Cierre de sesión', 1, '2026-01-21 20:04:38'),
(292, 4, 'logout', 'Cierre de sesión', 1, '2026-01-21 20:06:00'),
(293, 4, 'logout', 'Cierre de sesión', 1, '2026-01-23 20:31:03'),
(294, 4, 'logout', 'Cierre de sesión', 1, '2026-01-23 20:31:05'),
(295, 4, 'logout', 'Cierre de sesión', 1, '2026-01-23 20:31:06'),
(296, 4, 'logout', 'Cierre de sesión', 1, '2026-01-23 20:31:06'),
(297, 4, 'logout', 'Cierre de sesión', 1, '2026-01-23 20:31:06'),
(298, 4, 'logout', 'Cierre de sesión', 1, '2026-01-23 20:31:07'),
(299, 4, 'logout', 'Cierre de sesión', 1, '2026-01-23 20:31:07'),
(300, 4, 'logout', 'Cierre de sesión', 1, '2026-01-23 20:31:08'),
(301, 4, 'logout', 'Cierre de sesión', 1, '2026-01-23 20:31:09'),
(302, 4, 'logout', 'Cierre de sesión', 1, '2026-01-23 20:31:10'),
(303, 4, 'logout', 'Cierre de sesión', 1, '2026-01-23 20:31:10'),
(304, 4, 'logout', 'Cierre de sesión', 1, '2026-01-23 20:31:29'),
(305, 4, 'logout', 'Cierre de sesión', 1, '2026-01-23 20:36:57'),
(306, 4, 'logout', 'Cierre de sesión', 1, '2026-01-23 20:37:31'),
(307, 4, 'logout', 'Cierre de sesión', 1, '2026-01-23 20:56:43'),
(308, 4, 'logout', 'Cierre de sesión', 1, '2026-01-24 16:17:24'),
(309, NULL, 'usuario_registro', 'Nuevo usuario: rafael salazar | rafa@gmail.com | Rol: capturista', 1, '2026-01-25 17:17:03'),
(310, 4, 'logout', 'Cierre de sesión', 1, '2026-01-25 17:25:42'),
(311, NULL, 'usuario_delete', 'Usuario eliminado: rafael salazar | rafa@gmail.com | ID: 5', 1, '2026-01-25 17:38:40'),
(312, 6, 'usuario_registro', 'Nuevo usuario: rafael salazar | rafa@gmail.com | Rol: capturista', 1, '2026-01-25 17:39:47'),
(313, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-25 17:42:16'),
(314, 6, 'terminos', 'Aceptó términos y condiciones', 1, '2026-01-25 17:42:16'),
(315, 6, 'logout', 'Cierre de sesión', 1, '2026-01-25 18:17:03'),
(316, 4, 'logout', 'Cierre de sesión', 1, '2026-01-25 18:29:05'),
(317, 6, 'logout', 'Cierre de sesión', 1, '2026-01-25 18:29:40'),
(318, 4, 'logout', 'Cierre de sesión', 1, '2026-01-25 18:33:47'),
(319, 6, 'logout', 'Cierre de sesión', 1, '2026-01-25 18:34:26'),
(320, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-25 20:05:51'),
(321, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-25 20:13:13'),
(322, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-25 20:20:20'),
(323, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-25 23:10:12'),
(324, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-26 00:13:28'),
(325, 4, 'logout', 'Cierre de sesión', 1, '2026-01-26 01:34:26'),
(326, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-26 01:36:23'),
(327, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-26 01:37:45'),
(328, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-26 01:39:31'),
(329, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-26 01:47:49'),
(330, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-26 01:54:02'),
(331, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-26 02:03:09'),
(332, 4, 'logout', 'Cierre de sesión', 1, '2026-01-26 02:29:43'),
(333, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-26 02:30:12'),
(334, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-26 02:30:37'),
(335, 6, 'logout', 'Cierre de sesión', 1, '2026-01-26 02:46:48'),
(336, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-26 02:47:30'),
(337, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-26 02:50:24'),
(338, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-26 02:50:55'),
(339, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-26 02:52:22'),
(340, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-26 03:04:54'),
(341, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-26 03:08:15'),
(342, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-26 03:10:21'),
(343, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-26 03:11:18'),
(344, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-26 03:15:41'),
(345, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-26 03:20:48'),
(346, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-26 03:34:59'),
(347, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-26 03:39:04'),
(348, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-26 03:44:27'),
(349, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-26 03:49:24'),
(350, 4, 'logout', 'Cierre de sesión', 1, '2026-01-26 05:45:54'),
(351, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-26 17:51:58'),
(352, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-26 18:03:06'),
(353, 4, 'crear_material', 'Insumo: 1, Jur: 2 IXTLAHUACA, Mes: 1', 1, '2026-01-26 18:25:01'),
(354, 4, 'acceso_temporal_otorgado', 'Usuario: rafael salazar | Hasta: 2026-01-27 19:26:48 | Motivo: no', 1, '2026-01-26 18:26:48'),
(355, 4, 'acceso_temporal_otorgado', 'Usuario: rafael salazar | Hasta: 2026-01-27 19:26:54 | Motivo: no', 1, '2026-01-26 18:26:54'),
(356, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-26 19:01:23'),
(357, 4, 'usuario_desactivado', 'Usuario: rafael salazar (rafa@gmail.com) | Motivo: ghy', 1, '2026-01-26 19:01:23'),
(358, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-26 19:48:04'),
(359, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-26 20:21:54'),
(360, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-26 20:43:02'),
(361, NULL, 'usuario_activado', 'Usuario: rafael salazar (rafa@gmail.com) | Motivo: N/A', 1, '2026-01-26 20:43:02'),
(362, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-26 21:36:44'),
(363, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-27 00:13:18'),
(364, 4, 'usuario_update', 'Usuario actualizado: Gilberto Velazquez Nava | gilbertovelazquez546@gmail.com', 1, '2026-01-27 00:22:13'),
(365, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:33:12'),
(366, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:37:25'),
(367, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:37:29'),
(368, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:37:32'),
(369, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:37:35'),
(370, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:37:35'),
(371, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:38:12'),
(372, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:40:38'),
(373, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:41:10'),
(374, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:46:33'),
(375, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:46:36'),
(376, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:46:38'),
(377, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:46:41'),
(378, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:46:43'),
(379, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:46:43'),
(380, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:47:51'),
(381, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:57:57'),
(382, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:58:02'),
(383, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:58:05'),
(384, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:58:07'),
(385, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:58:10'),
(386, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:58:10'),
(387, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:58:45'),
(388, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 00:59:25'),
(389, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 01:00:46'),
(390, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 01:04:10'),
(391, NULL, 'usuario_desactivado', 'Usuario: rafael salazar (rafa@gmail.com) | Motivo: N/A', 1, '2026-01-27 01:04:10'),
(392, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 01:04:38'),
(393, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 01:06:03'),
(394, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 01:13:08'),
(395, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 01:13:11'),
(396, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 01:13:16'),
(397, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 01:13:24'),
(398, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 01:13:28'),
(399, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 01:13:28'),
(400, 6, 'usuario_update', 'Usuario actualizado: rafael salazar | rafa@gmail.com', 1, '2026-01-27 01:13:41');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `bloqueos_usuarios`
--

CREATE TABLE `bloqueos_usuarios` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `intentos` int(11) NOT NULL DEFAULT 1,
  `bloqueado_hasta` datetime DEFAULT NULL,
  `fecha_primer_intento` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_ultimo_intento` datetime NOT NULL DEFAULT current_timestamp(),
  `desbloqueado_por` int(11) DEFAULT NULL,
  `fecha_desbloqueo` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Disparadores `bloqueos_usuarios`
--
DELIMITER $$
CREATE TRIGGER `tr_bloqueos_desbloqueo` AFTER UPDATE ON `bloqueos_usuarios` FOR EACH ROW BEGIN
    IF OLD.desbloqueado_por IS NULL AND NEW.desbloqueado_por IS NOT NULL THEN
        INSERT INTO bitacora (usuario_id, accion, detalles, exito)
        VALUES (
            NEW.desbloqueado_por,
            'usuario_desbloqueado',
            CONCAT('Email: ', NEW.email, ' | IP: ', NEW.ip_address),
            1
        );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `carpetas`
--

CREATE TABLE `carpetas` (
  `id` int(11) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `jurisdiccion` varchar(50) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `extensiones`
--

CREATE TABLE `extensiones` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `mes` int(11) NOT NULL,
  `anio` int(11) NOT NULL,
  `fecha_limite` datetime NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `insumos`
--

CREATE TABLE `insumos` (
  `id` int(11) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `presentacion` varchar(100) DEFAULT NULL,
  `categoria` enum('estatal','federal') DEFAULT 'estatal',
  `activo` tinyint(1) DEFAULT 1,
  `orden` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `insumos`
--

INSERT INTO `insumos` (`id`, `nombre`, `presentacion`, `categoria`, `activo`, `orden`, `created_at`) VALUES
(1, 'HIDROCLORURO DE XILACINA AL 2%', 'FRASCO C/25 ML', 'estatal', 1, 1, '2026-01-25 03:47:12'),
(5, 'ALUSPRAY', 'FRASCO C/210 ML', 'estatal', 1, 5, '2026-01-25 03:47:17');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `jurisdicciones_config`
--

CREATE TABLE `jurisdicciones_config` (
  `id` int(11) NOT NULL,
  `jurisdiccion` varchar(100) NOT NULL,
  `direccion` text DEFAULT NULL,
  `responsable_nombre` varchar(200) DEFAULT '',
  `responsable_cargo` varchar(200) DEFAULT 'M.V.Z.',
  `cedula_profesional` varchar(50) DEFAULT '',
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `material_registro`
--

CREATE TABLE `material_registro` (
  `id` int(11) NOT NULL,
  `insumo_id` int(11) NOT NULL,
  `jurisdiccion` varchar(50) NOT NULL,
  `anio` int(11) NOT NULL,
  `mes` tinyint(4) NOT NULL,
  `entregado` int(11) DEFAULT 0,
  `utilizado` int(11) DEFAULT 0,
  `remanente` int(11) DEFAULT 0,
  `usuario_id` int(11) DEFAULT NULL,
  `fecha_captura` datetime DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `material_registro`
--

INSERT INTO `material_registro` (`id`, `insumo_id`, `jurisdiccion`, `anio`, `mes`, `entregado`, `utilizado`, `remanente`, `usuario_id`, `fecha_captura`, `fecha_actualizacion`) VALUES
(1, 1, '2 IXTLAHUACA', 2026, 1, 5, 0, 5, 4, '2026-01-26 12:25:01', '2026-01-26 12:25:01');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `mensajes`
--

CREATE TABLE `mensajes` (
  `id` int(11) NOT NULL,
  `remitente_id` int(11) NOT NULL,
  `destinatario_id` int(11) DEFAULT NULL,
  `asunto` varchar(255) NOT NULL,
  `mensaje` text NOT NULL,
  `tipo` enum('general','solicitud_apertura') NOT NULL DEFAULT 'general',
  `referencia_mes` int(11) DEFAULT NULL,
  `referencia_anio` int(11) DEFAULT NULL,
  `leido` tinyint(1) DEFAULT 0,
  `fecha_envio` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `metas`
--

CREATE TABLE `metas` (
  `id` int(11) NOT NULL,
  `jurisdiccion` varchar(50) NOT NULL,
  `municipio` varchar(100) NOT NULL,
  `tipo` enum('vacunados','esterilizados','control','muestras','agredidos','tratamientos') NOT NULL,
  `anio` int(11) NOT NULL,
  `meta` int(11) NOT NULL,
  `creada_por` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Disparadores `metas`
--
DELIMITER $$
CREATE TRIGGER `tr_metas_delete` AFTER DELETE ON `metas` FOR EACH ROW BEGIN
    INSERT INTO bitacora (usuario_id, accion, detalles, exito)
    VALUES (
        OLD.creada_por,
        'meta_delete',
        CONCAT(OLD.jurisdiccion, '|', OLD.municipio, '|', OLD.tipo, '|', OLD.anio),
        1
    );
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `tr_metas_insert` AFTER INSERT ON `metas` FOR EACH ROW BEGIN
    INSERT INTO bitacora (usuario_id, accion, detalles, exito)
    VALUES (
        NEW.creada_por,
        'meta_create',
        CONCAT(NEW.jurisdiccion, '|', NEW.municipio, '|', NEW.tipo, '|', NEW.anio, '|meta:', NEW.meta),
        1
    );
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `tr_metas_update` AFTER UPDATE ON `metas` FOR EACH ROW BEGIN
    INSERT INTO bitacora (usuario_id, accion, detalles, exito)
    VALUES (
        NEW.creada_por,
        'meta_update',
        CONCAT(NEW.jurisdiccion, '|', NEW.municipio, '|', NEW.tipo, '|', NEW.anio, '|meta:', OLD.meta, '->', NEW.meta),
        1
    );
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `observaciones_biologico`
--

CREATE TABLE `observaciones_biologico` (
  `id` int(11) NOT NULL,
  `anio` int(11) NOT NULL,
  `mes` int(11) NOT NULL,
  `observaciones` text DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `fecha_registro` datetime DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `recetas_biologico`
--

CREATE TABLE `recetas_biologico` (
  `id` int(11) NOT NULL,
  `lote_id` varchar(50) DEFAULT NULL,
  `jurisdiccion` varchar(100) NOT NULL,
  `folio` int(11) NOT NULL,
  `folio_formateado` varchar(10) NOT NULL,
  `producto` varchar(300) DEFAULT '',
  `tipo_insumo` varchar(50) DEFAULT 'GENERAL',
  `cantidad_frascos` int(11) NOT NULL DEFAULT 20,
  `fecha_emision` date NOT NULL,
  `anio` int(11) NOT NULL,
  `mes` int(11) NOT NULL,
  `receptor_nombre` varchar(200) DEFAULT '',
  `receptor_cedula` varchar(50) DEFAULT '',
  `direccion` text DEFAULT NULL,
  `receptor_cargo` varchar(200) DEFAULT 'M.V.Z.',
  `usuario_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `recetas_biologico`
--

INSERT INTO `recetas_biologico` (`id`, `lote_id`, `jurisdiccion`, `folio`, `folio_formateado`, `producto`, `tipo_insumo`, `cantidad_frascos`, `fecha_emision`, `anio`, `mes`, `receptor_nombre`, `receptor_cedula`, `direccion`, `receptor_cargo`, `usuario_id`, `created_at`) VALUES
(7, 'LOTE_7', '4 TENANGO DEL VALLE', 1, '1', 'CLOHIDRATO DE XILACINA AL 2%, (XILASYN 2) FRASCO CON 20 ml', 'XILACINA', 20, '2026-01-20', 2026, 0, 'CAROLA ARROYO MAZA', '7118616', 'Boulevard Narciso Bassols s/n, Tenango del Valle, México, C.P. 52160', 'M.V.Z.', 4, '2026-01-19 15:07:59'),
(8, 'LOTE_8', '12 NAUCALPAN', 2, '2', 'CLOHIDRATO DE XILACINA AL 2%, (XILASYN 2) FRASCO CON 20 ml', 'XILACINA', 20, '2026-01-20', 2026, 0, 'SERGIO REYES GARCIA', '3705470', 'Av. Corona No. 100, Naucalpan, México, C.P. 53410', 'M.V.Z.', 4, '2026-01-19 15:12:41'),
(9, 'LOTE_8', '12 NAUCALPAN', 3, '3', 'CLOHIDRATO DE XILACINA AL 2%, (XILASYN 2) FRASCO CON 20 ml', 'XILACINA', 15, '2026-01-20', 2026, 0, 'SERGIO REYES GARCIA', '3705470', 'Av. Corona No. 100, Naucalpan, México, C.P. 53410', 'M.V.Z.', 4, '2026-01-19 15:12:41'),
(13, 'LOTE_13', '4 TENANGO DEL VALLE', 1, '1', 'TILETAMINA ZOLAZEPAN AL 10%, (ZELAZOL) FRASCO CON 5 ml', 'ZOLETIL', 20, '2026-01-20', 2026, 0, 'CAROLA ARROYO MAZA', '7118616', 'Boulevard Narciso Bassols s/n, Tenango del Valle, México, C.P. 52160', 'M.V.Z.', 4, '2026-01-19 15:31:23'),
(14, 'LOTE_13', '4 TENANGO DEL VALLE', 2, '2', 'TILETAMINA ZOLAZEPAN AL 10%, (ZELAZOL) FRASCO CON 5 ml', 'ZOLETIL', 20, '2026-01-20', 2026, 0, 'CAROLA ARROYO MAZA', '7118616', 'Boulevard Narciso Bassols s/n, Tenango del Valle, México, C.P. 52160', 'M.V.Z.', 4, '2026-01-19 15:31:23'),
(15, 'LOTE_15', '18 NEZAHUALCÓYOTL', 4, '4', 'CLOHIDRATO DE XILACINA AL 2%, (XILASYN 2) FRASCO CON 20 ml', 'XILACINA', 20, '2026-01-20', 2026, 0, 'ARGELIO FRANCISCO POOT POOT', '785414', 'Av. Rivapalacios No. 25, Col. Pavón, Nezahualcóyotl, México, C.P. 55295', 'M.V.Z.', 4, '2026-01-19 15:36:51'),
(16, 'LOTE_15', '18 NEZAHUALCÓYOTL', 5, '5', 'CLOHIDRATO DE XILACINA AL 2%, (XILASYN 2) FRASCO CON 20 ml', 'XILACINA', 20, '2026-01-20', 2026, 0, 'ARGELIO FRANCISCO POOT POOT', '785414', 'Av. Rivapalacios No. 25, Col. Pavón, Nezahualcóyotl, México, C.P. 55295', 'M.V.Z.', 4, '2026-01-19 15:36:51'),
(17, 'LOTE_15', '18 NEZAHUALCÓYOTL', 6, '6', 'CLOHIDRATO DE XILACINA AL 2%, (XILASYN 2) FRASCO CON 20 ml', 'XILACINA', 5, '2026-01-20', 2026, 0, 'ARGELIO FRANCISCO POOT POOT', '785414', 'Av. Rivapalacios No. 25, Col. Pavón, Nezahualcóyotl, México, C.P. 55295', 'M.V.Z.', 4, '2026-01-19 15:36:51'),
(18, 'LOTE_18', '12 NAUCALPAN', 3, '3', 'TILETAMINA ZOLAZEPAN AL 10%, (ZELAZOL) FRASCO CON 5 ml', 'ZOLETIL', 20, '2026-01-20', 2026, 0, 'SERGIO REYES GARCIA', '3705470', 'Av. Corona No. 100, Naucalpan, México, C.P. 53410', 'M.V.Z.', 4, '2026-01-19 15:42:08'),
(19, 'LOTE_18', '12 NAUCALPAN', 4, '4', 'TILETAMINA ZOLAZEPAN AL 10%, (ZELAZOL) FRASCO CON 5 ml', 'ZOLETIL', 20, '2026-01-20', 2026, 0, 'SERGIO REYES GARCIA', '3705470', 'Av. Corona No. 100, Naucalpan, México, C.P. 53410', 'M.V.Z.', 4, '2026-01-19 15:42:08'),
(20, 'LOTE_18', '12 NAUCALPAN', 5, '5', 'TILETAMINA ZOLAZEPAN AL 10%, (ZELAZOL) FRASCO CON 5 ml', 'ZOLETIL', 20, '2026-01-20', 2026, 0, 'SERGIO REYES GARCIA', '3705470', 'Av. Corona No. 100, Naucalpan, México, C.P. 53410', 'M.V.Z.', 4, '2026-01-19 15:42:08'),
(21, 'LOTE_18', '12 NAUCALPAN', 6, '6', 'TILETAMINA ZOLAZEPAN AL 10%, (ZELAZOL) FRASCO CON 5 ml', 'ZOLETIL', 10, '2026-01-20', 2026, 0, 'SERGIO REYES GARCIA', '3705470', 'Av. Corona No. 100, Naucalpan, México, C.P. 53410', 'M.V.Z.', 4, '2026-01-19 15:42:08'),
(22, 'LOTE_22', '18 NEZAHUALCÓYOTL', 7, '7', 'TILETAMINA ZOLAZEPAN AL 10%(ZELAZOL) FRASCO CON 5 ml', 'ZOLETIL', 20, '2026-01-30', 2026, 0, 'ARGELIO FRANCISCO POOT POOT', '785414', 'Av. Rivapalacios No. 25, Col. Pavón, Nezahualcóyotl, México, C.P. 55295', 'M.V.Z.', 4, '2026-01-19 15:45:43'),
(23, 'LOTE_22', '18 NEZAHUALCÓYOTL', 8, '8', 'TILETAMINA ZOLAZEPAN AL 10%(ZELAZOL) FRASCO CON 5 ml', 'ZOLETIL', 20, '2026-01-30', 2026, 0, 'ARGELIO FRANCISCO POOT POOT', '785414', 'Av. Rivapalacios No. 25, Col. Pavón, Nezahualcóyotl, México, C.P. 55295', 'M.V.Z.', 4, '2026-01-19 15:45:43'),
(24, 'LOTE_22', '18 NEZAHUALCÓYOTL', 9, '9', 'TILETAMINA ZOLAZEPAN AL 10%(ZELAZOL) FRASCO CON 5 ml', 'ZOLETIL', 20, '2026-01-30', 2026, 0, 'ARGELIO FRANCISCO POOT POOT', '785414', 'Av. Rivapalacios No. 25, Col. Pavón, Nezahualcóyotl, México, C.P. 55295', 'M.V.Z.', 4, '2026-01-19 15:45:43'),
(25, 'LOTE_22', '18 NEZAHUALCÓYOTL', 10, '10', 'TILETAMINA ZOLAZEPAN AL 10%(ZELAZOL) FRASCO CON 5 ml', 'ZOLETIL', 20, '2026-01-30', 2026, 0, 'ARGELIO FRANCISCO POOT POOT', '785414', 'Av. Rivapalacios No. 25, Col. Pavón, Nezahualcóyotl, México, C.P. 55295', 'M.V.Z.', 4, '2026-01-19 15:45:43'),
(26, 'LOTE_22', '18 NEZAHUALCÓYOTL', 11, '11', 'TILETAMINA ZOLAZEPAN AL 10%(ZELAZOL) FRASCO CON 5 ml', 'ZOLETIL', 10, '2026-01-30', 2026, 0, 'ARGELIO FRANCISCO POOT POOT', '785414', 'Av. Rivapalacios No. 25, Col. Pavón, Nezahualcóyotl, México, C.P. 55295', 'M.V.Z.', 4, '2026-01-19 15:45:43');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `recetas_control_anios`
--

CREATE TABLE `recetas_control_anios` (
  `id` int(11) NOT NULL,
  `anio` int(11) NOT NULL,
  `ultimo_folio` int(11) NOT NULL DEFAULT 0,
  `fecha_inicio` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `registros`
--

CREATE TABLE `registros` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `anio` int(11) NOT NULL,
  `jurisdiccion` varchar(50) NOT NULL,
  `municipio` varchar(100) NOT NULL,
  `tipo` enum('vacunados','esterilizados','control','muestras','agredidos','tratamientos') NOT NULL,
  `meta_anual` int(11) NOT NULL,
  `ene` int(11) DEFAULT 0,
  `feb` int(11) DEFAULT 0,
  `mar` int(11) DEFAULT 0,
  `abr` int(11) DEFAULT 0,
  `may` int(11) DEFAULT 0,
  `jun` int(11) DEFAULT 0,
  `jul` int(11) DEFAULT 0,
  `ago` int(11) DEFAULT 0,
  `sep` int(11) DEFAULT 0,
  `oct` int(11) DEFAULT 0,
  `nov` int(11) DEFAULT 0,
  `dic` int(11) DEFAULT 0,
  `editable` tinyint(1) DEFAULT 1,
  `observaciones` text DEFAULT NULL,
  `fecha_captura` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Disparadores `registros`
--
DELIMITER $$
CREATE TRIGGER `tr_registros_delete` AFTER DELETE ON `registros` FOR EACH ROW BEGIN
    INSERT INTO bitacora (usuario_id, accion, detalles, exito)
    VALUES (
        OLD.usuario_id,
        'captura_delete',
        CONCAT(OLD.jurisdiccion, '|', OLD.municipio, '|', OLD.tipo, '|', OLD.anio),
        1
    );
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `tr_registros_insert` AFTER INSERT ON `registros` FOR EACH ROW BEGIN
    INSERT INTO bitacora (usuario_id, accion, detalles, exito)
    VALUES (
        NEW.usuario_id,
        'captura',
        CONCAT(NEW.jurisdiccion, '|', NEW.municipio, '|', NEW.tipo, '|', NEW.anio),
        1
    );
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `tr_registros_update` AFTER UPDATE ON `registros` FOR EACH ROW BEGIN
    INSERT INTO bitacora (usuario_id, accion, detalles, exito)
    VALUES (
        NEW.usuario_id,
        'captura_update',
        CONCAT(NEW.jurisdiccion, '|', NEW.municipio, '|', NEW.tipo, '|', NEW.anio),
        1
    );
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_registros_insert_bitacora` AFTER INSERT ON `registros` FOR EACH ROW BEGIN
  INSERT INTO bitacora (usuario_id, accion, detalles, exito, fecha)
  VALUES (NEW.usuario_id, 'captura', CONCAT(NEW.jurisdiccion,'|',NEW.municipio,'|',NEW.tipo,'|',NEW.anio), 1, NOW());
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_registros_update_bitacora` AFTER UPDATE ON `registros` FOR EACH ROW BEGIN
  INSERT INTO bitacora (usuario_id, accion, detalles, exito, fecha)
  VALUES (NEW.usuario_id, 'captura_update', CONCAT(NEW.jurisdiccion,'|',NEW.municipio,'|',NEW.tipo,'|',NEW.anio), 1, NOW());
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `restablecimientos`
--

CREATE TABLE `restablecimientos` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `codigo` varchar(6) NOT NULL,
  `expiracion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `usado` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `token_qr` varchar(64) DEFAULT NULL,
  `ip_solicitud` varchar(45) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `restablecimientos`
--

INSERT INTO `restablecimientos` (`id`, `usuario_id`, `email`, `codigo`, `expiracion`, `usado`, `created_at`, `token_qr`, `ip_solicitud`, `telefono`) VALUES
(1, 4, 'gilbertovelazquez546@gmail.com', '948592', '2026-01-26 00:42:21', 0, '2026-01-25 17:27:21', NULL, NULL, NULL),
(2, 4, 'gilbertovelazquez546@gmail.com', '308928', '2026-01-26 00:45:20', 0, '2026-01-25 17:30:20', NULL, NULL, NULL),
(3, 4, 'gilbertovelazquez546@gmail.com', '906908', '2026-01-26 21:31:19', 0, '2026-01-26 14:16:19', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `rol` enum('capturista','admin') NOT NULL,
  `jurisdiccion` varchar(50) DEFAULT 'TODAS',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `telefono` varchar(20) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `fecha_baja` datetime DEFAULT NULL,
  `motivo_baja` text DEFAULT NULL,
  `dado_baja_por` int(11) DEFAULT NULL,
  `ultimo_acceso` datetime DEFAULT NULL,
  `intentos_fallidos` int(11) NOT NULL DEFAULT 0,
  `bloqueado_hasta` datetime DEFAULT NULL,
  `terminos_aceptados` tinyint(1) DEFAULT 0,
  `fecha_terminos` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`id`, `nombre`, `email`, `password`, `rol`, `jurisdiccion`, `created_at`, `telefono`, `activo`, `fecha_baja`, `motivo_baja`, `dado_baja_por`, `ultimo_acceso`, `intentos_fallidos`, `bloqueado_hasta`, `terminos_aceptados`, `fecha_terminos`) VALUES
(4, 'Gilberto Velazquez Nava', 'gilbertovelazquez546@gmail.com', '$2y$10$3K7bEwH8ifDiyc8oqBB/D.i5w9klizRl939tFUYe8x2Gp1Z/GqSUm', 'admin', 'TODAS', '2025-11-18 20:11:04', '7221725762', 1, NULL, NULL, NULL, NULL, 0, NULL, 1, '2026-01-11 12:29:52'),
(6, 'rafael salazar', 'rafa@gmail.com', '$2y$10$sFs3Ik5sI6m5RYZIna6YGeWP0XeRXd/HCDxhoNkfSLrh8WyfVhlsi', 'capturista', '2 IXTLAHUACA', '2026-01-25 17:39:47', NULL, 0, NULL, NULL, NULL, NULL, 0, NULL, 1, '2026-01-25 11:42:16');

--
-- Disparadores `usuarios`
--
DELIMITER $$
CREATE TRIGGER `tr_usuarios_cambio_estado` AFTER UPDATE ON `usuarios` FOR EACH ROW BEGIN
    IF OLD.activo != NEW.activo THEN
        INSERT INTO bitacora (usuario_id, accion, detalles, exito)
        VALUES (
            NEW.dado_baja_por,
            IF(NEW.activo = 1, 'usuario_activado', 'usuario_desactivado'),
            CONCAT('Usuario: ', NEW.nombre, ' (', NEW.email, ') | Motivo: ', COALESCE(NEW.motivo_baja, 'N/A')),
            1
        );
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `tr_usuarios_delete` BEFORE DELETE ON `usuarios` FOR EACH ROW BEGIN
    INSERT INTO bitacora (usuario_id, accion, detalles, exito)
    VALUES (
        NULL,
        'usuario_delete',
        CONCAT('Usuario eliminado: ', OLD.nombre, ' | ', OLD.email, ' | ID: ', OLD.id),
        1
    );
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `tr_usuarios_insert` AFTER INSERT ON `usuarios` FOR EACH ROW BEGIN
    INSERT INTO bitacora (usuario_id, accion, detalles, exito)
    VALUES (
        NEW.id,
        'usuario_registro',
        CONCAT('Nuevo usuario: ', NEW.nombre, ' | ', NEW.email, ' | Rol: ', NEW.rol),
        1
    );
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `tr_usuarios_update` AFTER UPDATE ON `usuarios` FOR EACH ROW BEGIN
    INSERT INTO bitacora (usuario_id, accion, detalles, exito)
    VALUES (
        NEW.id,
        'usuario_update',
        CONCAT('Usuario actualizado: ', NEW.nombre, ' | ', NEW.email),
        1
    );
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `v_usuarios_completo`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `v_usuarios_completo` (
`id` int(11)
,`nombre` varchar(100)
,`email` varchar(100)
,`rol` enum('capturista','admin')
,`jurisdiccion` varchar(50)
,`telefono` varchar(20)
,`activo` tinyint(1)
,`fecha_baja` datetime
,`motivo_baja` text
,`ultimo_acceso` datetime
,`intentos_fallidos` int(11)
,`bloqueado_hasta` datetime
,`created_at` timestamp
,`estado` varchar(15)
,`total_bloqueos` bigint(21)
,`total_accesos_temporales` bigint(21)
,`acceso_temporal_hasta` datetime
);

-- --------------------------------------------------------

--
-- Estructura para la vista `v_usuarios_completo`
--
DROP TABLE IF EXISTS `v_usuarios_completo`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_usuarios_completo`  AS SELECT `u`.`id` AS `id`, `u`.`nombre` AS `nombre`, `u`.`email` AS `email`, `u`.`rol` AS `rol`, `u`.`jurisdiccion` AS `jurisdiccion`, `u`.`telefono` AS `telefono`, `u`.`activo` AS `activo`, `u`.`fecha_baja` AS `fecha_baja`, `u`.`motivo_baja` AS `motivo_baja`, `u`.`ultimo_acceso` AS `ultimo_acceso`, `u`.`intentos_fallidos` AS `intentos_fallidos`, `u`.`bloqueado_hasta` AS `bloqueado_hasta`, `u`.`created_at` AS `created_at`, CASE WHEN `u`.`bloqueado_hasta` is not null AND `u`.`bloqueado_hasta` > current_timestamp() THEN 'BLOQUEADO' WHEN `u`.`activo` = 0 THEN 'INACTIVO' WHEN exists(select 1 from `accesos_temporales` `at` where `at`.`usuario_id` = `u`.`id` AND `at`.`activo` = 1 AND `at`.`fecha_fin` > current_timestamp() limit 1) THEN 'ACCESO_TEMPORAL' ELSE 'ACTIVO' END AS `estado`, (select count(0) from `bloqueos_usuarios` where `bloqueos_usuarios`.`usuario_id` = `u`.`id`) AS `total_bloqueos`, (select count(0) from `accesos_temporales` where `accesos_temporales`.`usuario_id` = `u`.`id`) AS `total_accesos_temporales`, (select `accesos_temporales`.`fecha_fin` from `accesos_temporales` where `accesos_temporales`.`usuario_id` = `u`.`id` and `accesos_temporales`.`activo` = 1 and `accesos_temporales`.`fecha_fin` > current_timestamp() order by `accesos_temporales`.`fecha_fin` desc limit 1) AS `acceso_temporal_hasta` FROM `usuarios` AS `u` ;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `accesos_temporales`
--
ALTER TABLE `accesos_temporales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_usuario_id` (`usuario_id`),
  ADD KEY `idx_fecha_fin` (`fecha_fin`),
  ADD KEY `idx_activo` (`activo`),
  ADD KEY `otorgado_por` (`otorgado_por`);

--
-- Indices de la tabla `archivos`
--
ALTER TABLE `archivos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `usuario_id` (`usuario_id`),
  ADD KEY `jurisdiccion` (`jurisdiccion`),
  ADD KEY `tipo` (`tipo`),
  ADD KEY `carpeta_id` (`carpeta_id`);

--
-- Indices de la tabla `biologico_canino`
--
ALTER TABLE `biologico_canino`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_registro` (`jurisdiccion`,`anio`,`mes`),
  ADD KEY `idx_anio_mes` (`anio`,`mes`),
  ADD KEY `idx_jurisdiccion` (`jurisdiccion`);

--
-- Indices de la tabla `bitacora`
--
ALTER TABLE `bitacora`
  ADD PRIMARY KEY (`id`),
  ADD KEY `bitacora_ibfk_1` (`usuario_id`);

--
-- Indices de la tabla `bloqueos_usuarios`
--
ALTER TABLE `bloqueos_usuarios`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_usuario_id` (`usuario_id`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_ip_address` (`ip_address`),
  ADD KEY `idx_bloqueado_hasta` (`bloqueado_hasta`),
  ADD KEY `desbloqueado_por` (`desbloqueado_por`);

--
-- Indices de la tabla `carpetas`
--
ALTER TABLE `carpetas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `jurisdiccion` (`jurisdiccion`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Indices de la tabla `extensiones`
--
ALTER TABLE `extensiones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Indices de la tabla `insumos`
--
ALTER TABLE `insumos`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `jurisdicciones_config`
--
ALTER TABLE `jurisdicciones_config`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `jurisdiccion` (`jurisdiccion`);

--
-- Indices de la tabla `material_registro`
--
ALTER TABLE `material_registro`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_registro` (`insumo_id`,`jurisdiccion`,`anio`,`mes`);

--
-- Indices de la tabla `mensajes`
--
ALTER TABLE `mensajes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `remitente_id` (`remitente_id`),
  ADD KEY `destinatario_id` (`destinatario_id`);

--
-- Indices de la tabla `metas`
--
ALTER TABLE `metas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_meta` (`jurisdiccion`,`municipio`,`tipo`,`anio`),
  ADD KEY `creada_por` (`creada_por`);

--
-- Indices de la tabla `observaciones_biologico`
--
ALTER TABLE `observaciones_biologico`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_obs` (`anio`,`mes`),
  ADD KEY `idx_anio_mes` (`anio`,`mes`);

--
-- Indices de la tabla `recetas_biologico`
--
ALTER TABLE `recetas_biologico`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_jurisdiccion` (`jurisdiccion`),
  ADD KEY `idx_anio_mes` (`anio`,`mes`),
  ADD KEY `idx_tipo_insumo` (`tipo_insumo`),
  ADD KEY `idx_anio_tipo` (`anio`,`tipo_insumo`),
  ADD KEY `idx_lote_id` (`lote_id`);

--
-- Indices de la tabla `recetas_control_anios`
--
ALTER TABLE `recetas_control_anios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `anio` (`anio`),
  ADD KEY `idx_anio` (`anio`);

--
-- Indices de la tabla `registros`
--
ALTER TABLE `registros`
  ADD PRIMARY KEY (`id`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Indices de la tabla `restablecimientos`
--
ALTER TABLE `restablecimientos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_token_qr` (`token_qr`),
  ADD KEY `idx_ip_solicitud` (`ip_solicitud`,`created_at`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_activo` (`activo`),
  ADD KEY `idx_bloqueado_hasta` (`bloqueado_hasta`),
  ADD KEY `idx_ultimo_acceso` (`ultimo_acceso`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `accesos_temporales`
--
ALTER TABLE `accesos_temporales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `archivos`
--
ALTER TABLE `archivos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `biologico_canino`
--
ALTER TABLE `biologico_canino`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `bitacora`
--
ALTER TABLE `bitacora`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=401;

--
-- AUTO_INCREMENT de la tabla `bloqueos_usuarios`
--
ALTER TABLE `bloqueos_usuarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `carpetas`
--
ALTER TABLE `carpetas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `extensiones`
--
ALTER TABLE `extensiones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `insumos`
--
ALTER TABLE `insumos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `jurisdicciones_config`
--
ALTER TABLE `jurisdicciones_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `material_registro`
--
ALTER TABLE `material_registro`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `mensajes`
--
ALTER TABLE `mensajes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `metas`
--
ALTER TABLE `metas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `observaciones_biologico`
--
ALTER TABLE `observaciones_biologico`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `recetas_biologico`
--
ALTER TABLE `recetas_biologico`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT de la tabla `recetas_control_anios`
--
ALTER TABLE `recetas_control_anios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `registros`
--
ALTER TABLE `registros`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3420;

--
-- AUTO_INCREMENT de la tabla `restablecimientos`
--
ALTER TABLE `restablecimientos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `accesos_temporales`
--
ALTER TABLE `accesos_temporales`
  ADD CONSTRAINT `accesos_temporales_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `accesos_temporales_ibfk_2` FOREIGN KEY (`otorgado_por`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `archivos`
--
ALTER TABLE `archivos`
  ADD CONSTRAINT `archivos_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  ADD CONSTRAINT `archivos_ibfk_2` FOREIGN KEY (`carpeta_id`) REFERENCES `carpetas` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `bitacora`
--
ALTER TABLE `bitacora`
  ADD CONSTRAINT `bitacora_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Filtros para la tabla `bloqueos_usuarios`
--
ALTER TABLE `bloqueos_usuarios`
  ADD CONSTRAINT `bloqueos_usuarios_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bloqueos_usuarios_ibfk_2` FOREIGN KEY (`desbloqueado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `carpetas`
--
ALTER TABLE `carpetas`
  ADD CONSTRAINT `carpetas_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`);

--
-- Filtros para la tabla `extensiones`
--
ALTER TABLE `extensiones`
  ADD CONSTRAINT `extensiones_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `mensajes`
--
ALTER TABLE `mensajes`
  ADD CONSTRAINT `mensajes_ibfk_1` FOREIGN KEY (`remitente_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `mensajes_ibfk_2` FOREIGN KEY (`destinatario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `metas`
--
ALTER TABLE `metas`
  ADD CONSTRAINT `metas_ibfk_1` FOREIGN KEY (`creada_por`) REFERENCES `usuarios` (`id`);

--
-- Filtros para la tabla `registros`
--
ALTER TABLE `registros`
  ADD CONSTRAINT `registros_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`);

--
-- Filtros para la tabla `restablecimientos`
--
ALTER TABLE `restablecimientos`
  ADD CONSTRAINT `restablecimientos_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
