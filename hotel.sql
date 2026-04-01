-- 1. Tabel BillInfo
DROP TABLE IF EXISTS `_bill_info`;
CREATE TABLE _bill_info (
    id INT(11) NOT NULL AUTO_INCREMENT,
    source_id VARCHAR(255) NOT NULL,
    session VARCHAR(255),
    branch_id INT(11) NOT NULL,
    bill_no VARCHAR(255),
    check_in_type_code VARCHAR(255),
    locked VARCHAR(255),
    lock_time VARCHAR(255),
    lock_host VARCHAR(255),
    lock_user_name VARCHAR(255),
    bill_state VARCHAR(255),
    bill_type VARCHAR(255),
    guest_no VARCHAR(255),
    guest_name VARCHAR(255),
    id_type VARCHAR(255),
    id_code VARCHAR(255),
    in_date VARCHAR(255),
    plan_out_date VARCHAR(255),
    real_out_date VARCHAR(255),
    bill_date VARCHAR(255),
    remark VARCHAR(255),
    calc_price_type VARCHAR(255),
    status VARCHAR(255),
    operator VARCHAR(255),
    sync_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Kapan data masuk ke VPS    
    PRIMARY KEY (id),
    UNIQUE KEY uniq_branch_source (branch_id, source_id)
);

-- 2. Tabel BillRooms
DROP TABLE IF EXISTS `_bill_rooms`;
CREATE TABLE _bill_rooms (
    id INT(11) NOT NULL AUTO_INCREMENT,
    source_id VARCHAR(255) NOT NULL,
    session VARCHAR(255),
    branch_id INT(11) NOT NULL,
    bill_no VARCHAR(255),
    building_code VARCHAR(255),
    layer_code VARCHAR(255),
    room_code VARCHAR(255),
    sub_room_code VARCHAR(255),
    room_name VARCHAR(255),
    room_type VARCHAR(255),
    price VARCHAR(255),
    in_date VARCHAR(255),
    plan_out_date VARCHAR(255),
    real_out_date VARCHAR(255),
    make_card_count VARCHAR(255),
    return_card_count VARCHAR(255),
    check_in_type_code VARCHAR(255),
    calc_price_type VARCHAR(255),
    status VARCHAR(255),
    area_1 VARCHAR(255),
    area_2 VARCHAR(255),
    check_out_without_card VARCHAR(255),
    check_in_without_card VARCHAR(255),
    remark VARCHAR(255),
    operator VARCHAR(255),
    ext_rooms VARCHAR(255),
    floor_list VARCHAR(255),
    pms_line_id VARCHAR(255),
    open_pwd VARCHAR(255),
    we_chat_code_url VARCHAR(255),
    phone_number VARCHAR(255),
    new_phone_number VARCHAR(255),
    sync_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Kapan data masuk ke VPS    
    PRIMARY KEY (id),
    UNIQUE KEY uniq_branch_source (branch_id, source_id)
);

-- 3. Tabel RoomInfo
DROP TABLE IF EXISTS `_room_info`;
CREATE TABLE _room_info (
    id INT(11) NOT NULL AUTO_INCREMENT,
    source_id VARCHAR(255) NOT NULL,
    session VARCHAR(255),
    branch_id INT(11) NOT NULL,
    room_info_uid VARCHAR(255),
    building_code VARCHAR(255),
    layer_code VARCHAR(255),
    room_code VARCHAR(255),
    sub_room_code VARCHAR(255),
    room_name VARCHAR(255),
    room_type VARCHAR(255),
    status_code VARCHAR(255),
    room_price VARCHAR(255),
    curr_card_count VARCHAR(255),
    max_card_count VARCHAR(255),
    is_gate VARCHAR(255),
    area_1 VARCHAR(255),
    area_2 VARCHAR(255),
    check_in_date VARCHAR(255),
    check_out_date VARCHAR(255),
    last_check_out_date VARCHAR(255),
    in_used VARCHAR(255),
    lock_time VARCHAR(255),
    lock_type VARCHAR(255),
    lock_no VARCHAR(255),
    is_chanel_mode VARCHAR(255),
    plan_check_in_date VARCHAR(255),
    plan_check_out_date VARCHAR(255),
    check_in_type_code VARCHAR(255),
    calc_price_type VARCHAR(255),
    plan_make_card_count VARCHAR(255),
    start_repair_date VARCHAR(255),
    area_3 VARCHAR(255),
    area_4 VARCHAR(255),
    bill_no VARCHAR(255),
    sync_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Kapan data masuk ke VPS    
    PRIMARY KEY (id),
    UNIQUE KEY uniq_branch_source (branch_id, source_id)
);

