-- Phase 2: Final active reservable facility master data.
--
-- Depends on 2026_facility_space_capacity_unit.sql.
-- This migration updates existing facility_space rows by stable space_code,
-- inserts missing final reservable spaces by stable space_code, and preserves
-- SP-TRN-B for historical reservation references by making it inactive and
-- non-reservable instead of renaming, deleting, or reusing its ID.

UPDATE facility_space
SET
  space_name = 'Main Conference Room',
  space_type = 'CONFERENCE_ROOM',
  floor_number = '2',
  capacity = 16,
  capacity_unit = 'PAX',
  location_description = 'Administration Building second floor west wing',
  is_reservable = 1,
  status = 'ACTIVE',
  updated_at = NOW()
WHERE space_code = 'SP-ADM-CONF-MAIN'
  AND deleted_at IS NULL;

UPDATE facility_space
SET
  space_name = 'Executive Meeting Room',
  space_type = 'MEETING_ROOM',
  floor_number = '3',
  capacity = 8,
  capacity_unit = 'PAX',
  location_description = 'Administration Building third floor suite area',
  is_reservable = 1,
  status = 'ACTIVE',
  updated_at = NOW()
WHERE space_code = 'SP-ADM-MEET-EXEC'
  AND deleted_at IS NULL;

UPDATE facility_space
SET
  space_name = 'Training Room',
  space_type = 'TRAINING_FACILITY',
  floor_number = '1',
  capacity = 30,
  capacity_unit = 'PAX',
  location_description = 'Training Center ground floor',
  is_reservable = 1,
  status = 'ACTIVE',
  updated_at = NOW()
WHERE space_code = 'SP-TRN-A'
  AND deleted_at IS NULL;

UPDATE facility_space
SET
  is_reservable = 0,
  status = 'INACTIVE',
  updated_at = NOW()
WHERE space_code = 'SP-TRN-B'
  AND deleted_at IS NULL;

INSERT INTO facility_space (
  building_id,
  parent_space_id,
  space_code,
  space_name,
  space_type,
  floor_number,
  capacity,
  capacity_unit,
  location_description,
  is_reservable,
  status,
  created_at,
  updated_at
)
SELECT
  b.building_id,
  NULL,
  'SP-ADM-INT-RM',
  'Interview Room',
  'INTERVIEW_ROOM',
  '2',
  6,
  'PAX',
  'Administration Building second floor west wing',
  1,
  'ACTIVE',
  NOW(),
  NOW()
FROM building b
WHERE b.building_code = 'BLDG-ADM'
  AND b.status = 'ACTIVE'
  AND b.deleted_at IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM facility_space fs WHERE fs.space_code = 'SP-ADM-INT-RM'
  );

UPDATE facility_space fs
INNER JOIN building b ON b.building_code = 'BLDG-ADM'
  AND b.status = 'ACTIVE'
  AND b.deleted_at IS NULL
SET
  fs.building_id = b.building_id,
  fs.parent_space_id = NULL,
  fs.space_name = 'Interview Room',
  fs.space_type = 'INTERVIEW_ROOM',
  fs.floor_number = '2',
  fs.capacity = 6,
  fs.capacity_unit = 'PAX',
  fs.location_description = 'Administration Building second floor west wing',
  fs.is_reservable = 1,
  fs.status = 'ACTIVE',
  fs.updated_at = NOW()
WHERE fs.space_code = 'SP-ADM-INT-RM'
  AND fs.deleted_at IS NULL;

INSERT INTO facility_space (
  building_id,
  parent_space_id,
  space_code,
  space_name,
  space_type,
  floor_number,
  capacity,
  capacity_unit,
  location_description,
  is_reservable,
  status,
  created_at,
  updated_at
)
SELECT
  b.building_id,
  NULL,
  'SP-ADM-MULTI',
  'Multipurpose Room',
  'MULTIPURPOSE_FACILITY',
  'G',
  40,
  'PAX',
  'Administration Building main lobby area',
  1,
  'ACTIVE',
  NOW(),
  NOW()
