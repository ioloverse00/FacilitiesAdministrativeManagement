-- Building primary image support.
-- Apply this migration before deploying the building-image application code.
-- Adds one optional primary image to each building; existing building records remain valid without images.

ALTER TABLE building
  ADD COLUMN primary_image_original_file_name VARCHAR(255) NULL AFTER description,
  ADD COLUMN primary_image_storage_path VARCHAR(500) NULL AFTER primary_image_original_file_name,
  ADD COLUMN primary_image_mime_type VARCHAR(120) NULL AFTER primary_image_storage_path,
  ADD COLUMN primary_image_file_size BIGINT UNSIGNED NULL AFTER primary_image_mime_type,
  ADD COLUMN primary_image_uploaded_by_user_id BIGINT UNSIGNED NULL AFTER primary_image_file_size,
  ADD COLUMN primary_image_uploaded_at DATETIME NULL AFTER primary_image_uploaded_by_user_id,
  ADD INDEX idx_building_primary_image_uploaded_by (primary_image_uploaded_by_user_id),
  ADD CONSTRAINT fk_building_primary_image_uploaded_by
    FOREIGN KEY (primary_image_uploaded_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL;
