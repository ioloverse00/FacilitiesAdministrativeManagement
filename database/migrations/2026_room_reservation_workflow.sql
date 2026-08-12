-- Room Reservation final workflow normalization.
-- Run the SELECT first to inspect affected rows before applying the UPDATE.

SELECT status, COUNT(*) AS reservation_count
FROM facility_reservation
WHERE deleted_at IS NULL
  AND status IN ('PENDING','PENDING_APPROVAL','CHECKED_OUT')
GROUP BY status;

UPDATE facility_reservation
SET status = CASE
        WHEN status IN ('PENDING','PENDING_APPROVAL') THEN 'SUBMITTED'
        WHEN status = 'CHECKED_OUT' THEN 'COMPLETED'
        ELSE status
    END,
    updated_at = NOW()
WHERE deleted_at IS NULL
  AND status IN ('PENDING','PENDING_APPROVAL','CHECKED_OUT');

SELECT status, COUNT(*) AS reservation_count
FROM facility_reservation
WHERE deleted_at IS NULL
GROUP BY status
ORDER BY status;