FROM building b
WHERE b.building_code = 'BLDG-ADM'
  AND b.status = 'ACTIVE'
  AND b.deleted_at IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM facility_space fs WHERE fs.space_code = 'SP-ADM-MULTI'
  );

UPDATE facility_space fs
INNER JOIN building b ON b.building_code = 'BLDG-ADM'
  AND b.status = 'ACTIVE'
  AND b.deleted_at IS NULL
SET
  fs.building_id = b.building_id,
  fs.parent_space_id = NULL,
  fs.space_name = 'Multipurpose Room',
  fs.space_type = 'MULTIPURPOSE_FACILITY',
  fs.floor_number = 'G',
  fs.capacity = 40,
  fs.capacity_unit = 'PAX',
  fs.location_description = 'Administration Building main lobby area',
  fs.is_reservable = 1,
  fs.status = 'ACTIVE',
  fs.updated_at = NOW()
WHERE fs.space_code = 'SP-ADM-MULTI'
  AND fs.deleted_at IS NULL;

INSERT INTO facility_space (
  building_id,
  parent_space_id,
  space_code,
  space_name,
  space_type,
  floor_number,
  capacity,
  capacity_unit,
  location_description,
  is_reservable,
  status,
  created_at,
  updated_at
)
SELECT
  b.building_id,
  NULL,
  'SP-TRN-ORIENT',
  'Orientation Room',
  'TRAINING_ORIENTATION_ROOM',
  '1',
  25,
  'PAX',
  'Training Center ground floor',
  1,
  'ACTIVE',
  NOW(),
  NOW()
FROM building b
WHERE b.building_code = 'BLDG-TRN'
  AND b.status = 'ACTIVE'
  AND b.deleted_at IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM facility_space fs WHERE fs.space_code = 'SP-TRN-ORIENT'
  );

UPDATE facility_space fs
INNER JOIN building b ON b.building_code = 'BLDG-TRN'
  AND b.status = 'ACTIVE'
  AND b.deleted_at IS NULL
SET
  fs.building_id = b.building_id,
  fs.parent_space_id = NULL,
  fs.space_name = 'Orientation Room',
  fs.space_type = 'TRAINING_ORIENTATION_ROOM',
  fs.floor_number = '1',
  fs.capacity = 25,
  fs.capacity_unit = 'PAX',
  fs.location_description = 'Training Center ground floor',
  fs.is_reservable = 1,
  fs.status = 'ACTIVE',
  fs.updated_at = NOW()
WHERE fs.space_code = 'SP-TRN-ORIENT'
  AND fs.deleted_at IS NULL;

INSERT INTO facility_space (
  building_id,
  parent_space_id,
  space_code,
  space_name,
  space_type,
  floor_number,
  capacity,
  capacity_unit,
  location_description,
  is_reservable,
  status,
  created_at,
  updated_at
)
SELECT
  b.building_id,
  NULL,
  'SP-OPS-PARK-MAIN',
  'Main Parking Area',
  'PARKING_AREA',
  'G',
  20,
  'VEHICLES',
  'Operations Building ground floor parking area',
  1,
  'ACTIVE',
  NOW(),
  NOW()
FROM building b
WHERE b.building_code = 'BLDG-OPS'
  AND b.status = 'ACTIVE'
  AND b.deleted_at IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM facility_space fs WHERE fs.space_code = 'SP-OPS-PARK-MAIN'
  );

UPDATE facility_space fs
INNER JOIN building b ON b.building_code = 'BLDG-OPS'
  AND b.status = 'ACTIVE'
  AND b.deleted_at IS NULL
SET
  fs.building_id = b.building_id,
  fs.parent_space_id = NULL,
  fs.space_name = 'Main Parking Area',
  fs.space_type = 'PARKING_AREA',
  fs.floor_number = 'G',
  fs.capacity = 20,
  fs.capacity_unit = 'VEHICLES',
  fs.location_description = 'Operations Building ground floor parking area',
  fs.is_reservable = 1,
  fs.status = 'ACTIVE',
  fs.updated_at = NOW()
WHERE fs.space_code = 'SP-OPS-PARK-MAIN'
  AND fs.deleted_at IS NULL;
