-- Records Retention AI-assisted disposition decision support.
-- Advisory recommendation staging only; official retention lifecycle remains in record.

CREATE TABLE IF NOT EXISTS record_disposition_recommendation (
    recommendation_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    record_id bigint(20) unsigned NOT NULL,
    retention_schedule_id bigint(20) unsigned NULL,
    recommended_action varchar(30) NOT NULL,
    reason text NOT NULL,
    context_flags_json json NULL,
    policy_eligible_date date NULL,
    legal_hold_status varchar(30) NOT NULL DEFAULT 'NONE',
    needs_review tinyint(1) NOT NULL DEFAULT 1,
    status varchar(30) NOT NULL DEFAULT 'PENDING',
    reviewed_by_user_id bigint(20) unsigned NULL,
    reviewed_at datetime NULL,
    approved_action varchar(30) NULL,
    review_reason text NULL,
    source_provider varchar(30) NOT NULL DEFAULT 'SYSTEM',
    source_model varchar(120) NULL,
    evaluated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (recommendation_id),
    KEY idx_rdr_record_status (record_id, status),
    KEY idx_rdr_record_evaluated (record_id, evaluated_at),
    CONSTRAINT fk_rdr_record FOREIGN KEY (record_id) REFERENCES record(record_id),
    CONSTRAINT fk_rdr_schedule FOREIGN KEY (retention_schedule_id) REFERENCES retention_schedule(retention_schedule_id)
);
