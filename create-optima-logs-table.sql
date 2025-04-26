-- SQL script to create the wp_wc_optima_api_logs table
-- Replace 'wp_' with your actual table prefix if different

CREATE TABLE IF NOT EXISTS `wp_wc_optima_api_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `timestamp` datetime NOT NULL,
  `endpoint` varchar(255) NOT NULL,
  `request_method` varchar(10) NOT NULL,
  `request_data` longtext,
  `response_data` longtext,
  `status_code` int(5),
  `success` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
