-- One row per check: the article, its TL;DR and Laya's scores, with the
-- full reading as JSON. Welcome_model creates the table on the first save
-- when it is missing.
CREATE TABLE IF NOT EXISTS `evaluations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `url` varchar(2048) DEFAULT NULL,
  `url_hash` char(64) DEFAULT NULL,
  `title` varchar(500) NOT NULL DEFAULT '',
  `tldr` text NOT NULL,
  `verdict` varchar(16) DEFAULT NULL,
  `slop` decimal(5,4) DEFAULT NULL,
  `substance` decimal(4,3) DEFAULT NULL,
  `substance_label` varchar(64) DEFAULT NULL,
  `laya_error` text DEFAULT NULL,
  `counts` text NOT NULL,
  `stock_words` text NOT NULL,
  `sentences` mediumtext NOT NULL,
  `summary` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `url_hash` (`url_hash`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
