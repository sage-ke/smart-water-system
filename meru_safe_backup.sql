-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: meru
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `alerts`
--

DROP TABLE IF EXISTS `alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `alerts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `zone_id` int(11) DEFAULT NULL,
  `device_id` int(11) DEFAULT NULL,
  `alert_type` varchar(100) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `severity` enum('low','medium','high','critical') DEFAULT 'medium',
  `is_resolved` tinyint(1) DEFAULT 0,
  `resolved_by` int(11) DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `zone_id` (`zone_id`),
  KEY `device_id` (`device_id`),
  CONSTRAINT `alerts_ibfk_1` FOREIGN KEY (`zone_id`) REFERENCES `water_zones` (`id`) ON DELETE SET NULL,
  CONSTRAINT `alerts_ibfk_2` FOREIGN KEY (`device_id`) REFERENCES `hardware_devices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `alerts`
--

LOCK TABLES `alerts` WRITE;
/*!40000 ALTER TABLE `alerts` DISABLE KEYS */;
/*!40000 ALTER TABLE `alerts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `anomalies`
--

DROP TABLE IF EXISTS `anomalies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `anomalies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `zone_id` int(11) DEFAULT NULL,
  `device_id` int(11) DEFAULT NULL,
  `reading_id` int(11) DEFAULT NULL,
  `anomaly_type` varchar(100) NOT NULL,
  `expected_value` decimal(10,2) DEFAULT NULL,
  `actual_value` decimal(10,2) DEFAULT NULL,
  `deviation_pct` decimal(6,2) DEFAULT NULL,
  `severity_score` decimal(4,2) DEFAULT NULL,
  `is_confirmed` tinyint(1) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `detected_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_anom_zone` (`zone_id`),
  KEY `idx_anom_time` (`detected_at`),
  CONSTRAINT `anomalies_ibfk_1` FOREIGN KEY (`zone_id`) REFERENCES `water_zones` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `anomalies`
--

