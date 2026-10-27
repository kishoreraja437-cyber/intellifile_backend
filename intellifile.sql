-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 27, 2026
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `intellifile`
--

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `email`, `password`, `profile_image`, `created_at`) VALUES
(1, 'Arul', 'arul072004@gmail.com', 'Arul123', 'uploads/9b436445fdd83e6ba5a5983c56d2550f.webp', '2026-10-26 04:18:21'),
(3, 'Arul', 'arul07200@gmail.com', '$2y$10$TBbWhhZER0Dgwr8DvPh8YOghUsd5Crmdxg716qOOMxpXlBSZFrh8.', 'uploads/5b027ffce9a1799624694221f6e0b681.jpg', '2026-10-26 04:18:21'),
(5, 'Kishore', 'kishore@gmail.com', '$2y$10$/3SukE96rTzeUc0KM/mwW.NstvHL4e2.EZUbDL/4EOCgGZsWMJUbm', 'uploads/2bd9904edbb4d8f1c7d6275fb10a10c5.jpg', '2026-10-26 04:18:21'),
(7, 'kishoreee', 'kishoreeee@gmail.com', '$2y$10$2oT7.9oxnsKCD5rtiomS3.0HTmwcqbet4OvGo4mZQy10ezInQOOT6', 'uploads/a4079dae5ce26500e64c25efe1af605d.jpg', '2026-10-26 04:18:21');

-- --------------------------------------------------------

--
-- Table structure for table `user_settings`
--

CREATE TABLE IF NOT EXISTS `user_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `theme_mode` enum('LIGHT','DARK','SYSTEM') NOT NULL DEFAULT 'SYSTEM',
  `important_alerts` tinyint(1) NOT NULL DEFAULT 1,
  `deadline_reminders` tinyint(1) NOT NULL DEFAULT 1,
  `new_doc_alerts` tinyint(1) NOT NULL DEFAULT 1,
  `quiet_hours` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id_unique` (`user_id`),
  CONSTRAINT `fk_user_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping initial settings for default users
--

INSERT INTO `user_settings` (`user_id`, `theme_mode`, `important_alerts`, `deadline_reminders`, `new_doc_alerts`, `quiet_hours`) VALUES
(1, 'SYSTEM', 1, 1, 1, 0),
(3, 'LIGHT', 1, 1, 1, 0),
(5, 'LIGHT', 1, 1, 1, 0),
(7, 'DARK', 1, 1, 1, 0)
ON DUPLICATE KEY UPDATE `theme_mode` = VALUES(`theme_mode`);

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
