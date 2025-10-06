-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Oct 06, 2025 at 07:08 PM
-- Server version: 11.8.3-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u678631644_aimaidb`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`u678631644_aimai`@`127.0.0.1` PROCEDURE `UpdateAccountStatuses` ()   BEGIN
    -- Update temporary accounts to inactive after 7 days
    UPDATE users 
    SET status = 'inactive'
    WHERE status = 'temporary'
    AND created_at < NOW() - INTERVAL 7 DAY;
    
    UPDATE mentors 
    SET status = 'inactive'
    WHERE status = 'temporary'
    AND created_at < NOW() - INTERVAL 7 DAY;
    
    UPDATE companies 
    SET status = 'inactive'
    WHERE status = 'temporary'
    AND created_at < NOW() - INTERVAL 7 DAY;
    
    -- Update user status based on recent activity
    UPDATE users u
    JOIN (
        SELECT user_id, MAX(activity_date) AS last_active
        FROM (
            SELECT user_id, created_at AS activity_date FROM cvs
            UNION SELECT user_id, connection_date FROM company_connections
            UNION SELECT user_id, start_date FROM mentorships
            UNION SELECT user_id, last_updated FROM motivational_progress
            UNION SELECT user_id, interaction_date FROM user_vp_interactions
        ) AS activity
        GROUP BY user_id
    ) a ON u.user_id = a.user_id
    SET u.status = 
        CASE 
            WHEN last_active > NOW() - INTERVAL 6 MONTH THEN 'active'
            ELSE 'inactive'
        END
    WHERE u.status != 'temporary';
    
    -- Update mentor status based on mentorships
    UPDATE mentors m
    LEFT JOIN (
        SELECT mentor_id, MAX(start_date) AS last_mentorship
        FROM mentorships
        GROUP BY mentor_id
    ) ms ON m.mentor_id = ms.mentor_id
    SET m.status = 
        CASE 
            WHEN last_mentorship > NOW() - INTERVAL 6 MONTH THEN 'active'
            ELSE 'inactive'
        END
    WHERE m.status != 'temporary';
    
    -- FIXED: Company status based only on connections (since jobs table lacks company_id)
    UPDATE companies c
    LEFT JOIN (
        SELECT company_id, MAX(connection_date) AS last_activity
        FROM company_connections
        GROUP BY company_id
    ) ca ON c.company_id = ca.company_id
    SET c.status = 
        CASE 
            WHEN last_activity > NOW() - INTERVAL 6 MONTH THEN 'active'
            ELSE 'inactive'
        END
    WHERE c.status != 'temporary';
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Stand-in structure for view `account_status_overview`
-- (See below for the actual view)
--
CREATE TABLE `account_status_overview` (
`role` varchar(7)
,`id` int(11)
,`name` varchar(100)
,`status` varchar(9)
,`created_at` timestamp /* mariadb-5.3 */
);

-- --------------------------------------------------------

--
-- Table structure for table `achievements`
--

CREATE TABLE `achievements` (
  `achievement_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(50) DEFAULT NULL,
  `criteria` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`criteria`)),
  `points` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `achievements`
--

INSERT INTO `achievements` (`achievement_id`, `name`, `description`, `icon`, `criteria`, `points`) VALUES
(1, 'First Steps', 'Complete your first goal', 'fa-flag-checkered', '{\"goals_completed\": 1}', 10),
(2, 'Goal Getter', 'Complete 5 goals', 'fa-trophy', '{\"goals_completed\": 5}', 25),
(3, 'Goal Master', 'Complete 10 goals', 'fa-crown', '{\"goals_completed\": 10}', 50),
(4, 'Skill Collector', 'Acquire 3 skills', 'fa-graduation-cap', '{\"skills_acquired\": 3}', 20),
(5, 'Skill Expert', 'Reach expert level in any skill', 'fa-star', '{\"expert_skills\": 1}', 40),
(6, 'Networker', 'Connect with your first mentor', 'fa-handshake', '{\"mentors_connected\": 1}', 15),
(7, 'Project Builder', 'Complete your first project', 'fa-code', '{\"projects_completed\": 1}', 30),
(8, 'CV Creator', 'Generate your first CV', 'fa-file-alt', '{\"cvs_generated\": 1}', 20),
(9, 'Streak Starter', 'Maintain a 7-day activity streak', 'fa-fire', '{\"streak_days\": 7}', 35),
(10, 'Career Explorer', 'Explore 3 career paths', 'fa-compass', '{\"careers_explored\": 3}', 25);

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `activity_type` varchar(50) DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_log`
--

INSERT INTO `activity_log` (`log_id`, `user_id`, `activity_type`, `details`, `created_at`) VALUES
(1, 2, 'cv_generated', '{\"cv_id\": 3, \"timestamp\": \"2025-10-04 21:18:15\"}', '2025-10-04 21:18:15'),
(2, 2, 'goal_completed', '{\"goal\": \"reading\", \"progress_id\": 11}', '2025-10-04 21:37:57'),
(3, 2, 'skill_updated', '{\"skill_id\": 2, \"proficiency\": \"beginner\"}', '2025-10-04 21:50:14'),
(4, 1, 'career_exploration', '{\"paths_explored\": 3}', '2025-07-24 10:00:00'),
(5, 1, 'project_completed', '{\"project_id\": 2, \"title\": \"Task Management App\"}', '2025-07-15 16:30:00'),
(6, 10, 'roadmap_generated', '{\"path_id\": 2, \"career\": \"Data Scientist\"}', '2025-09-21 14:15:00'),
(7, 2, 'login', '{\"ip\": \"192.168.1.1\", \"device\": \"Chrome/Windows\"}', '2025-10-04 20:00:00'),
(8, 1, 'mentor_connected', '{\"mentor_id\": 3}', '2025-07-26 21:30:58');

-- --------------------------------------------------------

--
-- Table structure for table `ai_interactions`
--

CREATE TABLE `ai_interactions` (
  `interaction_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `agent_type` enum('career_explorer','skill_builder','cv_generator','mentor_matcher','job_matcher') DEFAULT NULL,
  `input_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`input_data`)),
  `output_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`output_data`)),
  `processing_time_ms` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ai_interactions`
--

INSERT INTO `ai_interactions` (`interaction_id`, `user_id`, `agent_type`, `input_data`, `output_data`, `processing_time_ms`, `created_at`) VALUES
(1, 2, 'cv_generator', '{\"user_id\": 2, \"include_projects\": true}', '{\"cv_id\": 3, \"sections\": 4}', 1250, '2025-10-04 21:18:15'),
(2, 1, 'career_explorer', '{\"personality_type\": \"INTJ\", \"skills\": [\"Python\", \"JavaScript\"]}', '{\"recommended_paths\": 3, \"top_match\": \"Full Stack Developer\"}', 850, '2025-07-24 10:00:00'),
(3, 10, 'skill_builder', '{\"target_career\": \"Data Scientist\", \"current_skills\": []}', '{\"milestones\": 5, \"estimated_weeks\": 72}', 980, '2025-09-21 14:15:00'),
(4, 1, 'job_matcher', '{\"user_id\": 1, \"skills\": [1, 2]}', '{\"matched_jobs\": 2, \"avg_match\": 85}', 650, '2025-07-25 09:30:00'),
(5, 2, 'mentor_matcher', '{\"personality\": \"ISFP\", \"specialization\": null}', '{\"recommended_mentors\": 1, \"top_score\": 78}', 720, '2025-07-26 21:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `auth_identity`
--

CREATE TABLE `auth_identity` (
  `userId` varchar(36) DEFAULT NULL,
  `providerId` varchar(64) NOT NULL,
  `providerType` varchar(32) NOT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `updatedAt` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `auth_provider_sync_history`
--

CREATE TABLE `auth_provider_sync_history` (
  `id` int(11) NOT NULL,
  `providerType` varchar(32) NOT NULL,
  `runMode` text NOT NULL,
  `status` text NOT NULL,
  `startedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `endedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `scanned` int(11) NOT NULL,
  `created` int(11) NOT NULL,
  `updated` int(11) NOT NULL,
  `disabled` int(11) NOT NULL,
  `error` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `career_paths`
--

CREATE TABLE `career_paths` (
  `path_id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `difficulty_level` enum('beginner','intermediate','advanced') DEFAULT NULL,
  `estimated_duration_months` int(11) DEFAULT NULL,
  `average_salary_min` decimal(10,2) DEFAULT NULL,
  `average_salary_max` decimal(10,2) DEFAULT NULL,
  `required_personality_types` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `career_paths`
--

INSERT INTO `career_paths` (`path_id`, `name`, `description`, `difficulty_level`, `estimated_duration_months`, `average_salary_min`, `average_salary_max`, `required_personality_types`, `created_at`) VALUES
(1, 'Full Stack Web Developer', 'Build complete web applications from front-end to back-end', 'intermediate', 12, 60000.00, 100000.00, 'INTJ,INTP,ENTJ,ENTP', '2025-10-04 22:02:53'),
(2, 'Data Scientist', 'Analyze data and build predictive models using machine learning', 'advanced', 18, 80000.00, 130000.00, 'INTJ,INTP,ISTJ', '2025-10-04 22:02:53'),
(3, 'UX/UI Designer', 'Design user-friendly interfaces and experiences', 'beginner', 9, 50000.00, 85000.00, 'INFP,INFJ,ENFP,ISFP', '2025-10-04 22:02:53'),
(4, 'DevOps Engineer', 'Automate and optimize software deployment pipelines', 'advanced', 15, 75000.00, 120000.00, 'INTJ,ISTJ,ESTJ', '2025-10-04 22:02:53'),
(5, 'Mobile App Developer', 'Create iOS and Android applications', 'intermediate', 10, 65000.00, 105000.00, 'INTP,ENTP,ISTP', '2025-10-04 22:02:53');

