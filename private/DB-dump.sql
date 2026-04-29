-- Cookventory SQL dump
-- Updated to match the current application schema
-- Target database: `cookyjyv_cookventory`

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `cookyjyv_cookventory`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE `cookyjyv_cookventory`;

DROP TABLE IF EXISTS
  `user_role_usrrol`,
  `shopping_list_item_shli`,
  `recipe_save_rsv`,
  `recipe_step_stp`,
  `recipe_ingredient_rcping`,
  `recipe_image_img`,
  `recipe_category_rcpcat`,
  `rating_rat`,
  `pantry_item_pan`,
  `recipe_rcp`,
  `category_cat`,
  `role_rol`,
  `ingredient_ing`,
  `unit_uni`,
  `category_type_cty`,
  `user_usr`;

START TRANSACTION;

--
-- Table structure for table `user_usr`
--

CREATE TABLE `user_usr` (
  `id_usr` int(11) NOT NULL AUTO_INCREMENT,
  `username_usr` varchar(25) NOT NULL,
  `email_usr` varchar(255) NOT NULL,
  `password_hash_usr` varchar(255) NOT NULL,
  `is_active_usr` tinyint(1) NOT NULL DEFAULT 1,
  `created_at_usr` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_usr`),
  UNIQUE KEY `username_usr` (`username_usr`),
  UNIQUE KEY `email_usr` (`email_usr`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `role_rol`
--

CREATE TABLE `role_rol` (
  `id_rol` int(11) NOT NULL AUTO_INCREMENT,
  `name_rol` varchar(50) NOT NULL,
  PRIMARY KEY (`id_rol`),
  UNIQUE KEY `name_rol` (`name_rol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `role_rol` (`id_rol`, `name_rol`) VALUES
(1, 'user'),
(2, 'admin'),
(3, 'super_admin');

--
-- Table structure for table `user_role_usrrol`
--

CREATE TABLE `user_role_usrrol` (
  `id_usrrol` int(11) NOT NULL AUTO_INCREMENT,
  `id_usr_usrrol` int(11) NOT NULL,
  `id_rol_usrrol` int(11) NOT NULL,
  PRIMARY KEY (`id_usrrol`),
  UNIQUE KEY `uq_user_role` (`id_usr_usrrol`, `id_rol_usrrol`),
  KEY `fk_usrrol_rol` (`id_rol_usrrol`),
  CONSTRAINT `fk_usrrol_rol` FOREIGN KEY (`id_rol_usrrol`) REFERENCES `role_rol` (`id_rol`),
  CONSTRAINT `fk_usrrol_usr` FOREIGN KEY (`id_usr_usrrol`) REFERENCES `user_usr` (`id_usr`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `category_type_cty`
--

CREATE TABLE `category_type_cty` (
  `id_cty` int(11) NOT NULL AUTO_INCREMENT,
  `name_cty` varchar(50) NOT NULL,
  PRIMARY KEY (`id_cty`),
  UNIQUE KEY `name_cty` (`name_cty`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `category_type_cty` (`id_cty`, `name_cty`) VALUES
(1, 'course'),
(2, 'cuisine'),
(3, 'protein'),
(4, 'diet');

--
-- Table structure for table `category_cat`
--

CREATE TABLE `category_cat` (
  `id_cat` int(11) NOT NULL AUTO_INCREMENT,
  `name_cat` varchar(50) NOT NULL,
  `id_cty_cat` int(11) NOT NULL,
  PRIMARY KEY (`id_cat`),
  KEY `fk_cat_cty` (`id_cty_cat`),
  CONSTRAINT `fk_cat_cty` FOREIGN KEY (`id_cty_cat`) REFERENCES `category_type_cty` (`id_cty`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `category_cat` (`id_cat`, `name_cat`, `id_cty_cat`) VALUES
(1, 'Dessert', 1),
(2, 'Italian', 2),
(3, 'French', 2),
(4, 'Japanese', 2),
(5, 'Mexican', 2),
(6, 'Chicken', 3),
(7, 'Beef', 3),
(8, 'Pork', 3),
(9, 'Fish', 3),
(10, 'Vegetarian', 3),
(11, 'Vegan', 4),
(12, 'Gluten-Free', 4),
(13, 'Keto', 4),
(14, 'Breakfast', 1),
(15, 'Lunch', 1),
(16, 'Dinner', 1);

--
-- Table structure for table `unit_uni`
--

CREATE TABLE `unit_uni` (
  `id_uni` int(11) NOT NULL AUTO_INCREMENT,
  `name_uni` varchar(50) NOT NULL,
  `unit_type_uni` enum('volume','weight','count') DEFAULT NULL,
  `base_unit_uni` varchar(50) DEFAULT NULL,
  `conversion_to_base_uni` decimal(12,6) DEFAULT NULL,
  PRIMARY KEY (`id_uni`),
  UNIQUE KEY `name_uni` (`name_uni`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `unit_uni` (`id_uni`, `name_uni`, `unit_type_uni`, `base_unit_uni`, `conversion_to_base_uni`) VALUES
(1, 'cup', 'volume', 'milliliter', 236.588000),
(2, 'teaspoon', 'volume', 'milliliter', 4.928920),
(3, 'tablespoon', 'volume', 'milliliter', 14.786800),
(4, 'fluid ounce', 'volume', 'milliliter', 29.573500),
(5, 'pint', 'volume', 'milliliter', 473.176000),
(6, 'quart', 'volume', 'milliliter', 946.353000),
(7, 'gallon', 'volume', 'milliliter', 3785.412000),
(8, 'milliliter', 'volume', 'milliliter', 1.000000),
(9, 'liter', 'volume', 'milliliter', 1000.000000),
(10, 'ounce', 'weight', 'gram', 28.349500),
(11, 'pound', 'weight', 'gram', 453.592000),
(12, 'gram', 'weight', 'gram', 1.000000),
(13, 'kilogram', 'weight', 'gram', 1000.000000),
(14, 'each', 'count', 'each', 1.000000),
(15, 'piece', 'count', 'each', 1.000000),
(16, 'whole', 'count', 'each', 1.000000),
(17, 'slice', 'count', 'each', 1.000000),
(18, 'clove', 'count', 'each', 1.000000),
(19, 'can', 'count', 'each', 1.000000),
(20, 'jar', 'count', 'each', 1.000000),
(21, 'bottle', 'count', 'each', 1.000000),
(22, 'package', 'count', 'each', 1.000000),
(23, 'bag', 'count', 'each', 1.000000),
(24, 'box', 'count', 'each', 1.000000),
(25, 'stick', 'count', 'each', 1.000000),
(26, 'bunch', 'count', 'each', 1.000000),
(27, 'dozen', 'count', 'each', 12.000000);

--
-- Table structure for table `ingredient_ing`
--

CREATE TABLE `ingredient_ing` (
  `id_ing` int(11) NOT NULL AUTO_INCREMENT,
  `name_ing` varchar(100) NOT NULL,
  PRIMARY KEY (`id_ing`),
  UNIQUE KEY `name_ing` (`name_ing`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `recipe_rcp`
--

CREATE TABLE `recipe_rcp` (
  `id_rcp` int(11) NOT NULL AUTO_INCREMENT,
  `id_usr_rcp` int(11) NOT NULL,
  `title_rcp` varchar(50) NOT NULL,
  `description_rcp` varchar(255) NOT NULL,
  `prep_time_minutes_rcp` int(11) DEFAULT NULL,
  `cook_time_minutes_rcp` int(11) DEFAULT NULL,
  `servings_rcp` int(10) unsigned DEFAULT NULL,
  `youtube_url_rcp` varchar(255) DEFAULT NULL,
  `is_active_rcp` tinyint(1) NOT NULL DEFAULT 1,
  `created_at_rcp` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_rcp`),
  KEY `fk_rcp_usr` (`id_usr_rcp`),
  CONSTRAINT `fk_rcp_usr` FOREIGN KEY (`id_usr_rcp`) REFERENCES `user_usr` (`id_usr`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `recipe_image_img`
--

CREATE TABLE `recipe_image_img` (
  `id_img` int(11) NOT NULL AUTO_INCREMENT,
  `id_rcp_img` int(11) NOT NULL,
  `image_path_img` varchar(255) NOT NULL,
  PRIMARY KEY (`id_img`),
  KEY `fk_img_rcp` (`id_rcp_img`),
  CONSTRAINT `fk_img_rcp` FOREIGN KEY (`id_rcp_img`) REFERENCES `recipe_rcp` (`id_rcp`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `recipe_step_stp`
--

CREATE TABLE `recipe_step_stp` (
  `id_stp` int(11) NOT NULL AUTO_INCREMENT,
  `id_rcp_stp` int(11) NOT NULL,
  `step_number_stp` int(11) NOT NULL,
  `instruction_stp` text NOT NULL,
  PRIMARY KEY (`id_stp`),
  KEY `fk_stp_rcp` (`id_rcp_stp`),
  CONSTRAINT `fk_stp_rcp` FOREIGN KEY (`id_rcp_stp`) REFERENCES `recipe_rcp` (`id_rcp`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `recipe_ingredient_rcping`
--

CREATE TABLE `recipe_ingredient_rcping` (
  `id_rcping` int(11) NOT NULL AUTO_INCREMENT,
  `id_rcp_rcping` int(11) NOT NULL,
  `id_ing_rcping` int(11) NOT NULL,
  `quantity_rcping` decimal(10,3) NOT NULL,
  `id_uni_rcping` int(11) NOT NULL,
  PRIMARY KEY (`id_rcping`),
  UNIQUE KEY `uq_rcp_ing` (`id_rcp_rcping`, `id_ing_rcping`),
  KEY `fk_rcping_ing` (`id_ing_rcping`),
  KEY `fk_rcping_uni` (`id_uni_rcping`),
  CONSTRAINT `fk_rcping_ing` FOREIGN KEY (`id_ing_rcping`) REFERENCES `ingredient_ing` (`id_ing`),
  CONSTRAINT `fk_rcping_rcp` FOREIGN KEY (`id_rcp_rcping`) REFERENCES `recipe_rcp` (`id_rcp`) ON DELETE CASCADE,
  CONSTRAINT `fk_rcping_uni` FOREIGN KEY (`id_uni_rcping`) REFERENCES `unit_uni` (`id_uni`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `recipe_category_rcpcat`
--

CREATE TABLE `recipe_category_rcpcat` (
  `id_rcpcat` int(11) NOT NULL AUTO_INCREMENT,
  `id_rcp_rcpcat` int(11) NOT NULL,
  `id_cat_rcpcat` int(11) NOT NULL,
  PRIMARY KEY (`id_rcpcat`),
  UNIQUE KEY `uq_rcp_cat` (`id_rcp_rcpcat`, `id_cat_rcpcat`),
  KEY `fk_rcpcat_cat` (`id_cat_rcpcat`),
  CONSTRAINT `fk_rcpcat_cat` FOREIGN KEY (`id_cat_rcpcat`) REFERENCES `category_cat` (`id_cat`),
  CONSTRAINT `fk_rcpcat_rcp` FOREIGN KEY (`id_rcp_rcpcat`) REFERENCES `recipe_rcp` (`id_rcp`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `pantry_item_pan`
--

CREATE TABLE `pantry_item_pan` (
  `id_pan` int(11) NOT NULL AUTO_INCREMENT,
  `id_usr_pan` int(11) NOT NULL,
  `id_ing_pan` int(11) NOT NULL,
  `quantity_pan` decimal(10,3) NOT NULL,
  `id_uni_pan` int(11) NOT NULL,
  PRIMARY KEY (`id_pan`),
  KEY `fk_pan_usr` (`id_usr_pan`),
  KEY `fk_pan_ing` (`id_ing_pan`),
  KEY `fk_pan_uni` (`id_uni_pan`),
  CONSTRAINT `fk_pan_ing` FOREIGN KEY (`id_ing_pan`) REFERENCES `ingredient_ing` (`id_ing`),
  CONSTRAINT `fk_pan_uni` FOREIGN KEY (`id_uni_pan`) REFERENCES `unit_uni` (`id_uni`),
  CONSTRAINT `fk_pan_usr` FOREIGN KEY (`id_usr_pan`) REFERENCES `user_usr` (`id_usr`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `shopping_list_item_shli`
--

CREATE TABLE `shopping_list_item_shli` (
  `id_shli` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_usr_shli` int(10) unsigned NOT NULL,
  `id_ing_shli` int(10) unsigned NOT NULL,
  `quantity_shli` decimal(12,4) NOT NULL,
  `id_uni_shli` int(10) unsigned NOT NULL,
  `id_display_uni_shli` int(10) unsigned DEFAULT NULL,
  `created_at_shli` timestamp NULL DEFAULT current_timestamp(),
  `updated_at_shli` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_shli`),
  KEY `idx_shli_user` (`id_usr_shli`),
  KEY `idx_shli_ing` (`id_ing_shli`),
  KEY `idx_shli_uni` (`id_uni_shli`),
  KEY `idx_shli_display_uni` (`id_display_uni_shli`),
  CONSTRAINT `fk_shli_display_uni` FOREIGN KEY (`id_display_uni_shli`) REFERENCES `unit_uni` (`id_uni`) ON DELETE SET NULL,
  CONSTRAINT `fk_shli_ing` FOREIGN KEY (`id_ing_shli`) REFERENCES `ingredient_ing` (`id_ing`),
  CONSTRAINT `fk_shli_uni` FOREIGN KEY (`id_uni_shli`) REFERENCES `unit_uni` (`id_uni`),
  CONSTRAINT `fk_shli_usr` FOREIGN KEY (`id_usr_shli`) REFERENCES `user_usr` (`id_usr`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `recipe_save_rsv`
--

CREATE TABLE `recipe_save_rsv` (
  `id_rsv` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_usr_rsv` int(10) unsigned NOT NULL,
  `id_rcp_rsv` int(10) unsigned NOT NULL,
  `created_at_rsv` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_rsv`),
  UNIQUE KEY `uniq_user_recipe` (`id_usr_rsv`, `id_rcp_rsv`),
  KEY `idx_rsv_user` (`id_usr_rsv`),
  KEY `idx_rsv_recipe` (`id_rcp_rsv`),
  CONSTRAINT `fk_rsv_rcp` FOREIGN KEY (`id_rcp_rsv`) REFERENCES `recipe_rcp` (`id_rcp`) ON DELETE CASCADE,
  CONSTRAINT `fk_rsv_usr` FOREIGN KEY (`id_usr_rsv`) REFERENCES `user_usr` (`id_usr`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `rating_rat`
--

CREATE TABLE `rating_rat` (
  `id_rat` int(11) NOT NULL AUTO_INCREMENT,
  `id_rcp_rat` int(11) NOT NULL,
  `id_usr_rat` int(11) NOT NULL,
  `rating_value_rat` int(11) NOT NULL,
  `created_at_rat` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_rat`),
  UNIQUE KEY `uq_rating` (`id_rcp_rat`, `id_usr_rat`),
  KEY `fk_rat_usr` (`id_usr_rat`),
  CONSTRAINT `fk_rat_rcp` FOREIGN KEY (`id_rcp_rat`) REFERENCES `recipe_rcp` (`id_rcp`) ON DELETE CASCADE,
  CONSTRAINT `fk_rat_usr` FOREIGN KEY (`id_usr_rat`) REFERENCES `user_usr` (`id_usr`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
