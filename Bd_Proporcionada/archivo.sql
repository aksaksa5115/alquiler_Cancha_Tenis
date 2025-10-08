-- Creamos el esquema si no existe 
CREATE SCHEMA IF NOT EXISTS `seminariophp`; 
USE `seminariophp`; 
 
-- Tabla de usuarios 
CREATE TABLE users ( 
  id INT AUTO_INCREMENT PRIMARY KEY, 
  email VARCHAR(255) UNIQUE NOT NULL, 
  first_name VARCHAR(100) NOT NULL, 
  last_name VARCHAR(100) NOT NULL, 
  password VARCHAR(255) NOT NULL, 
  token VARCHAR(255) UNIQUE, 
  expired DATETIME, 
  is_admin BOOLEAN NOT NULL DEFAULT FALSE 
); 
 
-- Tabla de canchas 
CREATE TABLE courts ( 
  id INT AUTO_INCREMENT PRIMARY KEY, 
  name VARCHAR(100) NOT NULL, 
  description TEXT 
); 
 
-- Tabla de reservas 
CREATE TABLE bookings ( 
  id INT AUTO_INCREMENT PRIMARY KEY, 
  created_by INT NOT NULL, 
  court_id INT NOT NULL, 
  booking_datetime DATETIME NOT NULL, 
  duration_blocks INT NOT NULL, 
  FOREIGN KEY (created_by) REFERENCES users(id), 
  FOREIGN KEY (court_id) REFERENCES courts(id) 
); 
 
-- Tabla de participantes en cada reserva 
CREATE TABLE booking_participants ( 
  id INT AUTO_INCREMENT PRIMARY KEY, 
  booking_id INT NOT NULL, 
  user_id INT NOT NULL, 
  UNIQUE KEY unique_booking_user (booking_id, user_id), 
  FOREIGN KEY (booking_id) REFERENCES bookings(id), 
  FOREIGN KEY (user_id) REFERENCES users(id) 
);