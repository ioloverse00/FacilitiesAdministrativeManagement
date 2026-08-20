-- ISMERS FAM aligned schema | MySQL 8.0+
CREATE DATABASE IF NOT EXISTS ismers_fam CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE ismers_fam;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- INTEGRATION REFERENCES
CREATE TABLE external_system (
  external_system_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  system_code VARCHAR(50) NOT NULL UNIQUE,
  system_name VARCHAR(150) NOT NULL,
  owner_group VARCHAR(150), integration_type VARCHAR(50),
  status VARCHAR(30) NOT NULL DEFAULT 'PLANNED',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE department_reference (
  department_reference_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  external_department_id VARCHAR(100) NOT NULL,
  department_code VARCHAR(50) NOT NULL,
  department_name VARCHAR(150) NOT NULL,
  source_system VARCHAR(50) NOT NULL DEFAULT 'HRIS',
  sync_status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
  last_synced_at DATETIME, external_updated_at DATETIME,
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_department_external(source_system,external_department_id),
  UNIQUE KEY uq_department_code(source_system,department_code)
) ENGINE=InnoDB;

CREATE TABLE employee_reference (
  employee_reference_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  external_employee_id VARCHAR(100) NOT NULL,
  employee_number VARCHAR(50) NOT NULL,
  full_name VARCHAR(200) NOT NULL,
  department_reference_id BIGINT UNSIGNED,
  position_title VARCHAR(150), email_address VARCHAR(190), contact_number VARCHAR(50),
  employment_status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  source_system VARCHAR(50) NOT NULL DEFAULT 'HRIS',
  sync_status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
  last_synced_at DATETIME, external_updated_at DATETIME,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME,
  CONSTRAINT fk_employee_department FOREIGN KEY(department_reference_id)
    REFERENCES department_reference(department_reference_id) ON UPDATE CASCADE ON DELETE SET NULL,
  UNIQUE KEY uq_employee_external(source_system,external_employee_id),
  UNIQUE KEY uq_employee_number(source_system,employee_number),
  INDEX idx_employee_department(department_reference_id)
) ENGINE=InnoDB;

CREATE TABLE budget_reference (
  budget_reference_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  external_budget_id VARCHAR(100) NOT NULL,
  budget_code VARCHAR(50) NOT NULL, budget_name VARCHAR(200) NOT NULL,
  fiscal_year SMALLINT UNSIGNED NOT NULL,
  allocated_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  committed_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  available_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  currency_code CHAR(3) NOT NULL DEFAULT 'PHP',
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  source_system VARCHAR(50) NOT NULL DEFAULT 'FMS',
  sync_status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
  last_synced_at DATETIME, external_updated_at DATETIME,
  UNIQUE KEY uq_budget_external(source_system,external_budget_id),
  UNIQUE KEY uq_budget_code_year(source_system,budget_code,fiscal_year),
  CHECK(allocated_amount>=0 AND committed_amount>=0 AND available_amount>=0)
) ENGINE=InnoDB;

CREATE TABLE supplier_reference (
  supplier_reference_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  external_supplier_id VARCHAR(100) NOT NULL,
  supplier_code VARCHAR(50) NOT NULL, supplier_name VARCHAR(200) NOT NULL,
  contact_person VARCHAR(150), contact_number VARCHAR(50), email_address VARCHAR(190),
  supplier_status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  source_system VARCHAR(50) NOT NULL DEFAULT 'SCM',
  sync_status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
  last_synced_at DATETIME, external_updated_at DATETIME,
  UNIQUE KEY uq_supplier_external(source_system,external_supplier_id),
  UNIQUE KEY uq_supplier_code(source_system,supplier_code)
) ENGINE=InnoDB;

CREATE TABLE inventory_item_reference (
  inventory_item_reference_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  external_item_id VARCHAR(100) NOT NULL,
  item_code VARCHAR(80) NOT NULL, item_name VARCHAR(255) NOT NULL,
  unit_of_measure VARCHAR(50), inventory_status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  source_system VARCHAR(50) NOT NULL DEFAULT 'SCM',
  sync_status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
  last_synced_at DATETIME, external_updated_at DATETIME,
  UNIQUE KEY uq_item_external(source_system,external_item_id),
  UNIQUE KEY uq_item_code(source_system,item_code)
) ENGINE=InnoDB;

CREATE TABLE vehicle_reference (
  vehicle_reference_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  external_vehicle_id VARCHAR(100) NOT NULL,
  vehicle_code VARCHAR(50) NOT NULL, plate_number VARCHAR(30) NOT NULL,
  vehicle_type VARCHAR(100) NOT NULL, capacity SMALLINT UNSIGNED,
  vehicle_status VARCHAR(30) NOT NULL DEFAULT 'AVAILABLE',
  source_system VARCHAR(50) NOT NULL DEFAULT 'FLEET',
  sync_status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
  last_synced_at DATETIME, external_updated_at DATETIME,
  UNIQUE KEY uq_vehicle_external(source_system,external_vehicle_id),
  UNIQUE KEY uq_vehicle_code(source_system,vehicle_code),
  UNIQUE KEY uq_vehicle_plate(source_system,plate_number)
) ENGINE=InnoDB;

CREATE TABLE integration_outbox (
  integration_outbox_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_uuid CHAR(36) NOT NULL UNIQUE,
  destination_system_code VARCHAR(50) NOT NULL,
  source_module VARCHAR(50) NOT NULL, event_type VARCHAR(100) NOT NULL,
  entity_type VARCHAR(100) NOT NULL, entity_id BIGINT UNSIGNED NOT NULL,
  payload_json JSON NOT NULL,
  delivery_status VARCHAR(30) NOT NULL DEFAULT 'PENDING', retry_count INT UNSIGNED NOT NULL DEFAULT 0,
  next_retry_at DATETIME, published_at DATETIME, last_error_message TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_outbox_delivery(delivery_status,next_retry_at,created_at)
) ENGINE=InnoDB;

-- USERS / RBAC
CREATE TABLE user_account (
  user_account_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_reference_id BIGINT UNSIGNED NOT NULL UNIQUE,
  username VARCHAR(100) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
  account_status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  last_login_at DATETIME, failed_login_count INT UNSIGNED NOT NULL DEFAULT 0, locked_until DATETIME,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME,
  CONSTRAINT fk_user_employee FOREIGN KEY(employee_reference_id)
    REFERENCES employee_reference(employee_reference_id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE role (
  role_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_code VARCHAR(80) NOT NULL UNIQUE, role_name VARCHAR(150) NOT NULL,
  description TEXT, status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE'
) ENGINE=InnoDB;

CREATE TABLE permission (
  permission_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  permission_code VARCHAR(120) NOT NULL UNIQUE,
  permission_name VARCHAR(180) NOT NULL, module_code VARCHAR(50) NOT NULL, description TEXT
) ENGINE=InnoDB;

CREATE TABLE user_role (
  user_role_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_account_id BIGINT UNSIGNED NOT NULL, role_id BIGINT UNSIGNED NOT NULL,
  assigned_by_user_id BIGINT UNSIGNED, assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, expires_at DATETIME,
  CONSTRAINT fk_user_role_user FOREIGN KEY(user_account_id) REFERENCES user_account(user_account_id) ON DELETE CASCADE,
  CONSTRAINT fk_user_role_role FOREIGN KEY(role_id) REFERENCES role(role_id) ON DELETE RESTRICT,
  CONSTRAINT fk_user_role_assigner FOREIGN KEY(assigned_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  UNIQUE KEY uq_user_role(user_account_id,role_id)
) ENGINE=InnoDB;

CREATE TABLE role_permission (
  role_permission_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id BIGINT UNSIGNED NOT NULL, permission_id BIGINT UNSIGNED NOT NULL,
  CONSTRAINT fk_role_permission_role FOREIGN KEY(role_id) REFERENCES role(role_id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permission_permission FOREIGN KEY(permission_id) REFERENCES permission(permission_id) ON DELETE CASCADE,
  UNIQUE KEY uq_role_permission(role_id,permission_id)
) ENGINE=InnoDB;

-- FACILITY MASTER DATA
CREATE TABLE building (
  building_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  building_code VARCHAR(50) NOT NULL UNIQUE, building_name VARCHAR(200) NOT NULL,
  address VARCHAR(500), description TEXT, status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME
) ENGINE=InnoDB;

CREATE TABLE facility_space (
  facility_space_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  building_id BIGINT UNSIGNED NOT NULL, parent_space_id BIGINT UNSIGNED,
  space_code VARCHAR(50) NOT NULL UNIQUE, space_name VARCHAR(200) NOT NULL,
  space_type VARCHAR(100) NOT NULL, floor_number VARCHAR(20), capacity INT UNSIGNED,
  location_description VARCHAR(500), is_reservable BOOLEAN NOT NULL DEFAULT TRUE,
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME,
  CONSTRAINT fk_space_building FOREIGN KEY(building_id) REFERENCES building(building_id) ON DELETE RESTRICT,
  CONSTRAINT fk_space_parent FOREIGN KEY(parent_space_id) REFERENCES facility_space(facility_space_id) ON DELETE SET NULL,
  INDEX idx_space_building(building_id), INDEX idx_space_type_status(space_type,status),
  CHECK(capacity IS NULL OR capacity>0)
) ENGINE=InnoDB;

-- SETTINGS
CREATE TABLE request_category (
  request_category_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_code VARCHAR(50) NOT NULL UNIQUE, category_name VARCHAR(150) NOT NULL,
  description TEXT, default_priority VARCHAR(30), responsible_role_code VARCHAR(80),
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE'
) ENGINE=InnoDB;

CREATE TABLE sla_policy (
  sla_policy_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  policy_code VARCHAR(50) NOT NULL UNIQUE, policy_name VARCHAR(150) NOT NULL,
  request_category_id BIGINT UNSIGNED, priority VARCHAR(30) NOT NULL,
  acknowledgement_minutes INT UNSIGNED, assignment_minutes INT UNSIGNED,
  resolution_minutes INT UNSIGNED, escalation_minutes INT UNSIGNED,
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE', effective_from DATE NOT NULL, effective_to DATE,
  CONSTRAINT fk_sla_category FOREIGN KEY(request_category_id) REFERENCES request_category(request_category_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE system_setting (
  system_setting_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(150) NOT NULL UNIQUE, setting_value TEXT,
  value_type VARCHAR(30) NOT NULL DEFAULT 'STRING', description TEXT,
  is_public BOOLEAN NOT NULL DEFAULT FALSE, updated_by_user_id BIGINT UNSIGNED,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_setting_user FOREIGN KEY(updated_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- DOCUMENTS / RECORDS
CREATE TABLE document_category (
  document_category_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_code VARCHAR(50) NOT NULL UNIQUE, category_name VARCHAR(150) NOT NULL,
  description TEXT, default_confidentiality_level VARCHAR(30) NOT NULL DEFAULT 'INTERNAL',
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE'
) ENGINE=InnoDB;

CREATE TABLE document (
  document_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_number VARCHAR(60) NOT NULL UNIQUE,
  document_category_id BIGINT UNSIGNED NOT NULL,
  document_title VARCHAR(255) NOT NULL, document_description TEXT,
  document_status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  confidentiality_level VARCHAR(30) NOT NULL DEFAULT 'INTERNAL',
  current_version_number INT UNSIGNED NOT NULL DEFAULT 1,
  document_date DATE, effective_date DATE, expiration_date DATE,
  uploaded_by_user_id BIGINT UNSIGNED NOT NULL,
  owner_employee_reference_id BIGINT UNSIGNED,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME,
  CONSTRAINT fk_document_category FOREIGN KEY(document_category_id) REFERENCES document_category(document_category_id) ON DELETE RESTRICT,
  CONSTRAINT fk_document_uploader FOREIGN KEY(uploaded_by_user_id) REFERENCES user_account(user_account_id) ON DELETE RESTRICT,
  CONSTRAINT fk_document_owner FOREIGN KEY(owner_employee_reference_id) REFERENCES employee_reference(employee_reference_id) ON DELETE SET NULL,
  INDEX idx_document_expiration(expiration_date)
) ENGINE=InnoDB;

CREATE TABLE document_version (
  document_version_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id BIGINT UNSIGNED NOT NULL, version_number INT UNSIGNED NOT NULL,
  file_name VARCHAR(255) NOT NULL, file_extension VARCHAR(20), mime_type VARCHAR(150),
  file_size BIGINT UNSIGNED, storage_path VARCHAR(1000) NOT NULL, file_hash VARCHAR(128),
  change_summary TEXT, uploaded_by_user_id BIGINT UNSIGNED NOT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, is_current BOOLEAN NOT NULL DEFAULT FALSE,
  deleted_at DATETIME,
  CONSTRAINT fk_document_version_document FOREIGN KEY(document_id) REFERENCES document(document_id) ON DELETE RESTRICT,
  CONSTRAINT fk_document_version_user FOREIGN KEY(uploaded_by_user_id) REFERENCES user_account(user_account_id) ON DELETE RESTRICT,
  UNIQUE KEY uq_document_version(document_id,version_number),
  INDEX idx_document_version_current(document_id,is_current)
) ENGINE=InnoDB;

-- FACILITY REQUESTS
CREATE TABLE facility_request (
  facility_request_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_number VARCHAR(60) NOT NULL UNIQUE,
  requested_by_employee_reference_id BIGINT UNSIGNED NOT NULL,
  department_reference_id BIGINT UNSIGNED, facility_space_id BIGINT UNSIGNED,
  request_category_id BIGINT UNSIGNED NOT NULL, sla_policy_id BIGINT UNSIGNED,
  subject VARCHAR(255) NOT NULL, description TEXT NOT NULL,
  priority VARCHAR(30) NOT NULL DEFAULT 'NORMAL', source_channel VARCHAR(30) NOT NULL DEFAULT 'WEB',
  status VARCHAR(30) NOT NULL DEFAULT 'SUBMITTED', approval_status VARCHAR(30) NOT NULL DEFAULT 'NOT_REQUIRED',
  assigned_to_employee_reference_id BIGINT UNSIGNED,
  requested_completion_at DATETIME, acknowledged_at DATETIME, assigned_at DATETIME,
  started_at DATETIME, completed_at DATETIME, verified_at DATETIME, closed_at DATETIME,
  cancelled_at DATETIME, cancellation_reason TEXT, rejection_reason TEXT, resolution_summary TEXT,
  created_by_user_id BIGINT UNSIGNED, updated_by_user_id BIGINT UNSIGNED,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME,
  CONSTRAINT fk_request_requester FOREIGN KEY(requested_by_employee_reference_id) REFERENCES employee_reference(employee_reference_id) ON DELETE RESTRICT,
  CONSTRAINT fk_request_department FOREIGN KEY(department_reference_id) REFERENCES department_reference(department_reference_id) ON DELETE SET NULL,
  CONSTRAINT fk_request_space FOREIGN KEY(facility_space_id) REFERENCES facility_space(facility_space_id) ON DELETE SET NULL,
  CONSTRAINT fk_request_category FOREIGN KEY(request_category_id) REFERENCES request_category(request_category_id) ON DELETE RESTRICT,
  CONSTRAINT fk_request_sla FOREIGN KEY(sla_policy_id) REFERENCES sla_policy(sla_policy_id) ON DELETE SET NULL,
  CONSTRAINT fk_request_assignee FOREIGN KEY(assigned_to_employee_reference_id) REFERENCES employee_reference(employee_reference_id) ON DELETE SET NULL,
  CONSTRAINT fk_request_creator FOREIGN KEY(created_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  CONSTRAINT fk_request_updater FOREIGN KEY(updated_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  INDEX idx_request_dashboard(status,priority,created_at), INDEX idx_request_assignee(assigned_to_employee_reference_id)
) ENGINE=InnoDB;

CREATE TABLE facility_request_history (
  facility_request_history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  facility_request_id BIGINT UNSIGNED NOT NULL,
  old_status VARCHAR(30), new_status VARCHAR(30) NOT NULL,
  changed_by_user_id BIGINT UNSIGNED, change_reason TEXT,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_request_history_request FOREIGN KEY(facility_request_id) REFERENCES facility_request(facility_request_id) ON DELETE CASCADE,
  CONSTRAINT fk_request_history_user FOREIGN KEY(changed_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  INDEX idx_request_history(facility_request_id,changed_at)
) ENGINE=InnoDB;

CREATE TABLE sla_tracking (
  sla_tracking_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  facility_request_id BIGINT UNSIGNED NOT NULL UNIQUE,
  acknowledgement_due_at DATETIME, assignment_due_at DATETIME, resolution_due_at DATETIME,
  acknowledged_at DATETIME, assigned_at DATETIME, resolved_at DATETIME,
  acknowledgement_breached BOOLEAN NOT NULL DEFAULT FALSE,
  assignment_breached BOOLEAN NOT NULL DEFAULT FALSE,
  resolution_breached BOOLEAN NOT NULL DEFAULT FALSE,
  breach_reason TEXT, last_evaluated_at DATETIME,
  CONSTRAINT fk_sla_tracking_request FOREIGN KEY(facility_request_id) REFERENCES facility_request(facility_request_id) ON DELETE CASCADE,
  INDEX idx_sla_due(resolution_due_at,resolution_breached)
) ENGINE=InnoDB;

-- ASSETS
CREATE TABLE asset_category (
  asset_category_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_code VARCHAR(50) NOT NULL UNIQUE, category_name VARCHAR(150) NOT NULL,
  description TEXT, default_useful_life_years SMALLINT UNSIGNED,
  default_maintenance_interval_days INT UNSIGNED, status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE'
) ENGINE=InnoDB;

CREATE TABLE asset (
  asset_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  asset_code VARCHAR(60) NOT NULL UNIQUE, property_number VARCHAR(80) UNIQUE,
  asset_category_id BIGINT UNSIGNED NOT NULL, asset_name VARCHAR(255) NOT NULL,
  description TEXT, serial_number VARCHAR(120), brand VARCHAR(100), model VARCHAR(120),
  supplier_reference_id BIGINT UNSIGNED, acquisition_date DATE, acquisition_cost DECIMAL(18,2),
  currency_code CHAR(3) NOT NULL DEFAULT 'PHP', facility_space_id BIGINT UNSIGNED,
  custodian_employee_reference_id BIGINT UNSIGNED,
  condition_status VARCHAR(30) NOT NULL DEFAULT 'GOOD', lifecycle_status VARCHAR(30) NOT NULL DEFAULT 'AVAILABLE',
  warranty_start_date DATE, warranty_end_date DATE, useful_life_years SMALLINT UNSIGNED,
  maintenance_interval_days INT UNSIGNED, last_maintenance_date DATE, next_maintenance_date DATE,
  retirement_date DATE, qr_code_value VARCHAR(255) UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME,
  CONSTRAINT fk_asset_category FOREIGN KEY(asset_category_id) REFERENCES asset_category(asset_category_id) ON DELETE RESTRICT,
  CONSTRAINT fk_asset_supplier FOREIGN KEY(supplier_reference_id) REFERENCES supplier_reference(supplier_reference_id) ON DELETE SET NULL,
  CONSTRAINT fk_asset_space FOREIGN KEY(facility_space_id) REFERENCES facility_space(facility_space_id) ON DELETE SET NULL,
  CONSTRAINT fk_asset_custodian FOREIGN KEY(custodian_employee_reference_id) REFERENCES employee_reference(employee_reference_id) ON DELETE SET NULL,
  INDEX idx_asset_dashboard(condition_status,lifecycle_status,next_maintenance_date),
  CHECK(acquisition_cost IS NULL OR acquisition_cost>=0)
) ENGINE=InnoDB;

CREATE TABLE asset_history (
  asset_history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  asset_id BIGINT UNSIGNED NOT NULL, event_type VARCHAR(50) NOT NULL,
  old_space_id BIGINT UNSIGNED, new_space_id BIGINT UNSIGNED,
  old_condition_status VARCHAR(30), new_condition_status VARCHAR(30),
  old_lifecycle_status VARCHAR(30), new_lifecycle_status VARCHAR(30),
  remarks TEXT, changed_by_user_id BIGINT UNSIGNED,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_asset_history_asset FOREIGN KEY(asset_id) REFERENCES asset(asset_id) ON DELETE CASCADE,
  CONSTRAINT fk_asset_history_old_space FOREIGN KEY(old_space_id) REFERENCES facility_space(facility_space_id) ON DELETE SET NULL,
  CONSTRAINT fk_asset_history_new_space FOREIGN KEY(new_space_id) REFERENCES facility_space(facility_space_id) ON DELETE SET NULL,
  CONSTRAINT fk_asset_history_user FOREIGN KEY(changed_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  INDEX idx_asset_history(asset_id,changed_at)
) ENGINE=InnoDB;

-- MAINTENANCE
CREATE TABLE preventive_maintenance_plan (
  preventive_maintenance_plan_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plan_code VARCHAR(60) NOT NULL UNIQUE, asset_id BIGINT UNSIGNED, facility_space_id BIGINT UNSIGNED,
  plan_name VARCHAR(255) NOT NULL, description TEXT, frequency_days INT UNSIGNED NOT NULL,
  next_due_date DATE NOT NULL, assigned_to_employee_reference_id BIGINT UNSIGNED,
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pm_asset FOREIGN KEY(asset_id) REFERENCES asset(asset_id) ON DELETE SET NULL,
  CONSTRAINT fk_pm_space FOREIGN KEY(facility_space_id) REFERENCES facility_space(facility_space_id) ON DELETE SET NULL,
  CONSTRAINT fk_pm_assignee FOREIGN KEY(assigned_to_employee_reference_id) REFERENCES employee_reference(employee_reference_id) ON DELETE SET NULL,
  INDEX idx_pm_due(next_due_date,status)
) ENGINE=InnoDB;

CREATE TABLE maintenance_work_order (
  maintenance_work_order_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  work_order_number VARCHAR(60) NOT NULL UNIQUE,
  facility_request_id BIGINT UNSIGNED, preventive_maintenance_plan_id BIGINT UNSIGNED,
  facility_space_id BIGINT UNSIGNED, asset_id BIGINT UNSIGNED,
  maintenance_type VARCHAR(30) NOT NULL, priority VARCHAR(30) NOT NULL DEFAULT 'NORMAL',
  status VARCHAR(30) NOT NULL DEFAULT 'OPEN', problem_description TEXT NOT NULL,
  diagnosis TEXT, work_performed TEXT, assigned_to_employee_reference_id BIGINT UNSIGNED,
  scheduled_start_at DATETIME, scheduled_end_at DATETIME, actual_start_at DATETIME, actual_end_at DATETIME,
  downtime_minutes INT UNSIGNED, estimated_cost DECIMAL(18,2), actual_cost DECIMAL(18,2),
  completion_notes TEXT, verified_by_employee_reference_id BIGINT UNSIGNED, verified_at DATETIME,
  created_by_user_id BIGINT UNSIGNED, updated_by_user_id BIGINT UNSIGNED,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME,
  CONSTRAINT fk_work_order_request FOREIGN KEY(facility_request_id) REFERENCES facility_request(facility_request_id) ON DELETE SET NULL,
  CONSTRAINT fk_work_order_pm FOREIGN KEY(preventive_maintenance_plan_id) REFERENCES preventive_maintenance_plan(preventive_maintenance_plan_id) ON DELETE SET NULL,
  CONSTRAINT fk_work_order_space FOREIGN KEY(facility_space_id) REFERENCES facility_space(facility_space_id) ON DELETE SET NULL,
  CONSTRAINT fk_work_order_asset FOREIGN KEY(asset_id) REFERENCES asset(asset_id) ON DELETE SET NULL,
  CONSTRAINT fk_work_order_assignee FOREIGN KEY(assigned_to_employee_reference_id) REFERENCES employee_reference(employee_reference_id) ON DELETE SET NULL,
  CONSTRAINT fk_work_order_verifier FOREIGN KEY(verified_by_employee_reference_id) REFERENCES employee_reference(employee_reference_id) ON DELETE SET NULL,
  CONSTRAINT fk_work_order_creator FOREIGN KEY(created_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  CONSTRAINT fk_work_order_updater FOREIGN KEY(updated_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  INDEX idx_work_order_dashboard(status,priority,created_at), INDEX idx_work_order_schedule(scheduled_start_at,scheduled_end_at),
  CHECK((estimated_cost IS NULL OR estimated_cost>=0) AND (actual_cost IS NULL OR actual_cost>=0))
) ENGINE=InnoDB;

CREATE TABLE maintenance_material (
  maintenance_material_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  maintenance_work_order_id BIGINT UNSIGNED NOT NULL,
  inventory_item_reference_id BIGINT UNSIGNED,
  item_description VARCHAR(255) NOT NULL, quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
  unit_of_measure VARCHAR(50), unit_cost DECIMAL(18,2), total_cost DECIMAL(18,2),
  source_type VARCHAR(30) NOT NULL DEFAULT 'STOCK', remarks TEXT,
  CONSTRAINT fk_material_work_order FOREIGN KEY(maintenance_work_order_id) REFERENCES maintenance_work_order(maintenance_work_order_id) ON DELETE CASCADE,
  CONSTRAINT fk_material_item FOREIGN KEY(inventory_item_reference_id) REFERENCES inventory_item_reference(inventory_item_reference_id) ON DELETE SET NULL,
  CHECK(quantity>0)
) ENGINE=InnoDB;

CREATE TABLE maintenance_history (
  maintenance_history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  maintenance_work_order_id BIGINT UNSIGNED NOT NULL,
  old_status VARCHAR(30), new_status VARCHAR(30) NOT NULL,
  changed_by_user_id BIGINT UNSIGNED, change_reason TEXT,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_maintenance_history_order FOREIGN KEY(maintenance_work_order_id) REFERENCES maintenance_work_order(maintenance_work_order_id) ON DELETE CASCADE,
  CONSTRAINT fk_maintenance_history_user FOREIGN KEY(changed_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  INDEX idx_maintenance_history(maintenance_work_order_id,changed_at)
) ENGINE=InnoDB;

-- RESERVATIONS
CREATE TABLE facility_reservation (
  facility_reservation_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reservation_number VARCHAR(60) NOT NULL UNIQUE,
  facility_space_id BIGINT UNSIGNED NOT NULL,
  requested_by_employee_reference_id BIGINT UNSIGNED NOT NULL,
  department_reference_id BIGINT UNSIGNED,
  reservation_type VARCHAR(100) NOT NULL, purpose TEXT NOT NULL,
  expected_attendees INT UNSIGNED NOT NULL DEFAULT 1,
  setup_requirements TEXT, start_datetime DATETIME NOT NULL, end_datetime DATETIME NOT NULL,
  setup_buffer_minutes INT UNSIGNED NOT NULL DEFAULT 0, cleanup_buffer_minutes INT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'DRAFT', approval_status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
  approved_by_employee_reference_id BIGINT UNSIGNED, approved_at DATETIME,
  checked_in_at DATETIME, checked_out_at DATETIME, cancellation_reason TEXT, remarks TEXT,
  created_by_user_id BIGINT UNSIGNED, updated_by_user_id BIGINT UNSIGNED,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME,
  CONSTRAINT fk_reservation_space FOREIGN KEY(facility_space_id) REFERENCES facility_space(facility_space_id) ON DELETE RESTRICT,
  CONSTRAINT fk_reservation_requester FOREIGN KEY(requested_by_employee_reference_id) REFERENCES employee_reference(employee_reference_id) ON DELETE RESTRICT,
  CONSTRAINT fk_reservation_department FOREIGN KEY(department_reference_id) REFERENCES department_reference(department_reference_id) ON DELETE SET NULL,
  CONSTRAINT fk_reservation_approver FOREIGN KEY(approved_by_employee_reference_id) REFERENCES employee_reference(employee_reference_id) ON DELETE SET NULL,
  CONSTRAINT fk_reservation_creator FOREIGN KEY(created_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  CONSTRAINT fk_reservation_updater FOREIGN KEY(updated_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  INDEX idx_reservation_schedule(facility_space_id,start_datetime,end_datetime), INDEX idx_reservation_status(status,approval_status),
  CHECK(end_datetime>start_datetime), CHECK(expected_attendees>0)
) ENGINE=InnoDB;

CREATE TABLE reservation_participant (
  reservation_participant_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  facility_reservation_id BIGINT UNSIGNED NOT NULL, employee_reference_id BIGINT UNSIGNED NOT NULL,
  participant_role VARCHAR(50) NOT NULL DEFAULT 'PARTICIPANT', attendance_status VARCHAR(30),
  CONSTRAINT fk_reservation_participant_reservation FOREIGN KEY(facility_reservation_id) REFERENCES facility_reservation(facility_reservation_id) ON DELETE CASCADE,
  CONSTRAINT fk_reservation_participant_employee FOREIGN KEY(employee_reference_id) REFERENCES employee_reference(employee_reference_id) ON DELETE RESTRICT,
  UNIQUE KEY uq_reservation_participant(facility_reservation_id,employee_reference_id)
) ENGINE=InnoDB;

CREATE TABLE reservation_history (
  reservation_history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  facility_reservation_id BIGINT UNSIGNED NOT NULL,
  old_status VARCHAR(30), new_status VARCHAR(30) NOT NULL,
  changed_by_user_id BIGINT UNSIGNED, change_reason TEXT,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reservation_history_reservation FOREIGN KEY(facility_reservation_id) REFERENCES facility_reservation(facility_reservation_id) ON DELETE CASCADE,
  CONSTRAINT fk_reservation_history_user FOREIGN KEY(changed_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  INDEX idx_reservation_history(facility_reservation_id,changed_at)
) ENGINE=InnoDB;

-- VISITORS
CREATE TABLE visitor (
  visitor_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL, middle_name VARCHAR(100), last_name VARCHAR(100) NOT NULL,
  organization_name VARCHAR(200), visitor_type VARCHAR(100) NOT NULL DEFAULT 'GUEST',
  email_address VARCHAR(190), contact_number VARCHAR(50), id_type VARCHAR(100), id_number_encrypted VARBINARY(512),
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, deleted_at DATETIME,
  INDEX idx_visitor_name(last_name,first_name)
) ENGINE=InnoDB;

CREATE TABLE visit (
  visit_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visit_number VARCHAR(60) NOT NULL UNIQUE, visitor_id BIGINT UNSIGNED NOT NULL,
  host_employee_reference_id BIGINT UNSIGNED NOT NULL,
  facility_reservation_id BIGINT UNSIGNED, destination_space_id BIGINT UNSIGNED,
  purpose TEXT NOT NULL, scheduled_arrival DATETIME NOT NULL, scheduled_departure DATETIME,
  actual_time_in DATETIME, actual_time_out DATETIME,
  visit_status VARCHAR(30) NOT NULL DEFAULT 'SCHEDULED', remarks TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME,
  CONSTRAINT fk_visit_visitor FOREIGN KEY(visitor_id) REFERENCES visitor(visitor_id) ON DELETE RESTRICT,
  CONSTRAINT fk_visit_host FOREIGN KEY(host_employee_reference_id) REFERENCES employee_reference(employee_reference_id) ON DELETE RESTRICT,
  CONSTRAINT fk_visit_reservation FOREIGN KEY(facility_reservation_id) REFERENCES facility_reservation(facility_reservation_id) ON DELETE SET NULL,
  CONSTRAINT fk_visit_space FOREIGN KEY(destination_space_id) REFERENCES facility_space(facility_space_id) ON DELETE SET NULL,
  INDEX idx_visit_schedule(scheduled_arrival,scheduled_departure), INDEX idx_visit_status(visit_status)
) ENGINE=InnoDB;

CREATE TABLE visitor_pass (
  visitor_pass_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visit_id BIGINT UNSIGNED NOT NULL, pass_number VARCHAR(60) NOT NULL UNIQUE,
  pass_type VARCHAR(100) NOT NULL, issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  returned_at DATETIME, pass_status VARCHAR(30) NOT NULL DEFAULT 'ISSUED',
  issued_by_user_id BIGINT UNSIGNED NOT NULL,
  CONSTRAINT fk_visitor_pass_visit FOREIGN KEY(visit_id) REFERENCES visit(visit_id) ON DELETE CASCADE,
  CONSTRAINT fk_visitor_pass_user FOREIGN KEY(issued_by_user_id) REFERENCES user_account(user_account_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- PROCUREMENT REQUESTS
CREATE TABLE procurement_request (
  procurement_request_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_number VARCHAR(60) NOT NULL UNIQUE,
  requested_by_employee_reference_id BIGINT UNSIGNED NOT NULL,
  department_reference_id BIGINT UNSIGNED, facility_request_id BIGINT UNSIGNED,
  maintenance_work_order_id BIGINT UNSIGNED, budget_reference_id BIGINT UNSIGNED,
  justification TEXT NOT NULL, priority VARCHAR(30) NOT NULL DEFAULT 'NORMAL',
  estimated_total DECIMAL(18,2) NOT NULL DEFAULT 0, currency_code CHAR(3) NOT NULL DEFAULT 'PHP',
  status VARCHAR(30) NOT NULL DEFAULT 'DRAFT', approval_status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
  external_procurement_id VARCHAR(100), external_status VARCHAR(50),
  integration_status VARCHAR(30) NOT NULL DEFAULT 'NOT_SUBMITTED',
  submitted_to_external_at DATETIME, last_synced_at DATETIME,
  integration_error_code VARCHAR(100), integration_error_message TEXT,
  expected_delivery_date DATE, completed_at DATETIME,
  created_by_user_id BIGINT UNSIGNED, updated_by_user_id BIGINT UNSIGNED,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME,
  CONSTRAINT fk_procurement_requester FOREIGN KEY(requested_by_employee_reference_id) REFERENCES employee_reference(employee_reference_id) ON DELETE RESTRICT,
  CONSTRAINT fk_procurement_department FOREIGN KEY(department_reference_id) REFERENCES department_reference(department_reference_id) ON DELETE SET NULL,
  CONSTRAINT fk_procurement_facility_request FOREIGN KEY(facility_request_id) REFERENCES facility_request(facility_request_id) ON DELETE SET NULL,
  CONSTRAINT fk_procurement_work_order FOREIGN KEY(maintenance_work_order_id) REFERENCES maintenance_work_order(maintenance_work_order_id) ON DELETE SET NULL,
  CONSTRAINT fk_procurement_budget FOREIGN KEY(budget_reference_id) REFERENCES budget_reference(budget_reference_id) ON DELETE SET NULL,
  CONSTRAINT fk_procurement_creator FOREIGN KEY(created_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  CONSTRAINT fk_procurement_updater FOREIGN KEY(updated_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  INDEX idx_procurement_dashboard(status,approval_status,created_at), INDEX idx_procurement_integration(integration_status,last_synced_at),
  CHECK(estimated_total>=0)
) ENGINE=InnoDB;

CREATE TABLE procurement_request_item (
  procurement_request_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  procurement_request_id BIGINT UNSIGNED NOT NULL,
  inventory_item_reference_id BIGINT UNSIGNED,
  item_description VARCHAR(255) NOT NULL, quantity DECIMAL(12,3) NOT NULL,
  unit_of_measure VARCHAR(50), estimated_unit_cost DECIMAL(18,2), estimated_total_cost DECIMAL(18,2), specifications TEXT,
  CONSTRAINT fk_procurement_item_request FOREIGN KEY(procurement_request_id) REFERENCES procurement_request(procurement_request_id) ON DELETE CASCADE,
  CONSTRAINT fk_procurement_item_reference FOREIGN KEY(inventory_item_reference_id) REFERENCES inventory_item_reference(inventory_item_reference_id) ON DELETE SET NULL,
  CHECK(quantity>0)
) ENGINE=InnoDB;

CREATE TABLE purchase_order_reference (
  purchase_order_reference_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  procurement_request_id BIGINT UNSIGNED NOT NULL, supplier_reference_id BIGINT UNSIGNED,
  external_purchase_order_id VARCHAR(100) NOT NULL, purchase_order_number VARCHAR(100),
  order_date DATE, expected_delivery_date DATE, total_amount DECIMAL(18,2), currency_code CHAR(3) NOT NULL DEFAULT 'PHP',
  purchase_order_status VARCHAR(30), source_system VARCHAR(50) NOT NULL DEFAULT 'SCM',
  sync_status VARCHAR(30) NOT NULL DEFAULT 'PENDING', last_synced_at DATETIME,
  CONSTRAINT fk_po_request FOREIGN KEY(procurement_request_id) REFERENCES procurement_request(procurement_request_id) ON DELETE CASCADE,
  CONSTRAINT fk_po_supplier FOREIGN KEY(supplier_reference_id) REFERENCES supplier_reference(supplier_reference_id) ON DELETE SET NULL,
  UNIQUE KEY uq_po_external(source_system,external_purchase_order_id)
) ENGINE=InnoDB;

CREATE TABLE procurement_history (
  procurement_history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  procurement_request_id BIGINT UNSIGNED NOT NULL,
  old_status VARCHAR(30), new_status VARCHAR(30) NOT NULL,
  changed_by_user_id BIGINT UNSIGNED, change_reason TEXT,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_procurement_history_request FOREIGN KEY(procurement_request_id) REFERENCES procurement_request(procurement_request_id) ON DELETE CASCADE,
  CONSTRAINT fk_procurement_history_user FOREIGN KEY(changed_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  INDEX idx_procurement_history(procurement_request_id,changed_at)
) ENGINE=InnoDB;

-- RECORD RETENTION
CREATE TABLE retention_schedule (
  retention_schedule_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  schedule_code VARCHAR(50) NOT NULL UNIQUE, schedule_name VARCHAR(200) NOT NULL,
  record_category VARCHAR(150) NOT NULL, retention_trigger VARCHAR(100) NOT NULL,
  retention_period_value INT UNSIGNED NOT NULL, retention_period_unit VARCHAR(20) NOT NULL,
  disposition_action VARCHAR(50) NOT NULL, legal_basis TEXT, description TEXT,
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE', effective_date DATE NOT NULL
) ENGINE=InnoDB;

CREATE TABLE record (
  record_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  record_number VARCHAR(60) NOT NULL UNIQUE, record_title VARCHAR(255) NOT NULL,
  record_description TEXT, record_type VARCHAR(100) NOT NULL,
  retention_schedule_id BIGINT UNSIGNED NOT NULL,
  originating_department_reference_id BIGINT UNSIGNED,
  record_owner_employee_reference_id BIGINT UNSIGNED NOT NULL,
  source_module VARCHAR(50), source_entity_type VARCHAR(100), source_entity_id BIGINT UNSIGNED,
  record_date DATE NOT NULL, retention_start_date DATE NOT NULL, scheduled_disposition_date DATE,
  record_status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE', confidentiality_level VARCHAR(30) NOT NULL DEFAULT 'INTERNAL',
  created_by_user_id BIGINT UNSIGNED, updated_by_user_id BIGINT UNSIGNED,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME,
  CONSTRAINT fk_record_schedule FOREIGN KEY(retention_schedule_id) REFERENCES retention_schedule(retention_schedule_id) ON DELETE RESTRICT,
  CONSTRAINT fk_record_department FOREIGN KEY(originating_department_reference_id) REFERENCES department_reference(department_reference_id) ON DELETE SET NULL,
  CONSTRAINT fk_record_owner FOREIGN KEY(record_owner_employee_reference_id) REFERENCES employee_reference(employee_reference_id) ON DELETE RESTRICT,
  CONSTRAINT fk_record_creator FOREIGN KEY(created_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  CONSTRAINT fk_record_updater FOREIGN KEY(updated_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  INDEX idx_record_review(record_status,scheduled_disposition_date), INDEX idx_record_source(source_module,source_entity_type,source_entity_id)
) ENGINE=InnoDB;

CREATE TABLE record_document (
  record_document_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  record_id BIGINT UNSIGNED NOT NULL, document_id BIGINT UNSIGNED NOT NULL,
  is_primary_document BOOLEAN NOT NULL DEFAULT FALSE, added_by_user_id BIGINT UNSIGNED NOT NULL,
  added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_record_document_record FOREIGN KEY(record_id) REFERENCES record(record_id) ON DELETE CASCADE,
  CONSTRAINT fk_record_document_document FOREIGN KEY(document_id) REFERENCES document(document_id) ON DELETE RESTRICT,
  CONSTRAINT fk_record_document_user FOREIGN KEY(added_by_user_id) REFERENCES user_account(user_account_id) ON DELETE RESTRICT,
  UNIQUE KEY uq_record_document(record_id,document_id)
) ENGINE=InnoDB;

-- CONTRACTS / LEGAL (BPA)
CREATE TABLE contract_type (
  contract_type_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type_code VARCHAR(50) NOT NULL UNIQUE, type_name VARCHAR(150) NOT NULL,
  description TEXT, status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE'
) ENGINE=InnoDB;

CREATE TABLE contract (
  contract_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contract_number VARCHAR(60) NOT NULL UNIQUE, contract_type_id BIGINT UNSIGNED NOT NULL,
  contract_title VARCHAR(255) NOT NULL, contract_description TEXT,
  supplier_reference_id BIGINT UNSIGNED, budget_reference_id BIGINT UNSIGNED,
  contract_owner_employee_reference_id BIGINT UNSIGNED NOT NULL,
  start_date DATE NOT NULL, end_date DATE NOT NULL,
  original_amount DECIMAL(18,2) NOT NULL DEFAULT 0, current_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  currency_code CHAR(3) NOT NULL DEFAULT 'PHP', contract_status VARCHAR(30) NOT NULL DEFAULT 'DRAFT',
  notice_period_days INT UNSIGNED,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME,
  CONSTRAINT fk_contract_type FOREIGN KEY(contract_type_id) REFERENCES contract_type(contract_type_id) ON DELETE RESTRICT,
  CONSTRAINT fk_contract_supplier FOREIGN KEY(supplier_reference_id) REFERENCES supplier_reference(supplier_reference_id) ON DELETE SET NULL,
  CONSTRAINT fk_contract_budget FOREIGN KEY(budget_reference_id) REFERENCES budget_reference(budget_reference_id) ON DELETE SET NULL,
  CONSTRAINT fk_contract_owner FOREIGN KEY(contract_owner_employee_reference_id) REFERENCES employee_reference(employee_reference_id) ON DELETE RESTRICT,
  INDEX idx_contract_status(contract_status), INDEX idx_contract_end(end_date),
  CHECK(end_date>=start_date), CHECK(original_amount>=0 AND current_amount>=0)
) ENGINE=InnoDB;

CREATE TABLE legal_matter (
  legal_matter_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  matter_number VARCHAR(60) NOT NULL UNIQUE,
  title VARCHAR(255) NOT NULL,
  matter_type VARCHAR(50) NOT NULL,
  summary TEXT NOT NULL,
  priority VARCHAR(30) NOT NULL DEFAULT 'MEDIUM',
  status VARCHAR(30) NOT NULL DEFAULT 'OPEN',
  department_reference_id BIGINT UNSIGNED,
  assigned_employee_reference_id BIGINT UNSIGNED,
  reported_at DATE,
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME,
  resolved_by_user_id BIGINT UNSIGNED,
  resolution_summary TEXT,
  closed_at DATETIME,
  closed_by_user_id BIGINT UNSIGNED,
  cancelled_at DATETIME,
  cancelled_by_user_id BIGINT UNSIGNED,
  cancellation_reason TEXT,
  created_by_user_id BIGINT UNSIGNED,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME,
  CONSTRAINT fk_legal_matter_department FOREIGN KEY(department_reference_id) REFERENCES department_reference(department_reference_id) ON DELETE SET NULL,
  CONSTRAINT fk_legal_matter_assignee FOREIGN KEY(assigned_employee_reference_id) REFERENCES employee_reference(employee_reference_id) ON DELETE SET NULL,
  CONSTRAINT fk_legal_matter_created_by FOREIGN KEY(created_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  CONSTRAINT fk_legal_matter_resolved_by FOREIGN KEY(resolved_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  CONSTRAINT fk_legal_matter_closed_by FOREIGN KEY(closed_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  CONSTRAINT fk_legal_matter_cancelled_by FOREIGN KEY(cancelled_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  INDEX idx_legal_matter_status(status),
  INDEX idx_legal_matter_type(matter_type),
  INDEX idx_legal_matter_priority(priority),
  INDEX idx_legal_matter_updated(updated_at)
) ENGINE=InnoDB;

CREATE TABLE legal_matter_history (
  legal_matter_history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  legal_matter_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(100) NOT NULL,
  from_status VARCHAR(30),
  to_status VARCHAR(30),
  description TEXT NOT NULL,
  metadata_json LONGTEXT,
  actor_user_id BIGINT UNSIGNED,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_legal_matter_history_matter FOREIGN KEY(legal_matter_id) REFERENCES legal_matter(legal_matter_id) ON DELETE CASCADE,
  CONSTRAINT fk_legal_matter_history_actor FOREIGN KEY(actor_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  INDEX idx_legal_matter_history_matter(legal_matter_id, created_at)
) ENGINE=InnoDB;

-- SHARED WORKFLOW / DASHBOARD
CREATE TABLE approval_request (
  approval_request_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  module_code VARCHAR(50) NOT NULL, entity_type VARCHAR(100) NOT NULL, entity_id BIGINT UNSIGNED NOT NULL,
  requested_by_user_id BIGINT UNSIGNED NOT NULL,
  approval_status VARCHAR(30) NOT NULL DEFAULT 'PENDING', current_step_number SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at DATETIME, remarks TEXT,
  CONSTRAINT fk_approval_request_user FOREIGN KEY(requested_by_user_id) REFERENCES user_account(user_account_id) ON DELETE RESTRICT,
  INDEX idx_approval_entity(module_code,entity_type,entity_id), INDEX idx_approval_status(approval_status,requested_at)
) ENGINE=InnoDB;

CREATE TABLE approval_step (
  approval_step_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  approval_request_id BIGINT UNSIGNED NOT NULL, step_number SMALLINT UNSIGNED NOT NULL,
  approver_employee_reference_id BIGINT UNSIGNED, approver_role_id BIGINT UNSIGNED,
  decision VARCHAR(30) NOT NULL DEFAULT 'PENDING', decision_at DATETIME, comments TEXT,
  CONSTRAINT fk_approval_step_request FOREIGN KEY(approval_request_id) REFERENCES approval_request(approval_request_id) ON DELETE CASCADE,
  CONSTRAINT fk_approval_step_employee FOREIGN KEY(approver_employee_reference_id) REFERENCES employee_reference(employee_reference_id) ON DELETE SET NULL,
  CONSTRAINT fk_approval_step_role FOREIGN KEY(approver_role_id) REFERENCES role(role_id) ON DELETE SET NULL,
  UNIQUE KEY uq_approval_step(approval_request_id,step_number), INDEX idx_approval_pending(decision,approver_employee_reference_id)
) ENGINE=InnoDB;

CREATE TABLE workflow_task (
  workflow_task_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_reference VARCHAR(60) NOT NULL UNIQUE,
  module_code VARCHAR(50) NOT NULL, entity_type VARCHAR(100) NOT NULL, entity_id BIGINT UNSIGNED NOT NULL,
  task_type VARCHAR(100) NOT NULL, title VARCHAR(255) NOT NULL, description TEXT,
  assigned_to_employee_reference_id BIGINT UNSIGNED, assigned_role_id BIGINT UNSIGNED,
  priority VARCHAR(30) NOT NULL DEFAULT 'NORMAL', status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
  due_at DATETIME, completed_at DATETIME, created_by_user_id BIGINT UNSIGNED,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_task_employee FOREIGN KEY(assigned_to_employee_reference_id) REFERENCES employee_reference(employee_reference_id) ON DELETE SET NULL,
  CONSTRAINT fk_task_role FOREIGN KEY(assigned_role_id) REFERENCES role(role_id) ON DELETE SET NULL,
  CONSTRAINT fk_task_creator FOREIGN KEY(created_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  INDEX idx_task_dashboard(status,priority,due_at), INDEX idx_task_assignee(assigned_to_employee_reference_id,status)
) ENGINE=InnoDB;

CREATE TABLE notification (
  notification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recipient_user_id BIGINT UNSIGNED NOT NULL,
  notification_type VARCHAR(80) NOT NULL, title VARCHAR(255) NOT NULL, message TEXT NOT NULL,
  module_code VARCHAR(50), entity_type VARCHAR(100), entity_id BIGINT UNSIGNED,
  is_read BOOLEAN NOT NULL DEFAULT FALSE, read_at DATETIME,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notification_user FOREIGN KEY(recipient_user_id) REFERENCES user_account(user_account_id) ON DELETE CASCADE,
  INDEX idx_notification_unread(recipient_user_id,is_read,created_at)
) ENGINE=InnoDB;

CREATE TABLE activity_event (
  activity_event_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  module_code VARCHAR(50) NOT NULL, entity_type VARCHAR(100) NOT NULL, entity_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(80) NOT NULL, event_description TEXT NOT NULL,
  actor_user_id BIGINT UNSIGNED, event_status VARCHAR(30), visibility_level VARCHAR(30) NOT NULL DEFAULT 'INTERNAL',
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_activity_user FOREIGN KEY(actor_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  INDEX idx_activity_dashboard(occurred_at,module_code)
) ENGINE=InnoDB;

CREATE TABLE audit_log (
  audit_log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED, action VARCHAR(100) NOT NULL, module_code VARCHAR(50) NOT NULL,
  entity_type VARCHAR(100), entity_id BIGINT UNSIGNED,
  old_values_json JSON, new_values_json JSON,
  ip_address VARCHAR(45), user_agent VARCHAR(500), created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY(actor_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  INDEX idx_audit_actor(actor_user_id,created_at), INDEX idx_audit_entity(module_code,entity_type,entity_id)
) ENGINE=InnoDB;

-- AI AUTOMATION
CREATE TABLE ai_recommendation (
  ai_recommendation_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  module_code VARCHAR(50) NOT NULL, entity_type VARCHAR(100) NOT NULL, entity_id BIGINT UNSIGNED NOT NULL,
  feature_type VARCHAR(100) NOT NULL, input_hash VARCHAR(128), input_summary TEXT, output_json JSON,
  suggested_category VARCHAR(100), suggested_priority VARCHAR(30), suggested_assignee VARCHAR(200),
  confidence_score DECIMAL(5,4), explanation TEXT,
  model_provider VARCHAR(100), model_name VARCHAR(150), model_version VARCHAR(100),
  recommendation_status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
  reviewed_by_user_id BIGINT UNSIGNED, reviewed_at DATETIME, final_decision VARCHAR(30), reviewer_feedback TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ai_reviewer FOREIGN KEY(reviewed_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  INDEX idx_ai_entity(module_code,entity_type,entity_id), INDEX idx_ai_status(recommendation_status,created_at),
  CHECK(confidence_score IS NULL OR confidence_score BETWEEN 0 AND 1)
) ENGINE=InnoDB;

-- FLEET INTEGRATION
CREATE TABLE transportation_request_reference (
  transportation_request_reference_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  facility_request_id BIGINT UNSIGNED NOT NULL,
  external_transport_request_id VARCHAR(100), vehicle_reference_id BIGINT UNSIGNED,
  pickup_location VARCHAR(500) NOT NULL, destination VARCHAR(500) NOT NULL,
  departure_datetime DATETIME NOT NULL, return_datetime DATETIME,
  passenger_count INT UNSIGNED NOT NULL DEFAULT 1,
  external_status VARCHAR(50), integration_status VARCHAR(30) NOT NULL DEFAULT 'NOT_SUBMITTED',
  submitted_to_external_at DATETIME, last_synced_at DATETIME,
  integration_error_code VARCHAR(100), integration_error_message TEXT, retry_count INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_transport_request FOREIGN KEY(facility_request_id) REFERENCES facility_request(facility_request_id) ON DELETE CASCADE,
  CONSTRAINT fk_transport_vehicle FOREIGN KEY(vehicle_reference_id) REFERENCES vehicle_reference(vehicle_reference_id) ON DELETE SET NULL,
  INDEX idx_transport_schedule(departure_datetime,return_datetime), CHECK(passenger_count>0)
) ENGINE=InnoDB;

-- SEED DATA
INSERT INTO external_system(system_code,system_name,owner_group,integration_type,status) VALUES
('HRIS','Human Resources Information System','Core 2','API/SYNC','PLANNED'),
('FMS','Financial Management System','Financial Management Group','API/SYNC','PLANNED'),
('SCM','Supply Chain and Inventory System','Supply Chain Group','API/SYNC','PLANNED'),
('FLEET','Fleet and Transportation System','Fleet Group','API/SYNC','PLANNED'),
('BI','Business Intelligence System','Business Intelligence Group','EVENT/EXPORT','PLANNED')
ON DUPLICATE KEY UPDATE system_name=VALUES(system_name);

INSERT INTO request_category(category_code,category_name,description,default_priority,responsible_role_code) VALUES
('GENERAL','General Facility Request','General facility-related concern or service request.','NORMAL','FACILITY_MANAGER'),
('HVAC','HVAC','Air-conditioning, ventilation, and cooling concerns.','HIGH','MAINTENANCE_SUPERVISOR'),
('ELECTRICAL','Electrical','Electrical systems, outlets, lighting, and related risks.','HIGH','MAINTENANCE_SUPERVISOR'),
('PLUMBING','Plumbing','Water supply, leaks, drainage, and plumbing concerns.','HIGH','MAINTENANCE_SUPERVISOR'),
('CLEANING','Cleaning and Sanitation','Cleaning and housekeeping service requests.','NORMAL','FACILITY_MANAGER'),
('TRANSPORT','Transportation Service','Official transportation coordination request.','NORMAL','FACILITY_MANAGER')
ON DUPLICATE KEY UPDATE category_name=VALUES(category_name);

INSERT INTO document_category(category_code,category_name,description,default_confidentiality_level) VALUES
('FACILITY_REQUEST','Facility Request Documents','Attachments for facility requests.','INTERNAL'),
('MAINTENANCE','Maintenance Documents','Work orders, photos, and service documents.','INTERNAL'),
('ASSET','Asset Documents','Asset records, warranties, and manuals.','INTERNAL'),
('RESERVATION','Reservation Documents','Documents attached to reservations.','INTERNAL'),
('VISITOR','Visitor Documents','Visitor-related documents and clearances.','CONFIDENTIAL'),
('PROCUREMENT','Procurement Documents','Procurement request and delivery documents.','INTERNAL'),
('CONTRACT','Contract Documents','Contracts and signed copies.','CONFIDENTIAL'),
('LEGAL','Legal Documents','Legal case documents and evidence.','RESTRICTED'),
('RECORDS','Records Management','Archived and retained records.','INTERNAL')
ON DUPLICATE KEY UPDATE category_name=VALUES(category_name);

INSERT INTO contract_type(type_code,type_name,description) VALUES
('SERVICE','Service Contract','Contract for professional or operational services.'),
('SUPPLY','Supply Contract','Contract for goods, supplies, or materials.'),
('LEASE','Lease Agreement','Lease or rental agreement.'),
('MAINTENANCE','Maintenance Contract','Contract for preventive or corrective maintenance.')
ON DUPLICATE KEY UPDATE type_name=VALUES(type_name);

INSERT INTO role(role_code,role_name,description) VALUES
('SYSTEM_ADMIN','System Administrator','Full system administration access.'),
('FAM_ADMIN','FAM Administrator','Manages FAM operations.'),
('FACILITY_MANAGER','Facility Manager','Reviews and coordinates facility requests.'),
('MAINTENANCE_SUPERVISOR','Maintenance Supervisor','Assigns and supervises maintenance work.'),
('TECHNICIAN','Technician','Performs maintenance work.'),
('ASSET_CUSTODIAN','Asset Custodian','Manages asset lifecycle records.'),
('RESERVATION_OFFICER','Reservation Officer','Manages reservations.'),
('PROCUREMENT_OFFICER','Procurement Officer','Coordinates procurement requests.'),
('RECORDS_OFFICER','Records Officer','Manages records retention.'),
('REQUESTOR','Department Requestor','Submits and tracks requests.'),
('APPROVER','Approver','Reviews approval steps.'),
('AUDITOR','Auditor','Read-only audit and report access.')
ON DUPLICATE KEY UPDATE role_name=VALUES(role_name);

SET FOREIGN_KEY_CHECKS=1;

-- NOTES:
-- 1) Check reservation overlaps in application code inside a transaction.
-- 2) Update document.current_version_number and document_version.is_current atomically.
-- 3) entity_type/entity_id fields are intentionally polymorphic for shared workflow/audit/AI tables.
-- 4) External reference tables are local synced projections; source systems remain authoritative.