-- 4. Tabel Employees
DROP TABLE IF EXISTS `_employees`;
CREATE TABLE _employees (
    id INT(11) NOT NULL AUTO_INCREMENT,
    source_id VARCHAR(255) NOT NULL,
    session VARCHAR(255),
    branch_id INT(11) NOT NULL,
    emp_id VARCHAR(255),
    emp_name VARCHAR(255),
    dept_id VARCHAR(255),
    opt_psw VARCHAR(255),
    is_operator VARCHAR(255),
    add_datetime VARCHAR(255),
    remark VARCHAR(255),
    sys_id VARCHAR(255),
    sync_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Kapan data masuk ke VPS    
    PRIMARY KEY (id),
    UNIQUE KEY uniq_branch_source (branch_id, source_id)
);

-- 5. Tabel RoomType
DROP TABLE IF EXISTS `_room_type`;
CREATE TABLE _room_type (
    id INT(11) NOT NULL AUTO_INCREMENT,
    source_id VARCHAR(255) NOT NULL,
    session VARCHAR(255),
    branch_id INT(11) NOT NULL,
    room_type VARCHAR(255),
    max_card_count VARCHAR(255),
    room_price VARCHAR(255),
    default_make_card VARCHAR(255),
    remark VARCHAR(255),
    sync_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Kapan data masuk ke VPS
    PRIMARY KEY (id),
    UNIQUE KEY uniq_branch_source (branch_id, source_id)
);

-- 6. Tabel MakeCardRecord
DROP TABLE IF EXISTS `_make_card_record`;
CREATE TABLE _make_card_record (
    id INT(11) NOT NULL AUTO_INCREMENT,
    source_id VARCHAR(255) NOT NULL,
    session VARCHAR(255),
    branch_id INT(11) NOT NULL,
    auto_code VARCHAR(255),
    card_code VARCHAR(255),
    card_type VARCHAR(255),
    card_type_code VARCHAR(255),
    lost_card_code VARCHAR(255),
    user_id VARCHAR(255),
    user_name VARCHAR(255),
    id_type VARCHAR(255),
    id_code VARCHAR(255),
    into_room_sum_day VARCHAR(255),
    room_type VARCHAR(255),
    sync_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Kapan data masuk ke VPS
    PRIMARY KEY (id),
    UNIQUE KEY uniq_branch_source (branch_id, source_id)
);

-- 7. Tabel CardState
DROP TABLE IF EXISTS `_card_state`;
CREATE TABLE _card_state (
    id INT(11) NOT NULL AUTO_INCREMENT,
    source_id VARCHAR(255) NOT NULL,
    session VARCHAR(255),
    branch_id INT(11) NOT NULL,
    card_code VARCHAR(255),
    last_make_card_auto_code VARCHAR(255),
    state VARCHAR(255),
    sync_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Kapan data masuk ke VPS
    PRIMARY KEY (id),
    UNIQUE KEY uniq_branch_source (branch_id, source_id)
);

DROP TABLE IF EXISTS `branchs`;
CREATE TABLE IF NOT EXISTS `branchs` (
    `branch_id` INT(255) NOT NULL AUTO_INCREMENT,
    `branch_session` VARCHAR(255) NOT NULL COMMENT 'Unique Alphanumeric Session ID',
    `branch_text` VARCHAR(255) NULL COMMENT 'Short Text Input',
    `branch_textarea` TEXT NULL COMMENT 'Long Text/Description',
    `branch_numeric` DOUBLE(18,2) DEFAULT '0.00' COMMENT 'Numeric Value',
    `branch_flag` INT(5) DEFAULT '0' COMMENT 'Status: 1=Active, 0=Inactive, 4=Deleted',
    `branch_url` TEXT NULL COMMENT 'File or Image URL Path',
    `branch_date_created` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Auto-record Insertion',
    `branch_date_updated` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Auto-record Update',
    PRIMARY KEY (`branch_id`),
    UNIQUE INDEX `idx_branch_session` (`branch_session`) USING BTREE,
    INDEX `idx_branch_flag` (`branch_flag`) USING BTREE
) ENGINE=INNODB;
