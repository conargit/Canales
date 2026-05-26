-- WhatsApp SaaS Platform - Database Schema
-- Optimized for MySQL / MariaDB

CREATE DATABASE IF NOT EXISTS whatsapp;
USE whatsapp;

-- 1. Branches / Companies
CREATE TABLE IF NOT EXISTS sucursales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. User Roles
CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL
) ENGINE=InnoDB;

-- 3. Users
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    id_rol INT,
    id_sucursal INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_rol) REFERENCES roles(id) ON DELETE SET NULL,
    FOREIGN KEY (id_sucursal) REFERENCES sucursales(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4. General Settings (isolated by branch)
CREATE TABLE IF NOT EXISTS ajustes (
    clave VARCHAR(255),
    valor TEXT,
    id_sucursal INT,
    PRIMARY KEY (clave, id_sucursal),
    FOREIGN KEY (id_sucursal) REFERENCES sucursales(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 5. WhatsApp Instances (multiple per branch)
CREATE TABLE IF NOT EXISTS instancias_wa (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_sucursal INT,
    nombre_identificador VARCHAR(255),
    instance_name VARCHAR(255),
    gateway_url VARCHAR(255),
    api_key VARCHAR(255),
    webhook_token VARCHAR(255),
    estado VARCHAR(50) DEFAULT 'desconectado',
    FOREIGN KEY (id_sucursal) REFERENCES sucursales(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 6. AI Memory (Knowledge Base)
CREATE TABLE IF NOT EXISTS memoria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_sucursal INT,
    tipo VARCHAR(50),
    fuente VARCHAR(255),
    contenido LONGTEXT,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_sucursal) REFERENCES sucursales(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 7. Conversations
CREATE TABLE IF NOT EXISTS chats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_instancia INT,
    remitente VARCHAR(50),
    mensaje TEXT,
    respuesta TEXT,
    modo VARCHAR(20) DEFAULT 'auto',
    id_usuario_asignado INT DEFAULT 0,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_instancia) REFERENCES instancias_wa(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 8. Marketing Campaigns
CREATE TABLE IF NOT EXISTS campanas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_instancia INT,
    nombre VARCHAR(255),
    mensaje TEXT,
    destinatarios TEXT,
    estado VARCHAR(50) DEFAULT 'pendiente',
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_instancia) REFERENCES instancias_wa(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- INITIAL DATA SEEDING
-- (Note: Password for admin@admin.com is 'admin123')
INSERT INTO roles (id, nombre) VALUES (1, 'Admin'), (2, 'Operador');
INSERT INTO sucursales (id, nombre) VALUES (1, 'Empresa Principal');
INSERT INTO usuarios (nombre, email, password, id_rol, id_sucursal)
VALUES ('Administrador', 'admin@admin.com', '$2y$10$aOvK2n0w012p//JfdXJ9we3iUWnai8dix4up27lMNuucuxI.RVcYW', 1, 1);
