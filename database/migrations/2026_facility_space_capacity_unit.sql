-- Adds an explicit unit for facility capacity so reservable spaces can represent
-- people-based capacity and vehicle-based capacity without changing reservation flow.
ALTER TABLE facility_space
  ADD COLUMN capacity_unit VARCHAR(20) NOT NULL DEFAULT 'PAX' AFTER capacity,
  ADD CONSTRAINT chk_facility_space_capacity_unit
    CHECK (capacity_unit IN ('PAX', 'VEHICLES'));
