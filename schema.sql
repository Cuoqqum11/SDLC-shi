-- MealDash schema for XAMPP. Creates the food_products database; does not modify the previous database.
-- Import this file in phpMyAdmin. It creates/uses food_products; no DROP statements are used.
CREATE DATABASE IF NOT EXISTS food_products CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE food_products;

CREATE TABLE IF NOT EXISTS roles (RoleID INT AUTO_INCREMENT PRIMARY KEY, RoleName VARCHAR(40) NOT NULL UNIQUE) ENGINE=InnoDB;
INSERT IGNORE INTO roles(RoleID,RoleName) VALUES (1,'Admin'),(2,'Customer'),(3,'Restaurant'),(4,'Driver');
CREATE TABLE IF NOT EXISTS users (
 UserID INT AUTO_INCREMENT PRIMARY KEY, FullName VARCHAR(120) NOT NULL DEFAULT '', UserName VARCHAR(60) NOT NULL UNIQUE,
 Password VARCHAR(255) NOT NULL, PhoneNumber VARCHAR(30) NULL, Avatar VARCHAR(255) NULL,
 Email VARCHAR(190) NOT NULL UNIQUE, RoleID INT NOT NULL DEFAULT 2, CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_users_role FOREIGN KEY(RoleID) REFERENCES roles(RoleID)
) ENGINE=InnoDB;
-- Safe additions for the original users table, which already has the listed account columns.
SET @q=(SELECT IF(COUNT(*)=0,'ALTER TABLE users ADD COLUMN FullName VARCHAR(120) NOT NULL DEFAULT '''' AFTER UserID','SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='FullName'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q=(SELECT IF(COUNT(*)=0,'ALTER TABLE users ADD COLUMN PhoneNumber VARCHAR(30) NULL','SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='PhoneNumber'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q=(SELECT IF(COUNT(*)=0,'ALTER TABLE users ADD COLUMN Avatar VARCHAR(255) NULL','SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='Avatar'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
ALTER TABLE users MODIFY COLUMN Password VARCHAR(255) NOT NULL;

CREATE TABLE IF NOT EXISTS categories (id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(80) NOT NULL UNIQUE,slug VARCHAR(90) NOT NULL UNIQUE) ENGINE=InnoDB;
INSERT IGNORE INTO categories(name,slug) VALUES ('Popular','popular'),('Burgers','burgers'),('Pizza','pizza'),('Asian','asian'),('Healthy','healthy'),('Desserts','desserts');
CREATE TABLE IF NOT EXISTS restaurants (
 id INT AUTO_INCREMENT PRIMARY KEY, owner_user_id INT NULL, name VARCHAR(140) NOT NULL, description TEXT,
 cuisine VARCHAR(100) NOT NULL DEFAULT 'Local favorites', address VARCHAR(255) NOT NULL DEFAULT '', delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
 rating DECIMAL(2,1) NOT NULL DEFAULT 4.5, image_url VARCHAR(500) NULL, is_open TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(owner_user_id) REFERENCES users(UserID) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS menu_items (
 id INT AUTO_INCREMENT PRIMARY KEY, restaurant_id INT NOT NULL, category_id INT NULL, name VARCHAR(140) NOT NULL,
 description TEXT, price DECIMAL(10,2) NOT NULL, stock INT NOT NULL DEFAULT 0, image_url VARCHAR(500) NULL, is_available TINYINT(1) NOT NULL DEFAULT 1,
 FOREIGN KEY(restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE, FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS orders (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, restaurant_id INT NOT NULL, driver_id INT NULL,
 status ENUM('Placed','Accepted','Preparing','Ready for pickup','Picked up','On the way','Delivered','Cancelled') NOT NULL DEFAULT 'Placed',
 subtotal DECIMAL(10,2) NOT NULL, delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0, total DECIMAL(10,2) NOT NULL,
 payment_method ENUM('Cash','Sandbox') NOT NULL DEFAULT 'Cash', payment_status ENUM('Pending','Sandbox approved','Failed','Cash due') NOT NULL DEFAULT 'Pending',
 delivery_address VARCHAR(255) NOT NULL, customer_note TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(customer_id) REFERENCES users(UserID), FOREIGN KEY(restaurant_id) REFERENCES restaurants(id), FOREIGN KEY(driver_id) REFERENCES users(UserID) ON DELETE SET NULL,
 INDEX idx_orders_customer(customer_id,created_at), INDEX idx_orders_status(status)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS order_items (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL, menu_item_id INT NULL, item_name VARCHAR(140) NOT NULL,
 unit_price DECIMAL(10,2) NOT NULL, quantity INT NOT NULL, FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 FOREIGN KEY(menu_item_id) REFERENCES menu_items(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS order_status_history (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL, status VARCHAR(40) NOT NULL, changed_by INT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE, FOREIGN KEY(changed_by) REFERENCES users(UserID) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS delivery_locations (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL, driver_id INT NOT NULL, latitude DECIMAL(10,7) NOT NULL, longitude DECIMAL(10,7) NOT NULL,
 recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE, FOREIGN KEY(driver_id) REFERENCES users(UserID) ON DELETE CASCADE, INDEX idx_location_order(order_id,recorded_at)
) ENGINE=InnoDB;
-- Optional sample content. Existing stores/items are not deleted or overwritten.
INSERT INTO restaurants(name,description,cuisine,address,delivery_fee,image_url)
SELECT 'Saigon Kitchen','Fresh Vietnamese favorites made to order.','Vietnamese','District 1',15000,'https://images.unsplash.com/photo-1555939594-58d7cb561ad1?w=900'
WHERE NOT EXISTS(SELECT 1 FROM restaurants WHERE name='Saigon Kitchen');
INSERT INTO restaurants(name,description,cuisine,address,delivery_fee,image_url)
SELECT 'Little Oven Pizza','Hand-stretched pizza and baked sides.','Pizza','District 3',20000,'https://images.unsplash.com/photo-1513104890138-7c749659a591?w=900'
WHERE NOT EXISTS(SELECT 1 FROM restaurants WHERE name='Little Oven Pizza');
INSERT INTO menu_items(restaurant_id,category_id,name,description,price,stock,image_url)
SELECT r.id,c.id,'Grilled pork rice bowl','Char-grilled pork, pickles, herbs and jasmine rice.',59000,30,'https://images.unsplash.com/photo-1512058564366-18510be2db19?w=700'
FROM restaurants r JOIN categories c ON c.slug='popular' WHERE r.name='Saigon Kitchen' AND NOT EXISTS(SELECT 1 FROM menu_items m WHERE m.restaurant_id=r.id AND m.name='Grilled pork rice bowl');
INSERT INTO menu_items(restaurant_id,category_id,name,description,price,stock,image_url)
SELECT r.id,c.id,'Margherita pizza','Tomato, mozzarella, basil and olive oil.',129000,20,'https://images.unsplash.com/photo-1574071318508-1cdbab80d002?w=700'
FROM restaurants r JOIN categories c ON c.slug='pizza' WHERE r.name='Little Oven Pizza' AND NOT EXISTS(SELECT 1 FROM menu_items m WHERE m.restaurant_id=r.id AND m.name='Margherita pizza');