-- --------------------------------------------------------

--
-- Table structure for table `coaching_sessions`
--

CREATE TABLE `coaching_sessions` (
  `coaching_id` int(11) NOT NULL,
  `mentor_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `goal` text NOT NULL,
  `progress` text NOT NULL,
  `ai_advice` text NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `company_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `industry` varchar(100) DEFAULT NULL,
  `contact_email` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','inactive','temporary') NOT NULL DEFAULT 'temporary'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `companies`
--

INSERT INTO `companies` (`company_id`, `name`, `description`, `industry`, `contact_email`, `created_at`, `status`) VALUES
(1, 'TechCorp', 'Innovative tech solutions', 'Technology', 'hr@techcorp.com', '2025-07-04 08:39:22', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `company_connections`
--

CREATE TABLE `company_connections` (
  `connection_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `company_id` int(11) DEFAULT NULL,
  `connection_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(20) DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `company_connections`
--

INSERT INTO `company_connections` (`connection_id`, `user_id`, `company_id`, `connection_date`, `status`) VALUES
(1, 1, 1, '2025-07-24 23:39:00', 'pending'),
(2, 1, 1, '2025-07-12 00:00:00', 'pending');

-- --------------------------------------------------------

--
-- Table structure for table `company_offerings`
--

CREATE TABLE `company_offerings` (
  `offering_id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `type` enum('job','workshop','course','internship') DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `requirements` text DEFAULT NULL,
  `duration` varchar(100) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `deadline` date DEFAULT NULL,
  `is_paid` tinyint(1) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `max_participants` int(11) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `is_remote` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `company_offerings`
--

INSERT INTO `company_offerings` (`offering_id`, `company_id`, `type`, `title`, `description`, `requirements`, `duration`, `start_date`, `deadline`, `is_paid`, `amount`, `max_participants`, `location`, `is_remote`, `created_at`) VALUES
(1, 1, 'workshop', 'Introduction to React Development', 'Hands-on workshop covering React fundamentals', 'Basic JavaScript knowledge', '2 days', '2025-10-15', '2025-10-10', 1, 199.00, 20, 'San Francisco', 1, '2025-10-04 22:12:14'),
(2, 1, 'internship', 'Summer Software Engineering Internship', '12-week paid internship for students', 'Computer Science major, junior/senior standing', '12 weeks', '2025-06-01', '2025-03-31', 1, 6000.00, 5, 'San Francisco', 0, '2025-10-04 22:12:14'),
(3, 1, 'course', 'Full Stack Development Bootcamp', 'Comprehensive 16-week bootcamp', 'None - beginner friendly', '16 weeks', '2025-11-01', '2025-10-25', 1, 4999.00, 30, NULL, 1, '2025-10-04 22:12:14'),
(4, 1, 'workshop', 'Data Science with Python', 'Learn pandas, numpy, and visualization', 'Python basics', '3 days', '2025-10-20', '2025-10-15', 1, 299.00, 15, 'New York', 1, '2025-10-04 22:12:14');

-- --------------------------------------------------------

--
-- Table structure for table `credentials_entity`
--

CREATE TABLE `credentials_entity` (
  `id` int(11) NOT NULL,
  `name` varchar(128) NOT NULL,
  `data` text NOT NULL,
  `type` varchar(128) NOT NULL,
  `nodesAccess` longtext NOT NULL CHECK (json_valid(`nodesAccess`)),
  `createdAt` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updatedAt` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cvs`
--

CREATE TABLE `cvs` (
  `cv_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `job_id` int(11) DEFAULT NULL,
  `content` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cvs`
--

INSERT INTO `cvs` (`cv_id`, `user_id`, `job_id`, `content`, `created_at`) VALUES
(3, 2, NULL, '=================================\n       CURRICULUM VITAE\n=================================\n\nPERSONAL INFORMATION\n--------------------\nName: aimai\nEmail: aimai@gmail.com\nPersonality Type: ISFP\n\nPROFESSIONAL SUMMARY\n--------------------\nMotivated ISFP professional with demonstrated commitment to continuous learning and personal development. Actively pursuing goals in Professional Growth.\n\nSKILLS\n------\nProficient in: Communication\n\nCURRENT OBJECTIVES\n------------------\n• advic\n• reading\n• test1\n\nGenerated via AIMAI Career Platform on October 04, 2025\n', '2025-10-04 21:18:15'),
(6, 2, NULL, '═══════════════════════════════════════════════════════════\n                    CURRICULUM VITAE\n═══════════════════════════════════════════════════════════\n\nPERSONAL INFORMATION\n━━━━━━━━━━━━━━━━━━━━\nName:               aimai\nEmail:              aimai@gmail.com\nPersonality Type:   ISFP\n\nPROFESSIONAL SUMMARY\n━━━━━━━━━━━━━━━━━━━━\nMotivated ISFP with strong commitment to continuous learning and personal development. Actively pursuing career goals in Technology. Demonstrated ability to set and achieve meaningful objectives through structured goal-setting and consistent progress tracking.\n\nCORE COMPETENCIES\n━━━━━━━━━━━━━━━━━\n▪ Proficient Skills:\n  • Communication (since Aug 2025)\n\nKEY ACHIEVEMENTS\n━━━━━━━━━━━━━━━━\n▪ reading\n  Completed: October 2025\n\nCURRENT DEVELOPMENT OBJECTIVES\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n▪ test1\n▪ test 2\n▪ test1\n\nPROFESSIONAL ATTRIBUTES\n━━━━━━━━━━━━━━━━━━━━━━━\n▪ Strong goal-setting and achievement tracking capabilities\n▪ Self-motivated with commitment to continuous improvement\n▪ Structured approach to skill development and career planning\n▪ Creative adaptability\n▪ Practical skills\n▪ Flexible work style\n\n═══════════════════════════════════════════════════════════\nGenerated via AIMAI Career Development Platform\nOctober 04, 2025 at 10:16 PM\n═══════════════════════════════════════════════════════════\n', '2025-10-04 22:16:22'),
(7, 2, NULL, '═══════════════════════════════════════════════════════════\n                    CURRICULUM VITAE\n═══════════════════════════════════════════════════════════\n\nPERSONAL INFORMATION\n━━━━━━━━━━━━━━━━━━━━\nName:               AimAI Student\nEmail:              aimai@gmail.com\nPersonality Type:   ISFP\n\nPROFESSIONAL SUMMARY\n━━━━━━━━━━━━━━━━━━━━\nMotivated ISFP with strong commitment to continuous learning and personal development. Actively pursuing career goals in Technology. Demonstrated ability to set and achieve meaningful objectives through structured goal-setting and consistent progress tracking.\n\nCORE COMPETENCIES\n━━━━━━━━━━━━━━━━━\n▪ Proficient Skills:\n  • Communication (since Aug 2025)\n\nKEY ACHIEVEMENTS\n━━━━━━━━━━━━━━━━\n▪ reading\n  Completed: October 2025\n\n▪ test1\n  Completed: October 2025\n\nCURRENT DEVELOPMENT OBJECTIVES\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n▪ test 2\n▪ test1\n\nPROFESSIONAL ATTRIBUTES\n━━━━━━━━━━━━━━━━━━━━━━━\n▪ Strong goal-setting and achievement tracking capabilities\n▪ Self-motivated with commitment to continuous improvement\n▪ Structured approach to skill development and career planning\n▪ Creative adaptability\n▪ Practical skills\n▪ Flexible work style\n\n═══════════════════════════════════════════════════════════\nGenerated via AIMAI Career Development Platform\nOctober 06, 2025 at 11:23 AM\n═══════════════════════════════════════════════════════════\n', '2025-10-06 11:23:57');

-- --------------------------------------------------------

--
-- Table structure for table `event_destinations`
--

CREATE TABLE `event_destinations` (
  `id` varchar(36) NOT NULL,
  `destination` text NOT NULL,
  `createdAt` datetime NOT NULL DEFAULT current_timestamp(),
  `updatedAt` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `execution_entity`
--

CREATE TABLE `execution_entity` (
  `id` int(11) NOT NULL,
  `data` mediumtext NOT NULL,
  `finished` tinyint(4) NOT NULL,
  `mode` varchar(255) NOT NULL,
  `retryOf` varchar(255) DEFAULT NULL,
  `retrySuccessId` varchar(255) DEFAULT NULL,
  `startedAt` datetime NOT NULL,
  `stoppedAt` datetime DEFAULT NULL,
  `workflowData` longtext NOT NULL CHECK (json_valid(`workflowData`)),
  `workflowId` int(11) DEFAULT NULL,
  `waitTill` datetime DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `execution_metadata`
--

CREATE TABLE `execution_metadata` (
  `id` int(11) NOT NULL,
  `executionId` int(11) NOT NULL,
  `key` text NOT NULL,
  `value` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `insights`
--

CREATE TABLE `insights` (
  `insight_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `insight` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `insights`
--

INSERT INTO `insights` (`insight_id`, `user_id`, `insight`, `created_at`) VALUES
(1, 2, 'You have completed 2 goals this month! Your progress is accelerating. Consider setting a stretch goal.', '2025-10-04 22:00:00'),
(2, 1, 'Your skill diversity is strong. Consider deepening expertise in one area to become highly specialized.', '2025-07-25 10:00:00'),
(3, 10, 'Your personality type (INTJ) aligns perfectly with Data Science. Strategic thinking is your strength.', '2025-09-21 15:00:00'),
(4, 2, 'You have been inactive for 3 days. Small daily progress compounds over time. Log in tomorrow!', '2025-10-01 09:00:00'),
(5, 1, 'Your project portfolio shows full-stack capabilities. You are ready to apply for mid-level positions.', '2025-07-26 11:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `installed_nodes`
--

CREATE TABLE `installed_nodes` (
  `name` char(200) NOT NULL,
  `type` char(200) NOT NULL,
  `latestVersion` int(11) NOT NULL DEFAULT 1,
  `package` char(214) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `installed_packages`
--

CREATE TABLE `installed_packages` (
  `packageName` char(214) NOT NULL,
  `installedVersion` char(50) NOT NULL,
  `authorName` char(70) DEFAULT NULL,
  `authorEmail` char(70) DEFAULT NULL,
  `createdAt` datetime DEFAULT current_timestamp(),
  `updatedAt` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

CREATE TABLE `jobs` (
  `job_id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `employment_type` varchar(50) DEFAULT 'Full-time',
  `salary_min` decimal(10,2) DEFAULT NULL,
  `salary_max` decimal(10,2) DEFAULT NULL,
  `posted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `company_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `jobs`
--

INSERT INTO `jobs` (`job_id`, `title`, `description`, `region`, `employment_type`, `salary_min`, `salary_max`, `posted_at`, `company_id`) VALUES
(1, 'Software Engineer', 'Develop web applications', 'San Francisco', 'Full-time', 80000.00, 120000.00, '2025-07-04 08:39:22', 1),
(2, 'Marketing Assistant', 'Support marketing campaigns', 'New York', 'Full-time', 50000.00, 70000.00, '2025-07-04 08:39:22', 1),
(3, 'test', 'test', 'test', 'Full-time', 0.00, 0.00, '2025-10-06 13:04:04', 1);

-- --------------------------------------------------------

--
-- Table structure for table `job_applications`
--

CREATE TABLE `job_applications` (
  `application_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `job_id` int(11) DEFAULT NULL,
  `status` enum('drafted','submitted','interviewing','offered','rejected','accepted') DEFAULT NULL,
  `applied_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `job_applications`
--

INSERT INTO `job_applications` (`application_id`, `user_id`, `job_id`, `status`, `applied_at`, `notes`) VALUES
(1, 1, 1, 'interviewing', '2025-07-25 10:00:00', 'Technical interview scheduled for next week'),
(2, 2, 2, 'submitted', '2025-10-01 14:30:00', 'Application submitted via company portal'),
(3, 10, 1, 'drafted', NULL, 'Need to update CV before submitting'),
(4, 1, 2, 'rejected', '2025-07-20 09:00:00', 'Not selected for interview');

-- --------------------------------------------------------

--
-- Table structure for table `job_queue`
--

CREATE TABLE `job_queue` (
  `job_id` int(11) NOT NULL,
  `job_type` varchar(50) DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `status` enum('pending','processing','completed','failed') DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `job_queue`
--

INSERT INTO `job_queue` (`job_id`, `job_type`, `payload`, `status`, `created_at`) VALUES
(1, 'cv_generation', '{\"user_id\": 2, \"format\": \"pdf\"}', 'completed', '2025-10-04 21:18:10'),
(2, 'email_notification', '{\"user_id\": 1, \"type\": \"job_match\", \"job_id\": 1}', 'completed', '2025-07-25 09:30:00'),
(3, 'mentor_matching', '{\"user_id\": 10, \"personality\": \"INTJ\"}', 'completed', '2025-09-21 14:00:00'),
(4, 'weekly_summary', '{\"user_ids\": [1, 2, 10], \"week\": \"2025-W40\"}', 'pending', '2025-10-06 00:00:00'),
(5, 'skill_assessment', '{\"user_id\": 2, \"skill_id\": 2}', 'failed', '2025-10-04 21:45:00');

-- --------------------------------------------------------

--
-- Table structure for table `job_skills`
--

CREATE TABLE `job_skills` (
  `job_id` int(11) NOT NULL,
  `skill_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `job_skills`
--

INSERT INTO `job_skills` (`job_id`, `skill_id`) VALUES
(1, 1),
(2, 2);

-- --------------------------------------------------------

--
-- Table structure for table `learning_paths`
--

CREATE TABLE `learning_paths` (
  `path_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `career_goal` varchar(255) DEFAULT NULL,
  `estimated_duration_weeks` int(11) DEFAULT NULL,
  `difficulty_level` enum('beginner','intermediate','advanced') DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mentors`
--

CREATE TABLE `mentors` (
  `mentor_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `profession` varchar(100) DEFAULT NULL,
  `company` varchar(100) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','inactive','temporary') NOT NULL DEFAULT 'temporary'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `mentors`
--

INSERT INTO `mentors` (`mentor_id`, `name`, `email`, `profession`, `company`, `bio`, `created_at`, `status`) VALUES
(3, 'Alex M.', 'mentor@gmail.com', 'Data Science', NULL, NULL, '2025-07-26 21:30:39', 'active'),
(4, 'Sarah Chen', 'sarah.chen@techmail.com', 'Full Stack Developer', 'Google', 'Senior engineer with 8 years experience in React and Node.js. Passionate about mentoring newcomers to tech.', '2025-10-04 22:14:22', 'active'),
(5, 'Michael Rodriguez', 'mrodriguez@devmail.com', 'Data Scientist', 'Microsoft', 'PhD in Machine Learning. Specializes in predictive modeling and data visualization.', '2025-10-04 22:14:22', 'active'),
(6, 'Emily Thompson', 'emily.t@designmail.com', 'UX/UI Designer', 'Adobe', 'Award-winning designer with 10 years experience. Focus on user research and accessibility.', '2025-10-04 22:14:22', 'active'),
(7, 'James Kim', 'jkim@cloudmail.com', 'DevOps Engineer', 'Amazon', 'Infrastructure specialist. Expert in AWS, Docker, and CI/CD pipelines.', '2025-10-04 22:14:22', 'active'),
(8, 'Lisa Patel', 'lpatel@mobiledev.com', 'Mobile Developer', 'Apple', 'iOS development lead. Swift expert with 6 years shipping production apps.', '2025-10-04 22:14:22', 'active'),
(9, 'David Brown', 'dbrown@analytics.com', 'Business Analyst', 'IBM', 'Data-driven decision maker. Helps teams translate business needs into technical solutions.', '2025-10-04 22:14:22', 'active'),
(10, 'Rachel Green', 'rgreen@backend.dev', 'Backend Engineer', 'Netflix', 'Scalable systems architect. Python and Java specialist.', '2025-10-04 22:14:22', 'active'),
(11, 'Marcus Johnson', 'mjohnson@security.io', 'Cybersecurity Expert', 'Cisco', 'Certified ethical hacker. 12 years experience in network security and penetration testing.', '2025-10-04 22:14:22', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `mentorships`
--

CREATE TABLE `mentorships` (
  `mentorship_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `mentor_id` int(11) DEFAULT NULL,
  `start_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(20) DEFAULT 'active',
  `mentee_hired` tinyint(1) DEFAULT 0,
  `last_session_date` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `mentorships`
--

INSERT INTO `mentorships` (`mentorship_id`, `user_id`, `mentor_id`, `start_date`, `status`, `mentee_hired`, `last_session_date`) VALUES
(5, 2, 3, '2025-07-26 21:30:58', 'active', 0, NULL),
(6, 1, 4, '2025-08-01 10:00:00', 'active', 0, NULL),
(7, 10, 5, '2025-09-22 09:00:00', 'active', 0, NULL),
(8, 8, 6, '2025-08-25 14:00:00', 'active', 0, NULL),
(9, 9, 4, '2025-09-01 11:00:00', 'active', 0, NULL),
(10, 1, 4, '2025-08-01 10:00:00', 'active', 0, NULL),
(11, 10, 5, '2025-09-22 09:00:00', 'active', 0, NULL),
(12, 8, 6, '2025-08-25 14:00:00', 'active', 0, NULL),
(13, 9, 4, '2025-09-01 11:00:00', 'active', 0, NULL),
(14, 2, 11, '2025-10-05 14:23:06', 'pending', 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `mentor_availability`
--

CREATE TABLE `mentor_availability` (
  `availability_id` int(11) NOT NULL,
  `mentor_id` int(11) NOT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `is_available` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `mentor_availability`
--

INSERT INTO `mentor_availability` (`availability_id`, `mentor_id`, `day_of_week`, `start_time`, `end_time`, `is_available`) VALUES
(1, 3, 'Monday', '09:00:00', '17:00:00', 1),
(2, 3, 'Tuesday', '09:00:00', '17:00:00', 1),
(3, 3, 'Wednesday', '14:00:00', '18:00:00', 1),
(4, 3, 'Thursday', '09:00:00', '17:00:00', 1),
(5, 3, 'Friday', '09:00:00', '15:00:00', 1);

-- --------------------------------------------------------

--
-- Table structure for table `mentor_compatibility`
--

CREATE TABLE `mentor_compatibility` (
  `student_id` int(11) NOT NULL,
  `mentor_id` int(11) NOT NULL,
  `compatibility_score` decimal(5,2) NOT NULL,
  `factors` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`factors`)),
  `calculated_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `mentor_compatibility`
--

INSERT INTO `mentor_compatibility` (`student_id`, `mentor_id`, `compatibility_score`, `factors`, `calculated_at`) VALUES
(1, 3, 85.00, '{\"personality_match\": false, \"specialization_match\": false, \"availability\": 4, \"rating\": null}', '2025-07-25 10:00:00'),
(1, 11, 88.00, '{\"personality_match\": true, \"specialization_match\": true, \"availability\": 3, \"rating\": null}', '2025-10-04 22:20:34'),
(1, 12, 90.00, '{\"personality_match\": true, \"specialization_match\": false, \"availability\": 3, \"rating\": null}', '2025-10-04 22:20:34'),
(2, 3, 78.50, '{\"personality_match\": false, \"specialization_match\": false, \"availability\": 5, \"rating\": null}', '2025-07-26 21:00:00'),
(2, 13, 85.00, '{\"personality_match\": true, \"specialization_match\": false, \"availability\": 3, \"rating\": null}', '2025-10-04 22:20:34'),
(2, 15, 82.00, '{\"personality_match\": true, \"specialization_match\": false, \"availability\": 3, \"rating\": null}', '2025-10-04 22:20:34'),
(8, 13, 79.00, '{\"personality_match\": false, \"specialization_match\": false, \"availability\": 3, \"rating\": null}', '2025-10-04 22:20:34'),
(9, 11, 81.00, '{\"personality_match\": false, \"specialization_match\": true, \"availability\": 4, \"rating\": null}', '2025-10-04 22:20:34'),
(10, 3, 92.00, '{\"personality_match\": true, \"specialization_match\": true, \"availability\": 5, \"rating\": null}', '2025-09-21 14:00:00'),
(10, 12, 94.00, '{\"personality_match\": true, \"specialization_match\": true, \"availability\": 3, \"rating\": null}', '2025-10-04 22:20:34'),
(10, 14, 87.00, '{\"personality_match\": false, \"specialization_match\": false, \"availability\": 3, \"rating\": null}', '2025-10-04 22:20:34');

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(11) NOT NULL,
  `timestamp` bigint(20) NOT NULL,
  `name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `timestamp`, `name`) VALUES
(1, 1588157391238, 'InitialMigration1588157391238'),
(2, 1592447867632, 'WebhookModel1592447867632'),
(3, 1594902918301, 'CreateIndexStoppedAt1594902918301'),
(4, 1607431743767, 'MakeStoppedAtNullable1607431743767'),
(5, 1611149998770, 'AddWebhookId1611149998770'),
(6, 1615306975123, 'ChangeDataSize1615306975123'),
(7, 1617268711084, 'CreateTagEntity1617268711084'),
(8, 1620729500000, 'ChangeCredentialDataSize1620729500000'),
(9, 1620826335440, 'UniqueWorkflowNames1620826335440'),
(10, 1623936588000, 'CertifyCorrectCollation1623936588000'),
(11, 1626183952959, 'AddWaitColumnId1626183952959'),
(12, 1630451444017, 'UpdateWorkflowCredentials1630451444017'),
(13, 1644424784709, 'AddExecutionEntityIndexes1644424784709'),
(14, 1646992772331, 'CreateUserManagement1646992772331'),
(15, 1648740597343, 'LowerCaseUserEmail1648740597343'),
(16, 1652254514003, 'CommunityNodes1652254514003'),
(17, 1652367743993, 'AddUserSettings1652367743993'),
(18, 1652905585850, 'AddAPIKeyColumn1652905585850'),
(19, 1654090101303, 'IntroducePinData1654090101303'),
(20, 1658932910559, 'AddNodeIds1658932910559'),
(21, 1659895550980, 'AddJsonKeyPinData1659895550980'),
(22, 1660062385367, 'CreateCredentialsUserRole1660062385367'),
(23, 1663755770894, 'CreateWorkflowsEditorRole1663755770894'),
(24, 1664196174002, 'WorkflowStatistics1664196174002'),
(25, 1665484192213, 'CreateCredentialUsageTable1665484192213'),
(26, 1665754637026, 'RemoveCredentialUsageTable1665754637026'),
(27, 1669739707125, 'AddWorkflowVersionIdColumn1669739707125'),
(28, 1669823906994, 'AddTriggerCountColumn1669823906994'),
(29, 1671535397530, 'MessageEventBusDestinations1671535397530'),
(30, 1671726148420, 'RemoveWorkflowDataLoadedFlag1671726148420'),
(31, 1673268682475, 'DeleteExecutionsWithWorkflows1673268682475'),
(32, 1674138566000, 'AddStatusToExecutions1674138566000'),
(33, 1674509946020, 'CreateLdapEntities1674509946020'),
(34, 1675940580449, 'PurgeInvalidWorkflowConnections1675940580449'),
(35, 1676996103000, 'MigrateExecutionStatus1676996103000'),
(36, 1677236788851, 'UpdateRunningExecutionStatus1677236788851'),
(37, 1677501636753, 'CreateVariables1677501636753'),
(38, 1679416281779, 'CreateExecutionMetadataTable1679416281779'),
(39, 1681134145996, 'AddUserActivatedProperty1681134145996'),
(40, 1681134145997, 'RemoveSkipOwnerSetup1681134145997');

-- --------------------------------------------------------

--
-- Table structure for table `motivational_progress`
--

CREATE TABLE `motivational_progress` (
  `progress_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `goal` text DEFAULT NULL,
  `progress_status` varchar(50) DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `motivational_progress`
--

INSERT INTO `motivational_progress` (`progress_id`, `user_id`, `goal`, `progress_status`, `last_updated`) VALUES
(1, 1, 'Learn Python', 'in_progress', '2025-07-24 23:39:00'),
(2, 1, 'Complete JavaScript Course', 'completed', '2025-07-24 23:39:00'),
(3, 2, 'test1', 'in_progress', '2025-07-24 23:45:33'),
(4, 2, 'test 2', 'in_progress', '2025-07-24 23:45:56'),
(5, 1, 'Learn Python', 'completed', '2025-07-01 00:00:00'),
(6, 1, 'Complete JavaScript Course', 'in_progress', '2025-07-10 00:00:00'),
(7, 1, 'Build Portfolio', 'completed', '2025-06-20 00:00:00'),
(10, 2, 'test1', 'completed', '2025-10-05 14:20:05'),
(11, 2, 'reading', 'completed', '2025-10-05 14:20:11');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `is_read`, `created_at`) VALUES
(1, 3, 'Your profile has been updated successfully.', 0, '2025-07-26 19:39:29'),
(2, 2, 'New session scheduled: python on 2025-07-27T23:48', 0, '2025-07-26 21:48:54'),
(3, 2, 'Session updated: python on 2025-07-27T23:48', 0, '2025-07-26 21:49:05');

-- --------------------------------------------------------

--
-- Table structure for table `notification_settings`
--

CREATE TABLE `notification_settings` (
  `user_id` int(11) NOT NULL,
  `email_notifications` tinyint(1) DEFAULT 1,
  `mentor_messages` tinyint(1) DEFAULT 1,
  `goal_reminders` tinyint(1) DEFAULT 1,
  `job_matches` tinyint(1) DEFAULT 1,
  `achievement_alerts` tinyint(1) DEFAULT 1,
  `weekly_summary` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notification_settings`
--

INSERT INTO `notification_settings` (`user_id`, `email_notifications`, `mentor_messages`, `goal_reminders`, `job_matches`, `achievement_alerts`, `weekly_summary`) VALUES
(1, 1, 1, 1, 1, 1, 1),
(2, 1, 1, 0, 1, 1, 0),
(8, 0, 1, 1, 0, 0, 0),
(9, 1, 0, 1, 1, 1, 1),
(10, 1, 1, 1, 1, 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `path_milestones`
--

CREATE TABLE `path_milestones` (
  `milestone_id` int(11) NOT NULL,
  `path_id` int(11) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `order_index` int(11) DEFAULT NULL,
  `estimated_weeks` int(11) DEFAULT NULL,
  `resources` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`resources`)),
  `required_skills` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`required_skills`)),
  `optional_skills` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`optional_skills`)),
  `project_ideas` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `path_milestones`
--

INSERT INTO `path_milestones` (`milestone_id`, `path_id`, `title`, `description`, `order_index`, `estimated_weeks`, `resources`, `required_skills`, `optional_skills`, `project_ideas`) VALUES
(1, 1, 'HTML, CSS & JavaScript Fundamentals', 'Master the core web technologies', 1, 6, NULL, '[\"HTML5\",\"CSS3\",\"JavaScript ES6\"]', '[\"Bootstrap\",\"Sass\"]', 'Build a personal portfolio website, Create a landing page clone'),
(2, 1, 'Frontend Framework (React)', 'Learn modern component-based development', 2, 8, NULL, '[\"React\",\"JSX\",\"Hooks\",\"State Management\"]', '[\"Redux\",\"Next.js\"]', 'Build a todo app, Create a weather dashboard'),
(3, 1, 'Backend Development (Node.js)', 'Server-side programming and APIs', 3, 10, NULL, '[\"Node.js\",\"Express\",\"RESTful APIs\",\"Authentication\"]', '[\"GraphQL\",\"WebSockets\"]', 'Build a REST API, Create authentication system'),
(4, 1, 'Databases & Data Modeling', 'Work with SQL and NoSQL databases', 4, 6, NULL, '[\"SQL\",\"PostgreSQL\",\"MongoDB\"]', '[\"Redis\",\"Database Design\"]', 'Design a blog database, Build CRUD application'),
(5, 1, 'Full Stack Integration & Deployment', 'Connect frontend and backend, deploy applications', 5, 8, NULL, '[\"Git\",\"Docker\",\"CI/CD\",\"Cloud Deployment\"]', '[\"AWS\",\"Kubernetes\"]', 'Deploy full-stack app, Set up automated testing');

-- --------------------------------------------------------

--
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `project_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `technologies` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`technologies`)),
  `github_url` varchar(255) DEFAULT NULL,
  `live_url` varchar(255) DEFAULT NULL,
  `screenshot_url` varchar(255) DEFAULT NULL,
  `status` enum('planning','in_progress','completed','on_hold') DEFAULT 'planning',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `visibility` enum('public','private','companies_only') DEFAULT 'public'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `projects`
--

INSERT INTO `projects` (`project_id`, `user_id`, `title`, `description`, `technologies`, `github_url`, `live_url`, `screenshot_url`, `status`, `start_date`, `end_date`, `created_at`, `visibility`) VALUES
(1, 2, 'Personal Portfolio Website', 'A responsive portfolio showcasing my projects and skills', '[\"HTML5\",\"CSS3\",\"JavaScript\",\"Bootstrap\"]', 'https://github.com/aimai/portfolio', 'https://aimai-portfolio.netlify.app', NULL, 'completed', '2025-07-15', '2025-08-05', '2025-10-04 22:12:14', 'public'),
(2, 1, 'Task Management App', 'Full-stack todo application with authentication', '[\"React\",\"Node.js\",\"Express\",\"MongoDB\"]', 'https://github.com/johndoe/taskapp', NULL, NULL, 'completed', '2025-06-01', '2025-07-15', '2025-10-04 22:12:14', 'public'),
(3, 1, 'Weather Dashboard', 'Real-time weather app using external API', '[\"React\",\"CSS3\",\"Weather API\"]', 'https://github.com/johndoe/weather', 'https://weather-dash.vercel.app', NULL, 'completed', '2025-05-10', '2025-06-05', '2025-10-04 22:12:14', 'public'),
(4, 10, 'Data Visualization Dashboard', 'Interactive charts for sales data analysis', '[\"Python\",\"Plotly\",\"Pandas\"]', NULL, NULL, NULL, 'in_progress', '2025-09-25', NULL, '2025-10-04 22:12:14', 'private'),
(5, 9, 'E-commerce Landing Page', 'Modern landing page with animations', '[\"HTML5\",\"CSS3\",\"JavaScript\"]', 'https://github.com/student/ecommerce', NULL, NULL, 'in_progress', '2025-08-25', NULL, '2025-10-04 22:12:14', 'public');

-- --------------------------------------------------------

--
-- Table structure for table `recommendations`
--

CREATE TABLE `recommendations` (
  `recommendation_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `recommendation` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `recommendation_text` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `recommendations`
--

INSERT INTO `recommendations` (`recommendation_id`, `user_id`, `recommendation`, `created_at`, `recommendation_text`) VALUES
(1, 2, 'Based on your goals, consider dedicating 30 minutes daily to skill practice. Consistency trumps intensity.', '2025-09-11 15:34:39', NULL),
(2, 10, 'Network with professionals in your field through virtual events or LinkedIn connections. As an INTJ, focus on long-term strategic planning and system optimization.', '2025-09-21 12:31:11', NULL),
(3, 2, 'Network with professionals in your field through virtual events or LinkedIn connections.', '2025-09-22 20:09:02', NULL),
(4, 2, 'Network with professionals in your field through virtual events or LinkedIn connections.', '2025-09-22 20:14:00', NULL),
(5, 2, 'Consider joining a study group or finding an accountability partner for your current goals.', '2025-10-04 21:06:58', NULL),
(6, 2, 'Your personality type suggests you\'d benefit from setting weekly milestones rather than daily ones.', '2025-10-04 22:16:31', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `resources`
--

CREATE TABLE `resources` (
  `resource_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `category` varchar(50) NOT NULL,
  `url` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `resources`
--

INSERT INTO `resources` (`resource_id`, `user_id`, `title`, `description`, `category`, `url`, `created_at`) VALUES
(1, NULL, 'Learn Python the Hard Way', 'A comprehensive book for beginners to learn Python programming through practical exercises.', 'Programming', 'https://learnpythonthehardway.org', '2025-07-25 01:07:14'),
(2, NULL, 'Coursera: Machine Learning by Stanford', 'An online course covering machine learning fundamentals, taught by Andrew Ng.', 'Programming', 'https://www.coursera.org/learn/machine-learning', '2025-07-25 01:07:14'),
(3, NULL, 'LinkedIn Learning: Career Essentials', 'A course on building a strong resume, networking, and interview skills.', 'Career Development', 'https://www.linkedin.com/learning', '2025-07-25 01:07:14'),
(4, NULL, 'FreeCodeCamp: JavaScript Tutorial', 'A free, interactive course to learn JavaScript programming from scratch.', 'Programming', 'https://www.freecodecamp.org/learn/javascript', '2025-07-25 01:07:14'),
(5, NULL, 'The Muse: Career Advice', 'Articles and resources for job searching, resume building, and career growth.', 'Career Development', 'https://www.themuse.com/advice', '2025-07-25 01:07:14');

-- --------------------------------------------------------

--
-- Table structure for table `reviews`
--

CREATE TABLE `reviews` (
  `review_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `rating` int(11) NOT NULL CHECK (`rating` between 1 and 5),
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reviews`
--

INSERT INTO `reviews` (`review_id`, `user_id`, `rating`, `comment`, `created_at`) VALUES
(1, 2, 5, 'The AI coach feature is incredibly helpful! It understood my goals and gave personalized advice.', '2025-10-04 22:00:00'),
(2, 1, 4, 'Great platform for career development. Mentor matching could be improved.', '2025-07-26 12:00:00'),
(3, 10, 5, 'Love the career roadmap feature. It breaks down complex paths into manageable steps.', '2025-09-22 10:00:00'),
(4, 9, 3, 'Good concept but needs more job postings. Interface is clean and easy to use.', '2025-08-25 14:30:00');

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `session_id` int(11) NOT NULL,
  `mentorship_id` int(11) NOT NULL,
  `session_title` varchar(255) NOT NULL,
  `session_date` timestamp NOT NULL,
  `session_type` varchar(50) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sessions`
--

INSERT INTO `sessions` (`session_id`, `mentorship_id`, `session_title`, `session_date`, `session_type`, `created_at`) VALUES
(1, 5, 'python', '2025-07-27 23:48:00', 'Video Call', '2025-07-26 21:48:54');

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `key` varchar(255) NOT NULL,
  `value` text NOT NULL,
  `loadOnStartup` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`key`, `value`, `loadOnStartup`) VALUES
('features.ldap', '{\"loginEnabled\":false,\"loginLabel\":\"\",\"connectionUrl\":\"\",\"allowUnauthorizedCerts\":false,\"connectionSecurity\":\"none\",\"connectionPort\":389,\"baseDn\":\"\",\"bindingAdminDn\":\"\",\"bindingAdminPassword\":\"\",\"firstNameAttribute\":\"\",\"lastNameAttribute\":\"\",\"emailAttribute\":\"\",\"loginIdAttribute\":\"\",\"ldapIdAttribute\":\"\",\"userFilter\":\"\",\"synchronizationEnabled\":false,\"synchronizationInterval\":60,\"searchPageSize\":0,\"searchTimeout\":60}', 1),
('ui.banners.dismissed', '[\"V1\"]', 1),
('userManagement.isInstanceOwnerSetUp', 'false', 1);

-- --------------------------------------------------------

--
-- Table structure for table `shared_credentials`
--

CREATE TABLE `shared_credentials` (
  `createdAt` datetime NOT NULL DEFAULT current_timestamp(),
  `updatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `roleId` int(11) NOT NULL,
  `userId` varchar(36) NOT NULL,
  `credentialsId` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shared_workflow`
--

CREATE TABLE `shared_workflow` (
  `createdAt` datetime NOT NULL DEFAULT current_timestamp(),
  `updatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `roleId` int(11) NOT NULL,
  `userId` varchar(36) NOT NULL,
  `workflowId` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `skills`
--

CREATE TABLE `skills` (
  `skill_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `skills`
--

INSERT INTO `skills` (`skill_id`, `name`, `description`) VALUES
(1, 'Python', 'Proficiency in Python programming language'),
(2, 'Communication', 'Effective verbal and written communication skills'),
(3, 'HTML5', 'Modern HTML markup language'),
(4, 'CSS3', 'Cascading Style Sheets for styling'),
(5, 'JavaScript ES6', 'Modern JavaScript programming'),
(6, 'React', 'Frontend JavaScript framework'),
(7, 'Node.js', 'Server-side JavaScript runtime'),
(8, 'SQL', 'Structured Query Language for databases'),
(9, 'Git', 'Version control system'),
(10, 'Docker', 'Containerization platform'),
(11, 'MongoDB', 'NoSQL database'),
(12, 'Express', 'Node.js web framework');

-- --------------------------------------------------------

--
-- Table structure for table `tag_entity`
--

CREATE TABLE `tag_entity` (
  `tmp_id` int(11) NOT NULL,
  `name` varchar(24) NOT NULL,
  `createdAt` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updatedAt` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  `id` varchar(36) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `personality_type` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `role` enum('student','admin','company','mentor') NOT NULL DEFAULT 'student',
  `status` enum('active','inactive','temporary') NOT NULL DEFAULT 'temporary',
  `specialization` varchar(255) DEFAULT NULL,
  `mentorship_intent` text DEFAULT NULL,
  `company_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `personality_type`, `created_at`, `role`, `status`, `specialization`, `mentorship_intent`, `company_id`) VALUES
(1, 'john_doe', 'john@example.com', 'hashed_password_123', 'INTJ', '2025-07-04 08:39:22', 'student', 'active', NULL, NULL, NULL),
(2, 'AimAI Student', 'aimai@gmail.com', '$2y$10$itXODXm8GWnuqbE/yyZ/hujAwG/OnG.T/t2XUJq8V7VlDMmnwfVHi', 'ISFP', '2025-07-10 13:30:58', 'student', 'active', NULL, NULL, NULL),
(3, 'Alex M.', 'mentor@gmail.com', '$2y$10$VpOAGWklH9oxM7RYMxjZMelBAEv5LSlZdrjBhwK3rWawqg.udMsGq', '', '2025-07-11 23:33:23', 'mentor', 'active', 'Data Science', NULL, NULL),
(4, 'company', 'company@gmail.com', '$2y$10$MotVdOHUJrwHUQVjTFoUD.xSlvApAW3cyUnjxI9KkDgVeToqKPd8.', '', '2025-07-11 23:34:21', 'company', 'active', NULL, NULL, NULL),
(5, 'company1', 'company1@gmail.com', '$2y$10$4ZUBkb1s1.ZOpEufLfWZvOW.Pj.1lS6UUUIWdpfHMc50W3/u1olg.', '', '2025-07-11 23:35:49', 'company', 'active', NULL, NULL, NULL),
(7, 'admin', 'admin@gmail.com', '$2y$10$8w7w1TlllXTv.SyQbmROAuNUJmQXxcY0rS4gq3h/Y4SKfQ9b6Aicm', '', '2025-07-24 16:10:04', 'admin', 'active', NULL, NULL, NULL),
(8, 'Besiri', 'topallibesir@gmail.com', '$2y$10$O8X.rleWEopiX/FnuspoEO1C7J6zHQWy33R3FmnYKIjxX4Gvnap/m', '', '2025-08-18 20:12:53', 'student', 'temporary', NULL, NULL, NULL),
(9, 'Student', 'student@gmail.com', '$2y$10$tQwOA4nYRtg7Mu/O9uFEme1yAFOS0cFhE9vX454F7rV.t770jfT/2', '', '2025-08-20 15:50:52', 'student', 'temporary', NULL, NULL, NULL),
(10, 'Jeta', 'jeta.krasniqi7@student.uni-pr.edu', '$2y$10$YqgBvgJMPh1IYeT.3S37bOebJsES9Rhn82KRNbldRBXkURn3S.XvS', 'INTJ', '2025-09-21 12:14:51', 'student', 'temporary', NULL, NULL, NULL),
(11, 'Sarah Chen', 'sarah.chen@techmail.com', '$2y$10$dummyhash1', 'ENTJ', '2025-10-04 22:14:22', 'mentor', 'active', 'Full Stack Development', NULL, NULL),
(12, 'Michael Rodriguez', 'mrodriguez@devmail.com', '$2y$10$dummyhash2', 'INTJ', '2025-10-04 22:14:22', 'mentor', 'active', 'Data Science', NULL, NULL),
(13, 'Emily Thompson', 'emily.t@designmail.com', '$2y$10$dummyhash3', 'INFJ', '2025-10-04 22:14:22', 'mentor', 'active', 'UX/UI Design', NULL, NULL),
(14, 'James Kim', 'jkim@cloudmail.com', '$2y$10$dummyhash4', 'ISTJ', '2025-10-04 22:14:22', 'mentor', 'active', 'DevOps', NULL, NULL),
(15, 'Lisa Patel', 'lpatel@mobiledev.com', '$2y$10$dummyhash5', 'ENFP', '2025-10-04 22:14:22', 'mentor', 'active', 'Mobile Development', NULL, NULL),
(16, 'David Brown', 'dbrown@analytics.com', '$2y$10$dummyhash6', 'ESTJ', '2025-10-04 22:14:22', 'mentor', 'active', 'Business Analysis', NULL, NULL),
(17, 'Rachel Green', 'rgreen@backend.dev', '$2y$10$dummyhash7', 'INTP', '2025-10-04 22:14:22', 'mentor', 'active', 'Backend Engineering', NULL, NULL),
(18, 'Marcus Johnson', 'mjohnson@security.io', '$2y$10$dummyhash8', 'ISTP', '2025-10-04 22:14:22', 'mentor', 'active', 'Cybersecurity', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_achievements`
--

CREATE TABLE `user_achievements` (
  `user_id` int(11) NOT NULL,
  `achievement_id` int(11) NOT NULL,
  `earned_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_achievements`
--

INSERT INTO `user_achievements` (`user_id`, `achievement_id`, `earned_at`) VALUES
(1, 1, '2025-07-01 00:00:00'),
(1, 2, '2025-07-10 00:00:00'),
(1, 7, '2025-07-15 16:30:00'),
(2, 1, '2025-07-25 00:00:00'),
(2, 4, '2025-08-08 00:00:00'),
(2, 8, '2025-10-04 21:18:15'),
(10, 1, '2025-09-21 12:30:00'),
(10, 10, '2025-09-21 14:15:00');

-- --------------------------------------------------------

--
-- Table structure for table `user_career_paths`
--

CREATE TABLE `user_career_paths` (
  `user_id` int(11) NOT NULL,
  `path_id` int(11) NOT NULL,
  `started_at` timestamp NULL DEFAULT current_timestamp(),
  `current_milestone_id` int(11) DEFAULT NULL,
  `completion_percentage` decimal(5,2) DEFAULT 0.00,
  `status` enum('active','paused','completed') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `user_career_paths`
--

INSERT INTO `user_career_paths` (`user_id`, `path_id`, `started_at`, `current_milestone_id`, `completion_percentage`, `status`) VALUES
(1, 1, '2025-07-05 09:00:00', 3, 60.00, 'active'),
(2, 1, '2025-08-01 10:00:00', 1, 20.00, 'active'),
(8, 3, '2025-08-20 11:00:00', NULL, 10.00, 'paused'),
(9, 1, '2025-08-21 10:00:00', 2, 35.00, 'active'),
(10, 2, '2025-09-21 14:00:00', NULL, 5.00, 'active');

-- --------------------------------------------------------

--
-- Table structure for table `user_skills`
--

CREATE TABLE `user_skills` (
  `user_id` int(11) NOT NULL,
  `skill_id` int(11) NOT NULL,
  `acquired_at` datetime DEFAULT current_timestamp(),
  `status` enum('learning','acquired') DEFAULT 'acquired',
  `proficiency_level` enum('beginner','intermediate','advanced','expert') DEFAULT 'beginner',
  `progress_percentage` decimal(5,2) DEFAULT 0.00,
  `last_practiced_at` timestamp NULL DEFAULT NULL,
  `last_assessed_at` timestamp NULL DEFAULT NULL,
  `assessment_score` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_skills`
--

INSERT INTO `user_skills` (`user_id`, `skill_id`, `acquired_at`, `status`, `proficiency_level`, `progress_percentage`, `last_practiced_at`, `last_assessed_at`, `assessment_score`) VALUES
(2, 2, '2025-08-08 19:23:01', 'acquired', 'beginner', 0.00, '2025-10-04 21:50:14', NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `user_vp_interactions`
--

CREATE TABLE `user_vp_interactions` (
  `interaction_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `vp_id` int(11) DEFAULT NULL,
  `conversation_log` text DEFAULT NULL,
  `interaction_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `variables`
--

CREATE TABLE `variables` (
  `id` int(11) NOT NULL,
  `key` varchar(50) NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'string',
  `value` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `virtual_professionals`
--

CREATE TABLE `virtual_professionals` (
  `vp_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `profession` varchar(100) NOT NULL,
  `company` varchar(100) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `virtual_professionals`
--

INSERT INTO `virtual_professionals` (`vp_id`, `name`, `profession`, `company`, `bio`, `created_at`) VALUES
(1, 'Jane Smith', 'CEO', 'TechCorp', 'Experienced leader in tech industry', '2025-07-04 08:39:22'),
(2, 'AIMAI Bot', 'AI Coach', NULL, NULL, '2025-08-14 20:34:38');

-- --------------------------------------------------------

--
-- Table structure for table `webhook_entity`
--

CREATE TABLE `webhook_entity` (
  `workflowId` int(11) NOT NULL,
  `webhookPath` varchar(255) NOT NULL,
  `method` varchar(255) NOT NULL,
  `node` varchar(255) NOT NULL,
  `webhookId` varchar(255) DEFAULT NULL,
  `pathLength` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `workflows_tags`
--

CREATE TABLE `workflows_tags` (
  `tmp_workflowId` int(11) NOT NULL,
  `tagId` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `workflow_entity`
--

CREATE TABLE `workflow_entity` (
  `tmp_id` int(11) NOT NULL,
  `name` varchar(128) NOT NULL,
  `active` tinyint(4) NOT NULL,
  `nodes` longtext NOT NULL CHECK (json_valid(`nodes`)),
  `connections` longtext NOT NULL CHECK (json_valid(`connections`)),
  `createdAt` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updatedAt` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  `settings` longtext DEFAULT NULL CHECK (json_valid(`settings`)),
  `staticData` longtext DEFAULT NULL CHECK (json_valid(`staticData`)),
  `pinData` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`pinData`)),
  `versionId` char(36) DEFAULT NULL,
  `triggerCount` int(11) NOT NULL DEFAULT 0,
  `id` varchar(36) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `workflow_statistics`
--

CREATE TABLE `workflow_statistics` (
  `count` int(11) DEFAULT 0,
  `latestEvent` datetime DEFAULT NULL,
  `name` varchar(128) NOT NULL,
  `workflowId` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `achievements`
--
ALTER TABLE `achievements`
  ADD PRIMARY KEY (`achievement_id`);

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_user_activity` (`user_id`,`activity_type`,`created_at`);

--
-- Indexes for table `ai_interactions`
--
ALTER TABLE `ai_interactions`
  ADD PRIMARY KEY (`interaction_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `auth_identity`
--
ALTER TABLE `auth_identity`
  ADD PRIMARY KEY (`providerId`,`providerType`),
  ADD KEY `userId` (`userId`);

--
-- Indexes for table `auth_provider_sync_history`
--
ALTER TABLE `auth_provider_sync_history`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `career_paths`
--
ALTER TABLE `career_paths`
  ADD PRIMARY KEY (`path_id`);

--
-- Indexes for table `coaching_sessions`
--
ALTER TABLE `coaching_sessions`
  ADD PRIMARY KEY (`coaching_id`),
  ADD KEY `mentor_id` (`mentor_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`company_id`);

--
-- Indexes for table `company_connections`
--
ALTER TABLE `company_connections`
  ADD PRIMARY KEY (`connection_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `company_id` (`company_id`);

--
-- Indexes for table `company_offerings`
--
ALTER TABLE `company_offerings`
  ADD PRIMARY KEY (`offering_id`),
  ADD KEY `company_offerings_ibfk_1` (`company_id`);

--
-- Indexes for table `credentials_entity`
--
ALTER TABLE `credentials_entity`
  ADD PRIMARY KEY (`id`),
  ADD KEY `IDX_07fde106c0b471d8cc80a64fc8` (`type`);

--
-- Indexes for table `cvs`
--
ALTER TABLE `cvs`
  ADD PRIMARY KEY (`cv_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `job_id` (`job_id`),
  ADD KEY `idx_cvs_user_job` (`user_id`,`job_id`);

--
-- Indexes for table `event_destinations`
--
ALTER TABLE `event_destinations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `execution_entity`
--
ALTER TABLE `execution_entity`
  ADD PRIMARY KEY (`id`),
  ADD KEY `IDX_b94b45ce2c73ce46c54f20b5f9` (`waitTill`,`id`),
  ADD KEY `IDX_81fc04c8a17de15835713505e4` (`workflowId`,`id`),
  ADD KEY `IDX_8b6f3f9ae234f137d707b98f3bf43584` (`status`,`workflowId`);

--
-- Indexes for table `execution_metadata`
--
ALTER TABLE `execution_metadata`
  ADD PRIMARY KEY (`id`),
  ADD KEY `IDX_6d44376da6c1058b5e81ed8a154e1fee106046eb` (`executionId`);

--
-- Indexes for table `insights`
--
ALTER TABLE `insights`
  ADD PRIMARY KEY (`insight_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `installed_nodes`
--
ALTER TABLE `installed_nodes`
  ADD PRIMARY KEY (`name`),
  ADD KEY `FK_73f857fc5dce682cef8a99c11dbddbc969618951` (`package`);

--
-- Indexes for table `installed_packages`
--
ALTER TABLE `installed_packages`
  ADD PRIMARY KEY (`packageName`);

--
-- Indexes for table `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`job_id`),
  ADD KEY `idx_jobs_region` (`region`),
  ADD KEY `fk_jobs_company` (`company_id`);

--
-- Indexes for table `job_applications`
--
ALTER TABLE `job_applications`
  ADD PRIMARY KEY (`application_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `job_id` (`job_id`);

--
-- Indexes for table `job_queue`
--
ALTER TABLE `job_queue`
  ADD PRIMARY KEY (`job_id`);

--
-- Indexes for table `job_skills`
--
ALTER TABLE `job_skills`
  ADD PRIMARY KEY (`job_id`,`skill_id`),
  ADD KEY `skill_id` (`skill_id`);

--
-- Indexes for table `learning_paths`
--
ALTER TABLE `learning_paths`
  ADD PRIMARY KEY (`path_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `mentors`
--
ALTER TABLE `mentors`
  ADD PRIMARY KEY (`mentor_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_mentor_profession` (`profession`);

--
-- Indexes for table `mentorships`
--
ALTER TABLE `mentorships`
  ADD PRIMARY KEY (`mentorship_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `mentor_id` (`mentor_id`),
  ADD KEY `idx_mentorships_status` (`status`,`mentor_id`);

--
-- Indexes for table `mentor_availability`
--
ALTER TABLE `mentor_availability`
  ADD PRIMARY KEY (`availability_id`),
  ADD KEY `mentor_availability_ibfk_1` (`mentor_id`);

--
-- Indexes for table `mentor_compatibility`
--
ALTER TABLE `mentor_compatibility`
  ADD PRIMARY KEY (`student_id`,`mentor_id`),
  ADD KEY `mentor_id` (`mentor_id`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `motivational_progress`
--
ALTER TABLE `motivational_progress`
  ADD PRIMARY KEY (`progress_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_user_last_updated` (`user_id`,`last_updated`),
  ADD KEY `idx_goals_user_status` (`user_id`,`progress_status`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `notification_settings`
--
ALTER TABLE `notification_settings`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `path_milestones`
--
ALTER TABLE `path_milestones`
  ADD PRIMARY KEY (`milestone_id`),
  ADD KEY `path_milestones_ibfk_1` (`path_id`);

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`project_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `recommendations`
--
ALTER TABLE `recommendations`
  ADD PRIMARY KEY (`recommendation_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `resources`
--
ALTER TABLE `resources`
  ADD PRIMARY KEY (`resource_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`review_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`session_id`),
  ADD KEY `mentorship_id` (`mentorship_id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`key`);

--
-- Indexes for table `shared_credentials`
--
ALTER TABLE `shared_credentials`
  ADD PRIMARY KEY (`userId`,`credentialsId`),
  ADD KEY `FK_c68e056637562000b68f480815a` (`roleId`),
  ADD KEY `FK_484f0327e778648dd04f1d70493` (`userId`),
  ADD KEY `FK_68661def1d4bcf2451ac8dbd949` (`credentialsId`);

--
-- Indexes for table `shared_workflow`
--
ALTER TABLE `shared_workflow`
  ADD PRIMARY KEY (`userId`,`workflowId`),
  ADD KEY `FK_3540da03964527aa24ae014b780x` (`roleId`),
  ADD KEY `FK_82b2fd9ec4e3e24209af8160282x` (`userId`),
  ADD KEY `FK_b83f8d2530884b66a9c848c8b88x` (`workflowId`);

--
-- Indexes for table `skills`
--
ALTER TABLE `skills`
  ADD PRIMARY KEY (`skill_id`),
  ADD KEY `idx_skills_name` (`name`);

--
-- Indexes for table `tag_entity`
--
ALTER TABLE `tag_entity`
  ADD PRIMARY KEY (`tmp_id`),
  ADD UNIQUE KEY `IDX_8f949d7a3a984759044054e89b` (`name`),
  ADD UNIQUE KEY `TMP_idx_tag_entity_id` (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `fk_users_company` (`company_id`);

--
-- Indexes for table `user_achievements`
--
ALTER TABLE `user_achievements`
  ADD PRIMARY KEY (`user_id`,`achievement_id`),
  ADD KEY `achievement_id` (`achievement_id`);

--
-- Indexes for table `user_career_paths`
--
ALTER TABLE `user_career_paths`
  ADD PRIMARY KEY (`user_id`,`path_id`),
  ADD KEY `path_id` (`path_id`),
  ADD KEY `current_milestone_id` (`current_milestone_id`);

--
-- Indexes for table `user_skills`
--
ALTER TABLE `user_skills`
  ADD PRIMARY KEY (`user_id`,`skill_id`),
  ADD KEY `skill_id` (`skill_id`);

--
-- Indexes for table `user_vp_interactions`
--
ALTER TABLE `user_vp_interactions`
  ADD PRIMARY KEY (`interaction_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `vp_id` (`vp_id`);

--
-- Indexes for table `variables`
--
ALTER TABLE `variables`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key` (`key`);

--
-- Indexes for table `virtual_professionals`
--
ALTER TABLE `virtual_professionals`
  ADD PRIMARY KEY (`vp_id`);

--
-- Indexes for table `webhook_entity`
--
ALTER TABLE `webhook_entity`
  ADD PRIMARY KEY (`webhookPath`,`method`),
  ADD KEY `IDX_742496f199721a057051acf4c2` (`webhookId`,`method`,`pathLength`);

--
-- Indexes for table `workflows_tags`
--
ALTER TABLE `workflows_tags`
  ADD PRIMARY KEY (`tmp_workflowId`,`tagId`),
  ADD KEY `IDX_54b2f0343d6a2078fa13744386` (`tmp_workflowId`),
  ADD KEY `IDX_77505b341625b0b4768082e217` (`tagId`);

--
-- Indexes for table `workflow_entity`
--
ALTER TABLE `workflow_entity`
  ADD PRIMARY KEY (`tmp_id`),
  ADD UNIQUE KEY `TMP_idx_workflow_entity_id` (`id`);

--
-- Indexes for table `workflow_statistics`
--
ALTER TABLE `workflow_statistics`
  ADD PRIMARY KEY (`workflowId`,`name`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `achievements`
--
ALTER TABLE `achievements`
  MODIFY `achievement_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `ai_interactions`
--
ALTER TABLE `ai_interactions`
  MODIFY `interaction_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `auth_provider_sync_history`
--
ALTER TABLE `auth_provider_sync_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `career_paths`
--
ALTER TABLE `career_paths`
  MODIFY `path_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `coaching_sessions`
--
ALTER TABLE `coaching_sessions`
  MODIFY `coaching_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `company_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `company_connections`
--
ALTER TABLE `company_connections`
  MODIFY `connection_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `company_offerings`
--
ALTER TABLE `company_offerings`
  MODIFY `offering_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `credentials_entity`
--
ALTER TABLE `credentials_entity`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cvs`
--
ALTER TABLE `cvs`
  MODIFY `cv_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `execution_entity`
--
ALTER TABLE `execution_entity`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `execution_metadata`
--
ALTER TABLE `execution_metadata`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `insights`
--
ALTER TABLE `insights`
  MODIFY `insight_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
  MODIFY `job_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `job_applications`
--
ALTER TABLE `job_applications`
  MODIFY `application_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `job_queue`
--
ALTER TABLE `job_queue`
  MODIFY `job_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `learning_paths`
--
ALTER TABLE `learning_paths`
  MODIFY `path_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mentors`
--
ALTER TABLE `mentors`
  MODIFY `mentor_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `mentorships`
--
ALTER TABLE `mentorships`
  MODIFY `mentorship_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `mentor_availability`
--
ALTER TABLE `mentor_availability`
  MODIFY `availability_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `motivational_progress`
--
ALTER TABLE `motivational_progress`
  MODIFY `progress_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `path_milestones`
--
ALTER TABLE `path_milestones`
  MODIFY `milestone_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `project_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `recommendations`
--
ALTER TABLE `recommendations`
  MODIFY `recommendation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `resources`
--
ALTER TABLE `resources`
  MODIFY `resource_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `review_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `sessions`
--
ALTER TABLE `sessions`
  MODIFY `session_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `skills`
--
ALTER TABLE `skills`
  MODIFY `skill_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `tag_entity`
--
ALTER TABLE `tag_entity`
  MODIFY `tmp_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `user_vp_interactions`
--
ALTER TABLE `user_vp_interactions`
  MODIFY `interaction_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `variables`
--
ALTER TABLE `variables`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `virtual_professionals`
--
ALTER TABLE `virtual_professionals`
  MODIFY `vp_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `workflow_entity`
--
ALTER TABLE `workflow_entity`
  MODIFY `tmp_id` int(11) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Structure for view `account_status_overview`
--
DROP TABLE IF EXISTS `account_status_overview`;

CREATE ALGORITHM=UNDEFINED DEFINER=`u678631644_aimai`@`127.0.0.1` SQL SECURITY DEFINER VIEW `account_status_overview`  AS SELECT 'student' AS `role`, `users`.`user_id` AS `id`, `users`.`username` AS `name`, `users`.`status` AS `status`, `users`.`created_at` AS `created_at` FROM `users` WHERE `users`.`role` = 'student'union all select 'admin' AS `role`,`users`.`user_id` AS `id`,`users`.`username` AS `name`,`users`.`status` AS `status`,`users`.`created_at` AS `created_at` from `users` where `users`.`role` = 'admin' union all select 'mentor' AS `role`,`mentors`.`mentor_id` AS `id`,`mentors`.`name` AS `name`,`mentors`.`status` AS `status`,`mentors`.`created_at` AS `created_at` from `mentors` union all select 'company' AS `role`,`companies`.`company_id` AS `id`,`companies`.`name` AS `name`,`companies`.`status` AS `status`,`companies`.`created_at` AS `created_at` from `companies`  ;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `ai_interactions`
--
ALTER TABLE `ai_interactions`
  ADD CONSTRAINT `ai_interactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `coaching_sessions`
--
ALTER TABLE `coaching_sessions`
  ADD CONSTRAINT `coaching_sessions_ibfk_1` FOREIGN KEY (`mentor_id`) REFERENCES `mentors` (`mentor_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `coaching_sessions_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `company_connections`
--
ALTER TABLE `company_connections`
  ADD CONSTRAINT `company_connections_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `company_connections_ibfk_2` FOREIGN KEY (`company_id`) REFERENCES `companies` (`company_id`) ON DELETE CASCADE;

--
-- Constraints for table `company_offerings`
--
ALTER TABLE `company_offerings`
  ADD CONSTRAINT `company_offerings_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`company_id`) ON DELETE CASCADE;

--
-- Constraints for table `cvs`
--
ALTER TABLE `cvs`
  ADD CONSTRAINT `cvs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cvs_ibfk_2` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`job_id`) ON DELETE SET NULL;

--
-- Constraints for table `execution_entity`
--
ALTER TABLE `execution_entity`
  ADD CONSTRAINT `FK_execution_entity_workflowId` FOREIGN KEY (`workflowId`) REFERENCES `workflow_entity` (`tmp_id`) ON DELETE CASCADE;

--
-- Constraints for table `execution_metadata`
--
ALTER TABLE `execution_metadata`
  ADD CONSTRAINT `execution_metadata_FK` FOREIGN KEY (`executionId`) REFERENCES `execution_entity` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `insights`
--
ALTER TABLE `insights`
  ADD CONSTRAINT `insights_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `installed_nodes`
--
ALTER TABLE `installed_nodes`
  ADD CONSTRAINT `FK_73f857fc5dce682cef8a99c11dbddbc969618951` FOREIGN KEY (`package`) REFERENCES `installed_packages` (`packageName`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `jobs`
--
ALTER TABLE `jobs`
  ADD CONSTRAINT `fk_jobs_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`company_id`);

--
-- Constraints for table `job_applications`
--
ALTER TABLE `job_applications`
  ADD CONSTRAINT `job_applications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `job_applications_ibfk_2` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`job_id`);

--
-- Constraints for table `job_skills`
--
ALTER TABLE `job_skills`
  ADD CONSTRAINT `job_skills_ibfk_1` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`job_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `job_skills_ibfk_2` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`skill_id`) ON DELETE CASCADE;

--
-- Constraints for table `learning_paths`
--
ALTER TABLE `learning_paths`
  ADD CONSTRAINT `learning_paths_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `mentorships`
--
ALTER TABLE `mentorships`
  ADD CONSTRAINT `mentorships_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `mentorships_ibfk_2` FOREIGN KEY (`mentor_id`) REFERENCES `mentors` (`mentor_id`) ON DELETE CASCADE;

--
-- Constraints for table `mentor_availability`
--
ALTER TABLE `mentor_availability`
  ADD CONSTRAINT `mentor_availability_ibfk_1` FOREIGN KEY (`mentor_id`) REFERENCES `mentors` (`mentor_id`) ON DELETE CASCADE;

--
-- Constraints for table `mentor_compatibility`
--
ALTER TABLE `mentor_compatibility`
  ADD CONSTRAINT `mentor_compatibility_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `mentor_compatibility_ibfk_2` FOREIGN KEY (`mentor_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `motivational_progress`
--
ALTER TABLE `motivational_progress`
  ADD CONSTRAINT `motivational_progress_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `notification_settings`
--
ALTER TABLE `notification_settings`
  ADD CONSTRAINT `notification_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `path_milestones`
--
ALTER TABLE `path_milestones`
  ADD CONSTRAINT `path_milestones_ibfk_1` FOREIGN KEY (`path_id`) REFERENCES `career_paths` (`path_id`) ON DELETE CASCADE;

--
-- Constraints for table `projects`
--
ALTER TABLE `projects`
  ADD CONSTRAINT `projects_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `recommendations`
--
ALTER TABLE `recommendations`
  ADD CONSTRAINT `recommendations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `resources`
--
ALTER TABLE `resources`
  ADD CONSTRAINT `resources_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `reviews`
--
ALTER TABLE `reviews`
  ADD CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `sessions`
--
ALTER TABLE `sessions`
  ADD CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`mentorship_id`) REFERENCES `mentorships` (`mentorship_id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`company_id`);

--
-- Constraints for table `user_achievements`
--
ALTER TABLE `user_achievements`
  ADD CONSTRAINT `user_achievements_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_achievements_ibfk_2` FOREIGN KEY (`achievement_id`) REFERENCES `achievements` (`achievement_id`) ON DELETE CASCADE;

--
-- Constraints for table `user_career_paths`
--
ALTER TABLE `user_career_paths`
  ADD CONSTRAINT `user_career_paths_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_career_paths_ibfk_2` FOREIGN KEY (`path_id`) REFERENCES `career_paths` (`path_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_career_paths_ibfk_3` FOREIGN KEY (`current_milestone_id`) REFERENCES `path_milestones` (`milestone_id`) ON DELETE SET NULL;

--
-- Constraints for table `user_skills`
--
ALTER TABLE `user_skills`
  ADD CONSTRAINT `user_skills_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_skills_ibfk_2` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`skill_id`) ON DELETE CASCADE;

--
-- Constraints for table `user_vp_interactions`
--
ALTER TABLE `user_vp_interactions`
  ADD CONSTRAINT `user_vp_interactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_vp_interactions_ibfk_2` FOREIGN KEY (`vp_id`) REFERENCES `virtual_professionals` (`vp_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