LOCK TABLES `anomalies` WRITE;
/*!40000 ALTER TABLE `anomalies` DISABLE KEYS */;
/*!40000 ALTER TABLE `anomalies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `anomaly_log`
--

DROP TABLE IF EXISTS `anomaly_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `anomaly_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `zone_id` int(11) NOT NULL,
  `reading_id` int(11) DEFAULT NULL,
  `anomaly_type` varchar(100) DEFAULT NULL,
  `expected_value` float DEFAULT NULL,
  `actual_value` float DEFAULT NULL,
  `deviation_pct` float DEFAULT NULL,
  `severity_score` float DEFAULT NULL,
  `ml_confidence` float DEFAULT NULL,
  `detected_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_resolved` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_anom_zone` (`zone_id`),
  KEY `idx_anom_det` (`detected_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `anomaly_log`
--

LOCK TABLES `anomaly_log` WRITE;
/*!40000 ALTER TABLE `anomaly_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `anomaly_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_log`
--

DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) DEFAULT NULL,
  `target` varchar(100) DEFAULT NULL,
  `detail` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_log`
--

LOCK TABLES `audit_log` WRITE;
/*!40000 ALTER TABLE `audit_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `billing`
--

DROP TABLE IF EXISTS `billing`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `billing` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `invoice_no` varchar(30) DEFAULT NULL,
  `litres` float DEFAULT 0,
  `rate_per_litre` decimal(8,4) DEFAULT 0.0500,
  `amount_kes` decimal(10,2) DEFAULT 0.00,
  `payment_method` varchar(30) DEFAULT 'M-Pesa',
  `mpesa_ref` varchar(50) DEFAULT NULL,
  `status` enum('paid','pending','failed') DEFAULT 'paid',
  `paid_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_no` (`invoice_no`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `billing_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `billing`
--

LOCK TABLES `billing` WRITE;
/*!40000 ALTER TABLE `billing` DISABLE KEYS */;
INSERT INTO `billing` VALUES (1,NULL,'INV-A09EC4D8',1000,0.0500,50.00,'M-Pesa',NULL,'paid','2026-03-08 12:46:40'),(2,5,'INV-8326BC81',2000,0.0500,100.00,'M-Pesa',NULL,'paid','2026-03-08 14:07:38');
/*!40000 ALTER TABLE `billing` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `complaints`
--

DROP TABLE IF EXISTS `complaints`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `complaints` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reporter_name` varchar(100) DEFAULT NULL,
  `reporter_phone` varchar(30) DEFAULT NULL,
  `zone_name` varchar(100) DEFAULT NULL,
  `zone_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `issue_type` varchar(100) DEFAULT NULL,
  `gps_lat` decimal(10,7) DEFAULT NULL,
  `gps_lng` decimal(10,7) DEFAULT NULL,
  `status` enum('open','in_progress','resolved') DEFAULT 'open',
  `assigned_to` varchar(100) DEFAULT NULL,
  `resolution_note` text DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `source` varchar(30) DEFAULT 'manual',
  `kobo_id` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_zone` (`zone_name`(50))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `complaints`
--

LOCK TABLES `complaints` WRITE;
/*!40000 ALTER TABLE `complaints` DISABLE KEYS */;
/*!40000 ALTER TABLE `complaints` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `consumption_log`
--

DROP TABLE IF EXISTS `consumption_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `consumption_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `zone_id` int(11) DEFAULT NULL,
  `litres_used` float DEFAULT 0,
  `consumed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `zone_id` (`zone_id`),
  CONSTRAINT `consumption_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `consumption_log_ibfk_2` FOREIGN KEY (`zone_id`) REFERENCES `water_zones` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `consumption_log`
--

LOCK TABLES `consumption_log` WRITE;
/*!40000 ALTER TABLE `consumption_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `consumption_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `decision_log`
--

DROP TABLE IF EXISTS `decision_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `decision_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `zone_id` int(11) DEFAULT NULL,
  `zone_name` varchar(100) DEFAULT NULL,
  `run_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actual_flow` decimal(8,2) DEFAULT NULL,
  `actual_pressure` decimal(8,2) DEFAULT NULL,
  `actual_level` decimal(5,2) DEFAULT NULL,
  `actual_ph` decimal(4,2) DEFAULT NULL,
  `actual_turbidity` decimal(6,2) DEFAULT NULL,
  `predicted_flow` decimal(8,2) DEFAULT NULL,
  `flow_deviation_pct` decimal(6,2) DEFAULT NULL,
  `anomaly_detected` tinyint(1) DEFAULT 0,
  `anomaly_types` varchar(255) DEFAULT NULL,
  `leak_probability` tinyint(4) DEFAULT 0,
  `leak_indicators` text DEFAULT NULL,
  `alert_triggered` tinyint(1) DEFAULT 0,
  `alert_type` varchar(100) DEFAULT NULL,
  `alert_severity` varchar(20) DEFAULT NULL,
  `failsafe_triggered` tinyint(1) DEFAULT 0,
  `failsafe_reason` varchar(255) DEFAULT NULL,
  `valve_command_pct` tinyint(4) DEFAULT 100,
  `command_issued` tinyint(1) DEFAULT 0,
  `command_reason` varchar(255) DEFAULT NULL,
  `engine_version` varchar(20) DEFAULT 'v2.0',
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dl_zone` (`zone_id`,`run_at`),
  KEY `idx_dl_time` (`run_at`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `decision_log`
--

LOCK TABLES `decision_log` WRITE;
/*!40000 ALTER TABLE `decision_log` DISABLE KEYS */;
INSERT INTO `decision_log` VALUES (1,1,'Zone A','2026-03-08 13:34:26',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,'',0,'',0,'','',0,'',100,0,'','v2.0','No sensor data'),(2,2,'Zone B','2026-03-08 13:34:26',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,'',0,'',0,'','',0,'',100,0,'','v2.0','No sensor data'),(3,3,'Zone C','2026-03-08 13:34:26',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,'',0,'',0,'','',0,'',100,0,'','v2.0','No sensor data'),(4,4,'Zone D','2026-03-08 13:34:26',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,'',0,'',0,'','',0,'',100,0,'','v2.0','No sensor data'),(5,5,'Zone E','2026-03-08 13:34:26',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,'',0,'',0,'','',0,'',100,0,'','v2.0','No sensor data'),(6,1,'Zone A','2026-03-08 14:41:01',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,'',0,'',0,'','',0,'',100,0,'','v2.0','No sensor data'),(7,2,'Zone B','2026-03-08 14:41:01',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,'',0,'',0,'','',0,'',100,0,'','v2.0','No sensor data'),(8,3,'Zone C','2026-03-08 14:41:01',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,'',0,'',0,'','',0,'',100,0,'','v2.0','No sensor data'),(9,4,'Zone D','2026-03-08 14:41:01',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,'',0,'',0,'','',0,'',100,0,'','v2.0','No sensor data'),(10,5,'Zone E','2026-03-08 14:41:01',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,'',0,'',0,'','',0,'',100,0,'','v2.0','No sensor data'),(11,1,'Zone A','2026-03-08 15:31:32',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,'',0,'',0,'','',0,'',100,0,'','v2.0','No sensor data'),(12,2,'Zone B','2026-03-08 15:31:32',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,'',0,'',0,'','',0,'',100,0,'','v2.0','No sensor data'),(13,3,'Zone C','2026-03-08 15:31:32',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,'',0,'',0,'','',0,'',100,0,'','v2.0','No sensor data'),(14,4,'Zone D','2026-03-08 15:31:32',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,'',0,'',0,'','',0,'',100,0,'','v2.0','No sensor data'),(15,5,'Zone E','2026-03-08 15:31:32',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,'',0,'',0,'','',0,'',100,0,'','v2.0','No sensor data'),(16,1,'Zone A','2026-03-08 18:09:47',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,'',0,'',0,'','',0,'',100,0,'','v2.0','No sensor data'),(17,2,'Zone B','2026-03-08 18:09:47',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,'',0,'',0,'','',0,'',100,0,'','v2.0','No sensor data'),(18,3,'Zone C','2026-03-08 18:09:47',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,'',0,'',0,'','',0,'',100,0,'','v2.0','No sensor data'),(19,4,'Zone D','2026-03-08 18:09:47',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,'',0,'',0,'','',0,'',100,0,'','v2.0','No sensor data'),(20,5,'Zone E','2026-03-08 18:09:47',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,'',0,'',0,'','',0,'',100,0,'','v2.0','No sensor data');
/*!40000 ALTER TABLE `decision_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `device_commands`
--

DROP TABLE IF EXISTS `device_commands`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `device_commands` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) NOT NULL,
  `command_type` enum('set_valve','set_pump','reboot','calibrate') NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `status` enum('pending','sent','acknowledged','failed') DEFAULT 'pending',
  `issued_by` int(11) DEFAULT NULL,
  `issued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ack_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `device_commands`
--

LOCK TABLES `device_commands` WRITE;
/*!40000 ALTER TABLE `device_commands` DISABLE KEYS */;
/*!40000 ALTER TABLE `device_commands` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `emergency_messages`
--

DROP TABLE IF EXISTS `emergency_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `emergency_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `severity` varchar(20) DEFAULT 'warning',
  `issue_type` varchar(100) DEFAULT 'Other',
  `status` varchar(30) DEFAULT 'open',
  `zone_name` varchar(100) DEFAULT NULL,
  `gps_lat` decimal(10,7) DEFAULT NULL,
  `gps_lng` decimal(10,7) DEFAULT NULL,
  `admin_response` text DEFAULT NULL,
  `responded_by` int(11) DEFAULT NULL,
  `responded_at` timestamp NULL DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `emergency_messages_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `emergency_messages`
--

LOCK TABLES `emergency_messages` WRITE;
/*!40000 ALTER TABLE `emergency_messages` DISABLE KEYS */;
INSERT INTO `emergency_messages` VALUES (1,NULL,'water is low in terms of pressure','warning','Low water pressure','open','Zone D',NULL,NULL,NULL,NULL,NULL,1,'2026-03-08 12:47:11'),(2,5,'valve is stuck in zone d','info','Valve stuck / not opening','resolved','Zone D',NULL,NULL,'we are experiencing issues but we are resolving it coming there please be patient',3,'2026-03-08 12:09:53',1,'2026-03-08 14:08:22');
/*!40000 ALTER TABLE `emergency_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hardware_devices`
--

DROP TABLE IF EXISTS `hardware_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hardware_devices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `zone_id` int(11) DEFAULT NULL,
  `device_name` varchar(100) NOT NULL,
  `device_type` enum('sensor_node','valve_controller','pump_controller','gateway') DEFAULT 'sensor_node',
  `device_code` varchar(50) NOT NULL,
  `api_key` varchar(64) NOT NULL,
  `firmware_version` varchar(20) DEFAULT '1.0.0',
  `ip_address` varchar(45) DEFAULT NULL,
  `is_online` tinyint(1) DEFAULT 0,
  `last_seen` timestamp NULL DEFAULT NULL,
  `battery_pct` tinyint(3) unsigned DEFAULT 100,
  `signal_strength` tinyint(4) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_code` (`device_code`),
  UNIQUE KEY `api_key` (`api_key`),
  KEY `zone_id` (`zone_id`),
  CONSTRAINT `hardware_devices_ibfk_1` FOREIGN KEY (`zone_id`) REFERENCES `water_zones` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hardware_devices`
--

LOCK TABLES `hardware_devices` WRITE;
/*!40000 ALTER TABLE `hardware_devices` DISABLE KEYS */;
/*!40000 ALTER TABLE `hardware_devices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `maintenance_logs`
--

DROP TABLE IF EXISTS `maintenance_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `maintenance_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `zone_id` int(11) DEFAULT NULL,
  `device_id` int(11) DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('scheduled','in_progress','completed') DEFAULT 'scheduled',
  `priority` enum('low','medium','high','critical') DEFAULT 'medium',
  `scheduled_date` date DEFAULT NULL,
  `completed_date` date DEFAULT NULL,
  `technician` varchar(100) DEFAULT NULL,
  `cost_kes` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `job_type` enum('pipe_repair','valve_replacement','sensor_calibration','filter_change','pump_service','tank_cleaning','meter_replacement','leak_repair','routine_check','other') DEFAULT 'routine_check',
  `technician_name` varchar(100) DEFAULT NULL,
  `cost_ksh` decimal(10,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `zone_id` (`zone_id`),
  KEY `device_id` (`device_id`),
  CONSTRAINT `maintenance_logs_ibfk_1` FOREIGN KEY (`zone_id`) REFERENCES `water_zones` (`id`) ON DELETE SET NULL,
  CONSTRAINT `maintenance_logs_ibfk_2` FOREIGN KEY (`device_id`) REFERENCES `hardware_devices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `maintenance_logs`
--

LOCK TABLES `maintenance_logs` WRITE;
/*!40000 ALTER TABLE `maintenance_logs` DISABLE KEYS */;
INSERT INTO `maintenance_logs` VALUES (1,4,NULL,'fix the burst pipe','try to cross check with the residents before leaving the site','scheduled','medium','2026-03-08',NULL,NULL,0.00,'2026-03-08 15:17:32','pipe_repair','mwangi',2500.00,NULL,3);
/*!40000 ALTER TABLE `maintenance_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `prediction_log_new`
--

DROP TABLE IF EXISTS `prediction_log_new`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prediction_log_new` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `zone_id` int(11) DEFAULT NULL,
  `zone_name` varchar(100) DEFAULT NULL,
  `run_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `rows_trained` int(11) DEFAULT 0,
  `forecast_days` int(11) DEFAULT 0,
  `anomalies_found` int(11) DEFAULT 0,
  `mae_flow` float DEFAULT NULL,
  `mae_level` float DEFAULT NULL,
  `model_version` varchar(50) DEFAULT NULL,
  `status` varchar(30) DEFAULT 'success',
  `error_msg` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pl_zone` (`zone_id`),
  KEY `idx_pl_run` (`run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `prediction_log_new`
--

LOCK TABLES `prediction_log_new` WRITE;
/*!40000 ALTER TABLE `prediction_log_new` DISABLE KEYS */;
/*!40000 ALTER TABLE `prediction_log_new` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `predictions`
--

DROP TABLE IF EXISTS `predictions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `predictions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `zone_id` int(11) NOT NULL,
  `predict_date` date NOT NULL,
  `predicted_flow` float DEFAULT NULL,
  `predicted_level` float DEFAULT NULL,
  `predicted_demand` float DEFAULT NULL,
  `confidence_pct` float DEFAULT NULL,
  `model_version` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_zone_date` (`zone_id`,`predict_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `predictions`
--

LOCK TABLES `predictions` WRITE;
/*!40000 ALTER TABLE `predictions` DISABLE KEYS */;
/*!40000 ALTER TABLE `predictions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sensor_readings`
--

DROP TABLE IF EXISTS `sensor_readings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sensor_readings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) DEFAULT NULL,
  `zone_id` int(11) DEFAULT NULL,
  `flow_rate` decimal(8,2) DEFAULT NULL,
  `pressure` decimal(8,2) DEFAULT NULL,
  `water_level` decimal(5,2) DEFAULT NULL,
  `temperature` decimal(5,2) DEFAULT NULL,
  `turbidity` decimal(6,2) DEFAULT NULL,
  `ph_level` decimal(4,2) DEFAULT NULL,
  `tds_ppm` int(11) DEFAULT NULL,
  `valve_open_pct` tinyint(4) DEFAULT 100,
  `pump_status` tinyint(1) DEFAULT 0,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `device_id` (`device_id`),
  KEY `idx_zone_time` (`zone_id`,`recorded_at`),
  CONSTRAINT `sensor_readings_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `hardware_devices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sensor_readings_ibfk_2` FOREIGN KEY (`zone_id`) REFERENCES `water_zones` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sensor_readings`
--

LOCK TABLES `sensor_readings` WRITE;
/*!40000 ALTER TABLE `sensor_readings` DISABLE KEYS */;
/*!40000 ALTER TABLE `sensor_readings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_val` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=44 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_settings`
--

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` VALUES (1,'system_name','SWDS Meru','Name of the system','2026-03-08 11:53:59'),(2,'water_rate_kes','0.05','Cost per litre in KES','2026-03-08 11:53:59'),(3,'alert_flow_min','10.0','Min flow rate L/min','2026-03-08 11:53:59'),(4,'alert_pressure_min','2.5','Min pressure Bar','2026-03-08 11:53:59'),(5,'alert_level_min','20','Min tank level percent','2026-03-08 11:53:59'),(6,'alert_ph_min','6.5','Min safe pH','2026-03-08 11:53:59'),(7,'alert_ph_max','8.5','Max safe pH','2026-03-08 11:53:59'),(8,'alert_turbidity_max','4.0','Max safe turbidity NTU','2026-03-08 11:53:59'),(9,'api_rate_limit','60','Max API calls per minute','2026-03-08 11:53:59'),(10,'prediction_days','7','Days ahead to predict','2026-03-08 11:53:59'),(41,'poll_interval_ms','15000','Live polling interval in milliseconds','2026-03-08 14:00:29'),(42,'auto_reload_interval','0','Full page reload interval in seconds (0 = off)','2026-03-08 14:00:29'),(43,'trend_chart_hours','24','Hours of history shown in valve control charts','2026-03-08 14:00:29');
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_notifications`
--

DROP TABLE IF EXISTS `user_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `title` varchar(200) DEFAULT NULL,
  `body` text DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `user_notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_notifications`
--

LOCK TABLES `user_notifications` WRITE;
/*!40000 ALTER TABLE `user_notifications` DISABLE KEYS */;
INSERT INTO `user_notifications` VALUES (1,5,'Admin replied to your report','we are experiencing issues but we are resolving it coming there please be patient',1,'2026-03-08 14:09:39'),(2,5,'Admin replied to your report','we are experiencing issues but we are resolving it coming there please be patient',1,'2026-03-08 14:09:53');
/*!40000 ALTER TABLE `user_notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','operator','user','viewer') DEFAULT 'user',
  `water_balance` float DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (3,'Adam','adam@gmail.com','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','admin',0,'2026-03-08 12:06:17'),(5,'yego','yego@gmail.com','$2y$10$Xi9lf29eXMsYYor6leS.k.cR2BKLWzv8eYAcrTYhwFAZTI2Zsanha','',2000,'2026-03-08 12:27:01'),(7,'ramadhan','ram@gmail.com','$2y$10$tAfihyuiAlIKcNOAFgG64uP6s3eh/byKmANZnrNg4MgOYkLQ3qUua','user',0,'2026-03-08 13:06:52'),(8,'tom karani','tom@gmail.com','$2y$10$/8StCF6rt0Oln8zV6VLEvOUEZpoAj9DMvIOplhIQ94C8VEkUuOReO','viewer',0,'2026-03-08 18:12:34');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `water_zones`
--

DROP TABLE IF EXISTS `water_zones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `water_zones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `zone_name` varchar(100) NOT NULL,
  `location` varchar(150) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `pipe_length_m` float DEFAULT NULL,
  `population` int(11) DEFAULT 0,
  `status` enum('active','inactive','maintenance') DEFAULT 'active',
  `valve_status` enum('OPEN','CLOSED') DEFAULT 'OPEN',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `water_zones`
--

LOCK TABLES `water_zones` WRITE;
/*!40000 ALTER TABLE `water_zones` DISABLE KEYS */;
INSERT INTO `water_zones` VALUES (1,'Zone A','North Meru',NULL,NULL,NULL,0,'active','CLOSED','2026-03-08 11:53:59'),(2,'Zone B','South Meru',NULL,NULL,NULL,0,'active','OPEN','2026-03-08 11:53:59'),(3,'Zone C','East Meru',NULL,NULL,NULL,0,'active','OPEN','2026-03-08 11:53:59'),(4,'Zone D','West Meru',NULL,NULL,NULL,0,'active','CLOSED','2026-03-08 11:53:59'),(5,'Zone E','Industrial Area',NULL,NULL,NULL,0,'active','OPEN','2026-03-08 11:53:59');
/*!40000 ALTER TABLE `water_zones` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-03-08 22:15:19
