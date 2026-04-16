CREATE TABLE users (
    id VARCHAR(64) PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('donor', 'receiver') NOT NULL,
    organization VARCHAR(160) NULL,
    phone VARCHAR(30) NULL,
    latitude DECIMAL(10, 6) NULL,
    longitude DECIMAL(10, 6) NULL,
    radius_km INT NULL,
    created_at DATETIME NOT NULL
);

CREATE TABLE food_listings (
    id VARCHAR(64) PRIMARY KEY,
    donor_id VARCHAR(64) NOT NULL,
    donor_name VARCHAR(120) NOT NULL,
    title VARCHAR(180) NOT NULL,
    food_type VARCHAR(120) NOT NULL,
    quantity INT NOT NULL,
    unit VARCHAR(50) NOT NULL,
    latitude DECIMAL(10, 6) NOT NULL,
    longitude DECIMAL(10, 6) NOT NULL,
    address VARCHAR(255) NOT NULL,
    expiry_time DATETIME NOT NULL,
    description TEXT NULL,
    status ENUM('available', 'requested', 'accepted', 'completed', 'expired') NOT NULL,
    created_at DATETIME NOT NULL,
    completed_at DATETIME NULL
);

CREATE TABLE food_requests (
    id VARCHAR(64) PRIMARY KEY,
    listing_id VARCHAR(64) NOT NULL,
    receiver_id VARCHAR(64) NOT NULL,
    receiver_name VARCHAR(120) NOT NULL,
    requested_quantity INT NOT NULL,
    note VARCHAR(255) NULL,
    status ENUM('pending', 'accepted', 'rejected') NOT NULL,
    requested_at DATETIME NOT NULL,
    reviewed_at DATETIME NULL
);

CREATE TABLE notifications (
    id VARCHAR(64) PRIMARY KEY,
    user_id VARCHAR(64) NOT NULL,
    message VARCHAR(255) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL
);
