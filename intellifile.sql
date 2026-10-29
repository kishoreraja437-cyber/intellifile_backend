-- phpMyAdmin SQL Dump
-- Database: `intellifile`
-- Generated for IntelliFile Document Management & AI Search App

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------
-- Table structure for table `users`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `auth_token` varchar(128) DEFAULT NULL,
  `token_expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_auth_token` (`auth_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `user_settings`
-- --------------------------------------------------------

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

-- --------------------------------------------------------
-- Table structure for table `documents`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `format` varchar(20) NOT NULL DEFAULT 'PDF',
  `file_size` varchar(50) NOT NULL DEFAULT '1.0 MB',
  `file_path` varchar(500) DEFAULT NULL,
  `category` varchar(100) NOT NULL DEFAULT 'General',
  `location` varchar(100) NOT NULL DEFAULT 'Internal Storage',
  `priority` enum('IMPORTANT','NORMAL','LOW') NOT NULL DEFAULT 'NORMAL',
  `is_bookmarked` tinyint(1) NOT NULL DEFAULT 0,
  `is_saved` tinyint(1) NOT NULL DEFAULT 0,
  `total_pages` int(11) NOT NULL DEFAULT 1,
  `content_text` longtext DEFAULT NULL,
  `last_accessed` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_documents_user` (`user_id`),
  KEY `idx_documents_priority` (`priority`),
  KEY `idx_documents_format` (`format`),
  KEY `idx_documents_saved` (`is_saved`),
  FULLTEXT KEY `ft_doc_search` (`title`, `content_text`),
  CONSTRAINT `fk_documents_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `document_shares`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `document_shares` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_id` int(11) NOT NULL,
  `shared_by_user_id` int(11) NOT NULL,
  `recipient_name` varchar(100) DEFAULT NULL,
  `recipient_email` varchar(150) DEFAULT NULL,
  `permission` enum('View Only','Can Edit','Full Access') NOT NULL DEFAULT 'View Only',
  `share_token` varchar(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `share_token` (`share_token`),
  KEY `idx_share_doc` (`document_id`),
  KEY `idx_share_user` (`shared_by_user_id`),
  CONSTRAINT `fk_share_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_share_user` FOREIGN KEY (`shared_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `ai_conversations`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `ai_conversations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `snippet` text DEFAULT NULL,
  `category` enum('ALL','ANALYSIS','SEARCH','GENERAL') NOT NULL DEFAULT 'GENERAL',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ai_conv_user` (`user_id`),
  KEY `idx_ai_conv_cat` (`category`),
  CONSTRAINT `fk_ai_conv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `ai_chat_messages`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `ai_chat_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conversation_id` int(11) NOT NULL,
  `sender` enum('USER','AI') NOT NULL,
  `message_text` longtext NOT NULL,
  `related_files_json` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ai_msg_conv` (`conversation_id`),
  CONSTRAINT `fk_ai_msg_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `feedbacks`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `feedbacks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `rating` int(11) NOT NULL DEFAULT 5,
  `category` enum('BUG','IDEA','EXPERIENCE') NOT NULL DEFAULT 'EXPERIENCE',
  `feedback_text` text NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_feedback_user` (`user_id`),
  CONSTRAINT `fk_feedback_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `support_messages`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `support_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `subject` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `status` enum('PENDING','IN_PROGRESS','RESOLVED') NOT NULL DEFAULT 'PENDING',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_support_user` (`user_id`),
  CONSTRAINT `fk_support_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Seed Data for Default Testing User (Kishore id=5 & Arul id=1)
-- --------------------------------------------------------

INSERT INTO `documents` (`id`, `user_id`, `title`, `format`, `file_size`, `category`, `location`, `priority`, `is_bookmarked`, `is_saved`, `total_pages`, `content_text`) VALUES
(1, 5, 'AML Assignment.pdf', 'PDF', '2.4 MB', 'Assignment', 'Internal Storage', 'IMPORTANT', 1, 1, 12, 'Advanced Machine Learning assignment detailing Convolutional Neural Networks, Deep Residual Networks, Loss functions, optimization algorithms like Adam and SGD, and gradient descent convergence proofs.'),
(2, 5, 'DBMS Notes.pdf', 'PDF', '1.8 MB', 'Notes', 'Internal Storage', 'NORMAL', 1, 1, 35, 'Database Management Systems revision guide covering Relational Algebra, B-Trees, Normalization 1NF to BCNF, ACID properties, Transaction Concurrency control, and SQL indexing strategies.'),
(3, 5, 'Project Report.pdf', 'PDF', '3.1 MB', 'Project', 'Internal Storage', 'LOW', 0, 1, 48, 'IntelliFile AI document assistant final semester project report. Outlines architecture, Jetpack Compose UI, PHP backend, MariaDB vector search emulation, full text inverted index and RAG pipeline.'),
(4, 5, 'Resume.pdf', 'PDF', '0.9 MB', 'Resume', 'Internal Storage', 'NORMAL', 1, 1, 2, 'Curriculum Vitae highlighting Android Jetpack Compose, Kotlin coroutines, PHP backend APIs, MySQL databases, AI integrations and academic projects.'),
(5, 5, 'Lab Record.pdf', 'DOC', '2.2 MB', 'Assignment', 'Internal Storage', 'NORMAL', 0, 1, 20, 'Data Structures and Algorithms laboratory exercises including Graph Traversals, Dijkstra shortest path, Minimum Spanning Tree Prim and Kruskal implementations.'),
(6, 5, 'Research Paper.pdf', 'PDF', '4.5 MB', 'Research', 'Internal Storage', 'IMPORTANT', 0, 0, 16, 'Empirical study on Retrieval-Augmented Generation for mobile document retrieval systems under low latency constraints.')
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

INSERT INTO `ai_conversations` (`id`, `user_id`, `title`, `snippet`, `category`) VALUES
(1, 5, 'Summarize this PDF', 'Here is a summary of the key points from the document Project Report.pdf...', 'ANALYSIS'),
(2, 5, 'Find important files', 'I found 5 important files related to assignment in your documents...', 'SEARCH'),
(3, 5, 'Explain this document', 'This document appears to be a project proposal for the AI-based document...', 'ANALYSIS'),
(4, 5, 'Compare two files', 'The main differences between the two files are in the content and file size...', 'ANALYSIS'),
(5, 5, 'Suggest file priorities', 'Based on your recent activity, here are the top 5 files you should prioritize...', 'GENERAL')
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

INSERT INTO `ai_chat_messages` (`conversation_id`, `sender`, `message_text`, `related_files_json`) VALUES
(1, 'USER', 'Summarize this PDF', NULL),
(1, 'AI', 'Here is a summary of the key points from the document Project Report.pdf: The project introduces IntelliFile, an intelligent document organizer with AI-powered search, automatic categorization, and local-first retrieval architecture.', '[{\"title\":\"Project Report.pdf\",\"format\":\"PDF\",\"size\":\"3.1 MB\",\"priority\":\"LOW\"}]'),
(2, 'USER', 'Find my AML assignment file', NULL),
(2, 'AI', 'I found 1 high-priority file matching your request: AML Assignment.pdf.', '[{\"title\":\"AML Assignment.pdf\",\"format\":\"PDF\",\"size\":\"2.4 MB\",\"priority\":\"IMPORTANT\"}]')
ON DUPLICATE KEY UPDATE `message_text` = VALUES(`message_text`);

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
