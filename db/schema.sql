SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS shopping_list_item_shp;
DROP TABLE IF EXISTS pantry_item_pan;
DROP TABLE IF EXISTS rating_rat;
DROP TABLE IF EXISTS recipe_category_rcpcat;
DROP TABLE IF EXISTS category_cat;
DROP TABLE IF EXISTS category_type_cty;
DROP TABLE IF EXISTS recipe_ingredient_rcping;
DROP TABLE IF EXISTS unit_uni;
DROP TABLE IF EXISTS ingredient_ing;
DROP TABLE IF EXISTS recipe_step_stp;
DROP TABLE IF EXISTS recipe_image_img;
DROP TABLE IF EXISTS recipe_rcp;
DROP TABLE IF EXISTS user_role_usrrol;
DROP TABLE IF EXISTS role_rol;
DROP TABLE IF EXISTS user_usr;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================
-- Core lookup tables
-- =====================

CREATE TABLE role_rol (
  id_rol INT AUTO_INCREMENT PRIMARY KEY,
  name_rol VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE unit_uni (
  id_uni INT AUTO_INCREMENT PRIMARY KEY,
  name_uni VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE category_type_cty (
  id_cty INT AUTO_INCREMENT PRIMARY KEY,
  name_cty VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================
-- Users
-- =====================

CREATE TABLE user_usr (
  id_usr INT AUTO_INCREMENT PRIMARY KEY,
  username_usr VARCHAR(25) NOT NULL UNIQUE,
  email_usr VARCHAR(255) NOT NULL UNIQUE,
  password_hash_usr VARCHAR(255) NOT NULL,
  is_active_usr TINYINT(1) NOT NULL DEFAULT 1,
  created_at_usr TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_role_usrrol (
  id_usrrol INT AUTO_INCREMENT PRIMARY KEY,
  id_usr_usrrol INT NOT NULL,
  id_rol_usrrol INT NOT NULL,
  UNIQUE KEY uq_user_role (id_usr_usrrol, id_rol_usrrol),
  CONSTRAINT fk_usrrol_usr
    FOREIGN KEY (id_usr_usrrol) REFERENCES user_usr(id_usr),
  CONSTRAINT fk_usrrol_rol
    FOREIGN KEY (id_rol_usrrol) REFERENCES role_rol(id_rol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================
-- Recipes
-- =====================

CREATE TABLE recipe_rcp (
  id_rcp INT AUTO_INCREMENT PRIMARY KEY,
  id_usr_rcp INT NOT NULL,
  title_rcp VARCHAR(50) NOT NULL,
  description_rcp VARCHAR(255) NOT NULL,
  prep_time_minutes_rcp INT,
  cook_time_minutes_rcp INT,
  youtube_url_rcp VARCHAR(255),
  is_active_rcp TINYINT(1) NOT NULL DEFAULT 1,
  created_at_rcp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rcp_usr
    FOREIGN KEY (id_usr_rcp) REFERENCES user_usr(id_usr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recipe_image_img (
  id_img INT AUTO_INCREMENT PRIMARY KEY,
  id_rcp_img INT NOT NULL,
  image_path_img VARCHAR(255) NOT NULL,
  CONSTRAINT fk_img_rcp
    FOREIGN KEY (id_rcp_img) REFERENCES recipe_rcp(id_rcp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recipe_step_stp (
  id_stp INT AUTO_INCREMENT PRIMARY KEY,
  id_rcp_stp INT NOT NULL,
  step_number_stp INT NOT NULL,
  instruction_stp TEXT NOT NULL,
  CONSTRAINT fk_stp_rcp
    FOREIGN KEY (id_rcp_stp) REFERENCES recipe_rcp(id_rcp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================
-- Ingredients
-- =====================

CREATE TABLE ingredient_ing (
  id_ing INT AUTO_INCREMENT PRIMARY KEY,
  name_ing VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recipe_ingredient_rcping (
  id_rcping INT AUTO_INCREMENT PRIMARY KEY,
  id_rcp_rcping INT NOT NULL,
  id_ing_rcping INT NOT NULL,
  quantity_rcping DECIMAL(10,3) NOT NULL,
  id_uni_rcping INT NOT NULL,
  UNIQUE KEY uq_rcp_ing (id_rcp_rcping, id_ing_rcping),
  CONSTRAINT fk_rcping_rcp
    FOREIGN KEY (id_rcp_rcping) REFERENCES recipe_rcp(id_rcp),
  CONSTRAINT fk_rcping_ing
    FOREIGN KEY (id_ing_rcping) REFERENCES ingredient_ing(id_ing),
  CONSTRAINT fk_rcping_uni
    FOREIGN KEY (id_uni_rcping) REFERENCES unit_uni(id_uni)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================
-- Categories
-- =====================

CREATE TABLE category_cat (
  id_cat INT AUTO_INCREMENT PRIMARY KEY,
  name_cat VARCHAR(50) NOT NULL,
  id_cty_cat INT NOT NULL,
  CONSTRAINT fk_cat_cty
    FOREIGN KEY (id_cty_cat) REFERENCES category_type_cty(id_cty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recipe_category_rcpcat (
  id_rcpcat INT AUTO_INCREMENT PRIMARY KEY,
  id_rcp_rcpcat INT NOT NULL,
  id_cat_rcpcat INT NOT NULL,
  UNIQUE KEY uq_rcp_cat (id_rcp_rcpcat, id_cat_rcpcat),
  CONSTRAINT fk_rcpcat_rcp
    FOREIGN KEY (id_rcp_rcpcat) REFERENCES recipe_rcp(id_rcp),
  CONSTRAINT fk_rcpcat_cat
    FOREIGN KEY (id_cat_rcpcat) REFERENCES category_cat(id_cat)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================
-- Pantry & Shopping
-- =====================

CREATE TABLE pantry_item_pan (
  id_pan INT AUTO_INCREMENT PRIMARY KEY,
  id_usr_pan INT NOT NULL,
  id_ing_pan INT NOT NULL,
  quantity_pan DECIMAL(10,3) NOT NULL,
  id_uni_pan INT NOT NULL,
  expiration_date_pan DATE,
  created_at_pan TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pan_usr
    FOREIGN KEY (id_usr_pan) REFERENCES user_usr(id_usr),
  CONSTRAINT fk_pan_ing
    FOREIGN KEY (id_ing_pan) REFERENCES ingredient_ing(id_ing),
  CONSTRAINT fk_pan_uni
    FOREIGN KEY (id_uni_pan) REFERENCES unit_uni(id_uni)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE shopping_list_item_shp (
  id_shp INT AUTO_INCREMENT PRIMARY KEY,
  id_usr_shp INT NOT NULL,
  id_ing_shp INT NOT NULL,
  quantity_shp DECIMAL(10,3),
  id_uni_shp INT,
  is_purchased_shp TINYINT(1) NOT NULL DEFAULT 0,
  created_at_shp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_shp_usr
    FOREIGN KEY (id_usr_shp) REFERENCES user_usr(id_usr),
  CONSTRAINT fk_shp_ing
    FOREIGN KEY (id_ing_shp) REFERENCES ingredient_ing(id_ing),
  CONSTRAINT fk_shp_uni
    FOREIGN KEY (id_uni_shp) REFERENCES unit_uni(id_uni)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================
-- Ratings
-- =====================

CREATE TABLE rating_rat (
  id_rat INT AUTO_INCREMENT PRIMARY KEY,
  id_usr_rat INT NOT NULL,
  id_rcp_rat INT NOT NULL,
  score_rat TINYINT NOT NULL CHECK (score_rat BETWEEN 1 AND 5),
  review_rat TEXT,
  created_at_rat TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_usr_rcp (id_usr_rat, id_rcp_rat),
  CONSTRAINT fk_rat_usr
    FOREIGN KEY (id_usr_rat) REFERENCES user_usr(id_usr),
  CONSTRAINT fk_rat_rcp
    FOREIGN KEY (id_rcp_rat) REFERENCES recipe_rcp(id_rcp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================
-- Seed data
-- =====================

INSERT INTO role_rol (name_rol) VALUES ('admin'), ('user');

INSERT INTO unit_uni (name_uni) VALUES
  ('cups'), ('tablespoons'), ('teaspoons'), ('oz'), ('lbs'),
  ('grams'), ('ml'), ('liters'), ('pieces'), ('pinch');

INSERT INTO category_type_cty (name_cty) VALUES
  ('Protein'), ('Diet'), ('Cuisine'), ('Meal Type');

INSERT INTO category_cat (name_cat, id_cty_cat) VALUES
  ('Chicken', 1), ('Beef', 1), ('Pork', 1), ('Fish', 1), ('Tofu', 1),
  ('Vegan', 2), ('Vegetarian', 2), ('Keto', 2), ('Gluten-Free', 2),
  ('Italian', 3), ('Mexican', 3), ('Asian', 3), ('American', 3), ('Indian', 3),
  ('Breakfast', 4), ('Lunch', 4), ('Dinner', 4), ('Snack', 4), ('Dessert', 4);
