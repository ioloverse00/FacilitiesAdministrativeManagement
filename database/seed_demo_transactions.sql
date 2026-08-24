-- ISMERS FAM demo transaction seed data
-- Target: MySQL 8.0+, database: ismers_fam
-- Fixed demo date: 2026-07-26
-- Import after seed_reference_data.sql.

USE ismers_fam;
START TRANSACTION;

SET @demo_now := TIMESTAMP('2026-07-26 10:00:00');
SET @admin_user := (SELECT user_account_id FROM user_account WHERE username='gsms-super-admin');
SET @fam_user := (SELECT user_account_id FROM user_account WHERE username='gsms-fam-admin');
SET @requestor_user := (SELECT user_account_id FROM user_account WHERE username='requestor.user');
SET @manager_emp := (SELECT employee_reference_id FROM employee_reference WHERE employee_number='EMP-2026-0003');
SET @supervisor_emp := (SELECT employee_reference_id FROM employee_reference WHERE employee_number='EMP-2026-0004');
SET @tech1_emp := (SELECT employee_reference_id FROM employee_reference WHERE employee_number='EMP-2026-0005');
SET @tech2_emp := (SELECT employee_reference_id FROM employee_reference WHERE employee_number='EMP-2026-0006');
SET @tech3_emp := (SELECT employee_reference_id FROM employee_reference WHERE employee_number='EMP-2026-0007');
SET @requestor_emp := (SELECT employee_reference_id FROM employee_reference WHERE employee_number='EMP-2026-0012');
SET @records_emp := (SELECT employee_reference_id FROM employee_reference WHERE employee_number='EMP-2026-0011');

-- Remove only known demo transactional records and their children.
DELETE FROM visitor_pass WHERE pass_number LIKE 'VP-2026-%';
DELETE FROM visit WHERE visit_number LIKE 'VIS-2026-%';
DELETE FROM visitor WHERE email_address LIKE '%@visitor.example-agency.test';
DELETE FROM legal_matter_history WHERE legal_matter_id IN (SELECT legal_matter_id FROM legal_matter WHERE matter_number LIKE 'LM-2026-%');
DELETE FROM legal_matter WHERE matter_number LIKE 'LM-2026-%';
DELETE FROM contract WHERE contract_number LIKE 'CON-2026-%';
DELETE FROM record WHERE record_number LIKE 'REC-2026-%';
DELETE FROM document_version WHERE document_id IN (SELECT document_id FROM document WHERE document_number LIKE 'DOC-2026-%');
DELETE FROM document WHERE document_number LIKE 'DOC-2026-%';
DELETE FROM purchase_order_reference WHERE external_purchase_order_id LIKE 'EXT-PO-2026-%';
DELETE FROM procurement_request WHERE request_number LIKE 'PR-2026-%';
DELETE FROM facility_reservation WHERE reservation_number LIKE 'RR-2026-%';
DELETE FROM maintenance_work_order WHERE work_order_number LIKE 'WO-2026-%';
DELETE FROM preventive_maintenance_plan WHERE plan_code LIKE 'PM-2026-%';
DELETE FROM asset WHERE asset_code LIKE 'AST-2026-%';
DELETE FROM facility_request WHERE request_number LIKE 'FR-2026-%';
DELETE FROM approval_request WHERE requested_at BETWEEN '2026-01-01' AND '2026-12-31' AND entity_type LIKE 'DEMO_%';
DELETE FROM workflow_task WHERE task_reference LIKE 'TASK-2026-%';
DELETE FROM notification WHERE created_at BETWEEN '2026-07-19' AND '2026-07-26 23:59:59' AND title LIKE 'Demo:%';
DELETE FROM activity_event WHERE occurred_at BETWEEN '2026-07-19' AND '2026-07-26 23:59:59' AND event_description LIKE 'Demo:%';
DELETE FROM audit_log WHERE created_at BETWEEN '2026-07-19' AND '2026-07-26 23:59:59' AND module_code LIKE 'demo_%';
DELETE FROM ai_recommendation WHERE input_hash LIKE 'demo-fr-%';
DELETE FROM integration_outbox WHERE event_uuid LIKE '00000000-2026-%';

-- 1. Facility Requests: 20 requests across status, priority, SLA, and locations.
INSERT INTO facility_request (
request_number, requested_by_employee_reference_id, department_reference_id, facility_space_id, request_category_id, sla_policy_id,
subject, description, priority, source_channel, status, approval_status, assigned_to_employee_reference_id,
requested_completion_at, acknowledged_at, assigned_at, started_at, completed_at, verified_at, closed_at, cancelled_at,
cancellation_reason, resolution_summary, created_by_user_id, updated_by_user_id, created_at, updated_at
)
SELECT CONCAT('FR-2026-',LPAD(n.n,4,'0')), @requestor_emp, d.department_reference_id, s.facility_space_id, c.request_category_id, sp.sla_policy_id,
CONCAT(v.subject,' #',n.n), v.description, v.priority, 'WEB', v.status, v.approval_status,
CASE WHEN v.status IN ('ASSIGNED','IN_PROGRESS','COMPLETED','VERIFIED','CLOSED') THEN ELT(1 + MOD(n.n,3), @tech1_emp, @tech2_emp, @tech3_emp) ELSE NULL END,
DATE_ADD(@demo_now, INTERVAL v.req_offset HOUR),
CASE WHEN v.status NOT IN ('DRAFT','SUBMITTED','PENDING_APPROVAL','CANCELLED') THEN DATE_ADD(@demo_now, INTERVAL v.ack_offset HOUR) END,
CASE WHEN v.status IN ('ASSIGNED','IN_PROGRESS','COMPLETED','VERIFIED','CLOSED') THEN DATE_ADD(@demo_now, INTERVAL v.assign_offset HOUR) END,
CASE WHEN v.status IN ('IN_PROGRESS','COMPLETED','VERIFIED','CLOSED') THEN DATE_ADD(@demo_now, INTERVAL v.start_offset HOUR) END,
CASE WHEN v.status IN ('COMPLETED','VERIFIED','CLOSED') THEN DATE_ADD(@demo_now, INTERVAL v.complete_offset HOUR) END,
CASE WHEN v.status IN ('VERIFIED','CLOSED') THEN DATE_ADD(@demo_now, INTERVAL v.verify_offset HOUR) END,
CASE WHEN v.status='CLOSED' THEN DATE_ADD(@demo_now, INTERVAL v.close_offset HOUR) END,
CASE WHEN v.status='CANCELLED' THEN DATE_ADD(@demo_now, INTERVAL v.cancel_offset HOUR) END,
CASE WHEN v.status='CANCELLED' THEN 'Requester cancelled after schedule changed.' END,
CASE WHEN v.status IN ('COMPLETED','VERIFIED','CLOSED') THEN 'Demo repair completed; area tested and returned to service.' END,
@requestor_user, @fam_user, DATE_ADD(@demo_now, INTERVAL v.created_offset HOUR), DATE_ADD(@demo_now, INTERVAL v.updated_offset HOUR)
FROM (
SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15 UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19 UNION ALL SELECT 20
) n
JOIN (
SELECT 1 n,'HVAC' cat,'HIGH' priority,'IN_PROGRESS' status,'APPROVED' approval_status,'SP-ADM-CONF-MAIN' space,'Conference room cooling is weak' subject,'Cooling drops during afternoon meetings.' description,24 req_offset,-30 created_offset,-2 updated_offset,-28 ack_offset,-27 assign_offset,-24 start_offset,NULL complete_offset,NULL verify_offset,NULL close_offset,NULL cancel_offset UNION ALL
SELECT 2,'ELEC','CRITICAL','ASSIGNED','APPROVED','SP-UTL-ELEC','Breaker trip near records wing','Panel breaker trips under normal load.',4,-12,-1,-11,-10,NULL,NULL,NULL,NULL,NULL UNION ALL
SELECT 3,'PLUMB','HIGH','SUBMITTED','PENDING','SP-ADM-LOBBY','Lobby restroom leak','Leak observed near public restroom sink.',12,-4,-4,NULL,NULL,NULL,NULL,NULL,NULL,NULL UNION ALL
SELECT 4,'CLEAN','MEDIUM','COMPLETED','NOT_REQUIRED','SP-TRN-A','Training room sanitation','Post-session cleaning and sanitation requested.',-8,-40,-6,-38,-36,-35,-10,NULL,NULL,NULL UNION ALL
SELECT 5,'CARP','LOW','VERIFIED','NOT_REQUIRED','SP-REC-OFFICE','Cabinet hinge replacement','Two cabinet hinges are loose.',48,-100,-20,-96,-90,-80,-60,-28,NULL,NULL UNION ALL
SELECT 6,'SAFE','CRITICAL','IN_PROGRESS','APPROVED','SP-ADM-LOBBY','Wet floor hazard','Recurring slip hazard after rain near entry.',2,-8,-1,-7,-6,-5,NULL,NULL,NULL,NULL UNION ALL
SELECT 7,'EQUIP','MEDIUM','PENDING_APPROVAL','PENDING','SP-TRN-B','Projector support for workshop','Projector image flickers intermittently.',30,-3,-3,NULL,NULL,NULL,NULL,NULL,NULL,NULL UNION ALL
SELECT 8,'HVAC','HIGH','CLOSED','APPROVED','SP-IT-SERVER','Server room AC alarm','Alarm reported during weekend monitoring.',-24,-160,-80,-158,-156,-150,-120,-96,-72,NULL UNION ALL
SELECT 9,'ELEC','HIGH','CANCELLED','PENDING','SP-SCM-OFFICE','Additional outlet request','Temporary extra outlets requested for sorting activity.',36,-12,-11,NULL,NULL,NULL,NULL,NULL,NULL,-10 UNION ALL
SELECT 10,'GEN','MEDIUM','DRAFT','NOT_REQUIRED','SP-OPS-STORAGE','Storage shelving assessment','Assess storage layout for supplies.',72,-1,-1,NULL,NULL,NULL,NULL,NULL,NULL,NULL UNION ALL
SELECT 11,'CLEAN','LOW','ASSIGNED','NOT_REQUIRED','SP-ADM-LOBBY','Lobby glass cleaning','Clean glass panels after visitor event.',8,-7,-1,-6,-5,NULL,NULL,NULL,NULL,NULL UNION ALL
SELECT 12,'PLUMB','MEDIUM','APPROVED','APPROVED','SP-TRN-A','Low water pressure','Pantry sink pressure is below normal.',20,-9,-2,-8,NULL,NULL,NULL,NULL,NULL,NULL UNION ALL
SELECT 13,'CARP','MEDIUM','IN_PROGRESS','APPROVED','SP-ADM-MEET-EXEC','Door closer adjustment','Meeting room door does not close properly.',6,-20,-1,-18,-15,-12,NULL,NULL,NULL,NULL UNION ALL
SELECT 14,'EQUIP','LOW','COMPLETED','NOT_REQUIRED','SP-TRN-B','Whiteboard replacement','Replace stained mobile whiteboard.',-6,-220,-30,-218,-216,-200,-120,NULL,NULL,NULL UNION ALL
SELECT 15,'SAFE','HIGH','SUBMITTED','PENDING','SP-MNT-WORKSHOP','Safety sign replacement','Warning signs faded near workshop entrance.',24,-2,-2,NULL,NULL,NULL,NULL,NULL,NULL,NULL UNION ALL
SELECT 16,'HVAC','MEDIUM','ASSIGNED','APPROVED','SP-ADM-MEET-EXEC','Thermostat calibration','Room temperature reading appears inaccurate.',14,-14,-1,-13,-12,NULL,NULL,NULL,NULL,NULL UNION ALL
SELECT 17,'ELEC','MEDIUM','VERIFIED','APPROVED','SP-TRN-A','Light fixture repair','Two tube lights were flickering.',-12,-380,-90,-378,-370,-350,-300,-260,NULL,NULL UNION ALL
SELECT 18,'GEN','LOW','CLOSED','NOT_REQUIRED','SP-OPS-STORAGE','Move unused chairs','Chairs moved to storage after training.',-20,-420,-100,-418,-416,-410,-360,-330,-300,NULL UNION ALL
SELECT 19,'TRANS','MEDIUM','APPROVED','APPROVED','SP-ADM-LOBBY','Vehicle support for site visit','Transport support for inspection team.',28,-6,-1,-5,NULL,NULL,NULL,NULL,NULL,NULL UNION ALL
SELECT 20,'PLUMB','HIGH','ASSIGNED','APPROVED','SP-UTL-GEN','Utility drain inspection','Drain near generator area is backing up.',3,-30,-1,-28,-27,NULL,NULL,NULL,NULL,NULL
) v ON v.n=n.n
JOIN department_reference d ON d.department_code='DEP-ADM'
JOIN facility_space s ON s.space_code=v.space
JOIN request_category c ON c.category_code=v.cat
LEFT JOIN sla_policy sp ON sp.request_category_id=c.request_category_id AND sp.priority=CASE WHEN v.priority='MEDIUM' THEN 'MEDIUM' ELSE v.priority END
ON DUPLICATE KEY UPDATE status=VALUES(status), approval_status=VALUES(approval_status), updated_at=VALUES(updated_at), resolution_summary=VALUES(resolution_summary);

INSERT INTO facility_request_history (facility_request_id, old_status, new_status, changed_by_user_id, change_reason, changed_at)
SELECT fr.facility_request_id, NULL, fr.status, @fam_user, 'Demo: current lifecycle status seeded.', fr.created_at FROM facility_request fr WHERE fr.request_number LIKE 'FR-2026-%';

INSERT INTO sla_tracking (facility_request_id, acknowledgement_due_at, assignment_due_at, resolution_due_at, acknowledged_at, assigned_at, resolved_at, acknowledgement_breached, assignment_breached, resolution_breached, breach_reason, last_evaluated_at)
SELECT fr.facility_request_id,
DATE_ADD(fr.created_at, INTERVAL COALESCE(sp.acknowledgement_minutes,120) MINUTE),
DATE_ADD(fr.created_at, INTERVAL COALESCE(sp.assignment_minutes,240) MINUTE),
DATE_ADD(fr.created_at, INTERVAL COALESCE(sp.resolution_minutes,1440) MINUTE),
fr.acknowledged_at, fr.assigned_at, fr.completed_at,
fr.acknowledged_at IS NOT NULL AND fr.acknowledged_at > DATE_ADD(fr.created_at, INTERVAL COALESCE(sp.acknowledgement_minutes,120) MINUTE),
fr.assigned_at IS NOT NULL AND fr.assigned_at > DATE_ADD(fr.created_at, INTERVAL COALESCE(sp.assignment_minutes,240) MINUTE),
(fr.completed_at IS NULL AND DATE_ADD(fr.created_at, INTERVAL COALESCE(sp.resolution_minutes,1440) MINUTE) < @demo_now) OR (fr.completed_at IS NOT NULL AND fr.completed_at > DATE_ADD(fr.created_at, INTERVAL COALESCE(sp.resolution_minutes,1440) MINUTE)),
CASE WHEN fr.request_number IN ('FR-2026-0002','FR-2026-0006','FR-2026-0020') THEN 'Demo: SLA is breached or near breach for dashboard coverage.' END,
@demo_now
FROM facility_request fr LEFT JOIN sla_policy sp ON sp.sla_policy_id=fr.sla_policy_id
WHERE fr.request_number LIKE 'FR-2026-%'
ON DUPLICATE KEY UPDATE acknowledgement_due_at=VALUES(acknowledgement_due_at), assignment_due_at=VALUES(assignment_due_at), resolution_due_at=VALUES(resolution_due_at), resolution_breached=VALUES(resolution_breached), breach_reason=VALUES(breach_reason), last_evaluated_at=VALUES(last_evaluated_at);

-- 2. AI Recommendations
INSERT INTO ai_recommendation (module_code, entity_type, entity_id, feature_type, input_hash, input_summary, output_json, suggested_category, suggested_priority, suggested_assignee, confidence_score, explanation, model_provider, model_name, model_version, recommendation_status, reviewed_by_user_id, reviewed_at, final_decision, reviewer_feedback, created_at)
SELECT 'facility_requests','facility_request',fr.facility_request_id,'triage_advisory',CONCAT('demo-fr-',fr.request_number),CONCAT('Demo summary for ',fr.subject),
JSON_OBJECT('advisoryOnly',true,'signals',JSON_ARRAY('keywords','location','past work orders')),
c.category_name, fr.priority, 'Maintenance Supervisor', v.confidence,
v.explanation,'OpenAI','demo-advisory-model','2026-07',v.status, CASE WHEN v.status<>'PENDING' THEN @fam_user END, CASE WHEN v.status<>'PENDING' THEN DATE_ADD(@demo_now, INTERVAL -1 HOUR) END, v.decision, v.feedback, DATE_ADD(@demo_now, INTERVAL -2 HOUR)
FROM (
SELECT 'FR-2026-0001' rn,0.8840 confidence,'Accepted because HVAC symptoms matched prior cooling incidents.' explanation,'ACCEPTED' status,'ACCEPTED' decision,'Category and team accepted; priority kept by dispatcher.' feedback UNION ALL
SELECT 'FR-2026-0002',0.9320,'Critical electrical advisory pending dispatcher review.','PENDING',NULL,NULL UNION ALL
SELECT 'FR-2026-0007',0.7120,'Rejected because the issue was equipment setup, not electrical repair.','REJECTED','REJECTED','Dispatcher selected equipment support instead.' UNION ALL
SELECT 'FR-2026-0015',0.8010,'Safety signage issue likely requires facility manager review.','PENDING',NULL,NULL
) v JOIN facility_request fr ON fr.request_number=v.rn JOIN request_category c ON c.request_category_id=fr.request_category_id;

-- 3. Assets: 25 assets.
INSERT INTO asset (asset_code, property_number, asset_category_id, asset_name, description, serial_number, brand, model, supplier_reference_id, acquisition_date, acquisition_cost, facility_space_id, custodian_employee_reference_id, condition_status, lifecycle_status, warranty_start_date, warranty_end_date, useful_life_years, maintenance_interval_days, last_maintenance_date, next_maintenance_date, retirement_date, qr_code_value)
SELECT CONCAT('AST-2026-',LPAD(n.n,4,'0')), CONCAT('PROP-2026-',LPAD(n.n,4,'0')), ac.asset_category_id,
CONCAT(v.name,' ',n.n), v.description, CONCAT('SN-DEMO-',LPAD(n.n,5,'0')), v.brand, v.model, sup.supplier_reference_id,
DATE_ADD('2021-01-01', INTERVAL n.n*50 DAY), 15000 + (n.n*3200), s.facility_space_id, @manager_emp,
v.condition_status, v.lifecycle_status, DATE_ADD('2021-01-01', INTERVAL n.n*50 DAY), DATE_ADD('2021-01-01', INTERVAL n.n*50+730 DAY),
ac.default_useful_life_years, ac.default_maintenance_interval_days, DATE_ADD(@demo_now, INTERVAL -(n.n*7) DAY), DATE_ADD(@demo_now, INTERVAL v.next_due DAY),
CASE WHEN v.lifecycle_status IN ('RETIRED','FOR_DISPOSAL') THEN DATE_ADD(@demo_now, INTERVAL -30 DAY) END, CONCAT('QR-AST-2026-',LPAD(n.n,4,'0'))
FROM (
SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15 UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19 UNION ALL SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23 UNION ALL SELECT 24 UNION ALL SELECT 25
) n
JOIN (
SELECT 1 n,'AST-HVAC' cat,'Split Type AC Unit' name,'Room cooling equipment.' description,'Northstar' brand,'AC-18K' model,'SP-ADM-CONF-MAIN' space,'GOOD' condition_status,'AVAILABLE' lifecycle_status,45 next_due UNION ALL
SELECT 2,'AST-HVAC','Ceiling Cassette AC','Room cooling equipment.','Northstar','CC-24K','SP-TRN-A','FAIR','AVAILABLE',7 UNION ALL
SELECT 3,'AST-ELEC','Main Panel Board','Electrical distribution panel.','Voltline','MPB-200','SP-UTL-ELEC','GOOD','AVAILABLE',30 UNION ALL
SELECT 4,'AST-ELEC','Automatic Transfer Switch','Power transfer equipment.','Voltline','ATS-100','SP-UTL-GEN','POOR','UNDER_MAINTENANCE',-5 UNION ALL
SELECT 5,'AST-FURN','Conference Table','Meeting room furniture.','FurniCore','CT-12','SP-ADM-CONF-MAIN','GOOD','AVAILABLE',210 UNION ALL
SELECT 6,'AST-FURN','Training Chair Set','Training room furniture.','FurniCore','TC-40','SP-TRN-B','FAIR','AVAILABLE',18 UNION ALL
SELECT 7,'AST-ITAV','LCD Projector','Presentation projector.','Lumaview','PX-500','SP-TRN-A','GOOD','AVAILABLE',12 UNION ALL
SELECT 8,'AST-ITAV','Video Conference Kit','Meeting camera and speaker kit.','MeetBox','VC-200','SP-ADM-MEET-EXEC','GOOD','AVAILABLE',60 UNION ALL
SELECT 9,'AST-SAFE','Fire Extinguisher','Safety extinguisher.','SafePro','FE-10','SP-ADM-LOBBY','GOOD','AVAILABLE',3 UNION ALL
SELECT 10,'AST-SAFE','Emergency Light','Emergency lighting.','SafePro','EL-2','SP-REC-OFFICE','FAIR','AVAILABLE',14 UNION ALL
SELECT 11,'AST-OFF','Multifunction Printer','Office printer and scanner.','PrintWorks','MF-33','SP-SCM-OFFICE','GOOD','AVAILABLE',90 UNION ALL
SELECT 12,'AST-OFF','Document Scanner','Records scanner.','ScanWay','DS-900','SP-REC-OFFICE','GOOD','AVAILABLE',120 UNION ALL
SELECT 13,'AST-UTIL','Standby Generator','Emergency generator.','PowerGrid','GEN-80','SP-UTL-GEN','CRITICAL','UNAVAILABLE',-1 UNION ALL
SELECT 14,'AST-UTIL','Water Pump','Utility water pump.','FlowMax','WP-20','SP-UTL-GEN','POOR','UNDER_MAINTENANCE',-10 UNION ALL
SELECT 15,'AST-HVAC','Air Curtain','Lobby air curtain.','Northstar','ACR-6','SP-ADM-LOBBY','GOOD','AVAILABLE',80 UNION ALL
SELECT 16,'AST-ITAV','Sound System','Training audio system.','AudioPeak','SS-8','SP-TRN-B','FAIR','AVAILABLE',25 UNION ALL
SELECT 17,'AST-FURN','Filing Cabinet','Records cabinet.','FurniCore','FC-4','SP-REC-OFFICE','GOOD','AVAILABLE',400 UNION ALL
SELECT 18,'AST-ELEC','UPS Unit','Server room UPS.','Voltline','UPS-6K','SP-IT-SERVER','GOOD','AVAILABLE',15 UNION ALL
SELECT 19,'AST-SAFE','Smoke Detector','Smoke detector.','SafePro','SD-1','SP-OPS-STORAGE','GOOD','AVAILABLE',20 UNION ALL
SELECT 20,'AST-OFF','Copier','Office copier.','PrintWorks','CP-70','SP-ADM-LOBBY','FAIR','AVAILABLE',6 UNION ALL
SELECT 21,'AST-ITAV','Wireless Access Point','Wi-Fi access point.','NetAxis','AP-6','SP-ADM-CONF-MAIN','GOOD','AVAILABLE',100 UNION ALL
SELECT 22,'AST-FURN','Reception Sofa','Lobby sofa.','FurniCore','RS-3','SP-ADM-LOBBY','POOR','FOR_DISPOSAL',365 UNION ALL
SELECT 23,'AST-UTIL','Industrial Fan','Workshop fan.','AirMove','IF-24','SP-MNT-WORKSHOP','FAIR','AVAILABLE',33 UNION ALL
SELECT 24,'AST-HVAC','Window AC Unit','Older cooling unit.','Northstar','WAC-10','SP-OPS-STORAGE','POOR','RETIRED',999 UNION ALL
SELECT 25,'AST-SAFE','First Aid Cabinet','Safety cabinet.','SafePro','FA-2','SP-TRN-A','GOOD','AVAILABLE',11
) v ON v.n=n.n
JOIN asset_category ac ON ac.category_code=v.cat
JOIN facility_space s ON s.space_code=v.space
LEFT JOIN supplier_reference sup ON sup.supplier_code=ELT(1 + MOD(n.n,3),'SUP-ALPHA','SUP-BRIGHT','SUP-CLEAR')
ON DUPLICATE KEY UPDATE asset_name=VALUES(asset_name), condition_status=VALUES(condition_status), lifecycle_status=VALUES(lifecycle_status), next_maintenance_date=VALUES(next_maintenance_date);

INSERT INTO asset_history (asset_id, event_type, old_space_id, new_space_id, old_condition_status, new_condition_status, old_lifecycle_status, new_lifecycle_status, remarks, changed_by_user_id, changed_at)
SELECT asset_id, 'SEEDED', NULL, facility_space_id, NULL, condition_status, NULL, lifecycle_status, 'Demo: initial asset state seeded.', @fam_user, @demo_now FROM asset WHERE asset_code LIKE 'AST-2026-%';

-- 4. Preventive Maintenance Plans
INSERT INTO preventive_maintenance_plan (plan_code, asset_id, facility_space_id, plan_name, description, frequency_days, next_due_date, assigned_to_employee_reference_id, status)
SELECT v.plan_code, a.asset_id, s.facility_space_id, v.plan_name, v.description, v.frequency_days, v.next_due_date, v.assignee, 'ACTIVE'
FROM (
SELECT 'PM-2026-HVAC-MONTHLY' plan_code,'AST-2026-0001' asset_code,NULL space_code,'Monthly HVAC Inspection' plan_name,'Inspect filters, coils, thermostat, and drain lines.' description,30 frequency_days,'2026-08-01' next_due_date,@tech2_emp assignee UNION ALL
SELECT 'PM-2026-GEN-QUARTERLY','AST-2026-0013',NULL,'Quarterly Generator Test','Run load test and inspect fuel, oil, and transfer switch.',90,'2026-07-29',@tech1_emp UNION ALL
SELECT 'PM-2026-FIRE-EXT','AST-2026-0009',NULL,'Fire Extinguisher Inspection','Check seals, pressure, labels, and placement.',30,'2026-07-27',@tech3_emp UNION ALL
SELECT 'PM-2026-ELEC-PANEL','AST-2026-0003',NULL,'Electrical Panel Inspection','Thermal scan and panel condition review.',180,'2026-08-15',@tech1_emp UNION ALL
SELECT 'PM-2026-PROJECTOR','AST-2026-0007','SP-TRN-A','Projector Cleaning and Testing','Clean filter, test lamp hours, and verify display input.',60,'2026-07-26',@tech3_emp
) v LEFT JOIN asset a ON a.asset_code=v.asset_code LEFT JOIN facility_space s ON s.space_code=v.space_code
ON DUPLICATE KEY UPDATE next_due_date=VALUES(next_due_date), assigned_to_employee_reference_id=VALUES(assigned_to_employee_reference_id), status=VALUES(status);

-- 5. Maintenance Work Orders: 15 work orders.
INSERT INTO maintenance_work_order (work_order_number, facility_request_id, preventive_maintenance_plan_id, facility_space_id, asset_id, maintenance_type, priority, status, problem_description, diagnosis, work_performed, assigned_to_employee_reference_id, scheduled_start_at, scheduled_end_at, actual_start_at, actual_end_at, downtime_minutes, estimated_cost, actual_cost, completion_notes, verified_by_employee_reference_id, verified_at, created_by_user_id, updated_by_user_id, created_at, updated_at)
SELECT CONCAT('WO-2026-',LPAD(n.n,4,'0')), fr.facility_request_id, pm.preventive_maintenance_plan_id, s.facility_space_id, a.asset_id, v.type, v.priority, v.status, v.problem,
CASE WHEN v.status IN ('IN_PROGRESS','COMPLETED','VERIFIED') THEN 'Demo diagnosis recorded after inspection.' END,
CASE WHEN v.status IN ('COMPLETED','VERIFIED') THEN 'Demo work performed and area cleaned.' END,
ELT(1 + MOD(n.n,3), @tech1_emp, @tech2_emp, @tech3_emp),
DATE_ADD(@demo_now, INTERVAL v.sched_start HOUR), DATE_ADD(@demo_now, INTERVAL v.sched_end HOUR),
CASE WHEN v.status IN ('IN_PROGRESS','COMPLETED','VERIFIED') THEN DATE_ADD(@demo_now, INTERVAL v.actual_start HOUR) END,
CASE WHEN v.status IN ('COMPLETED','VERIFIED') THEN DATE_ADD(@demo_now, INTERVAL v.actual_end HOUR) END,
CASE WHEN v.status IN ('COMPLETED','VERIFIED') THEN 60 + n.n*5 END, 1000+n.n*250,
CASE WHEN v.status IN ('COMPLETED','VERIFIED') THEN 950+n.n*220 END,
CASE WHEN v.status IN ('COMPLETED','VERIFIED') THEN 'Demo completion notes for verification.' END,
CASE WHEN v.status='VERIFIED' THEN @supervisor_emp END, CASE WHEN v.status='VERIFIED' THEN DATE_ADD(@demo_now, INTERVAL -1 HOUR) END,
@fam_user, @fam_user, DATE_ADD(@demo_now, INTERVAL v.created HOUR), DATE_ADD(@demo_now, INTERVAL v.updated HOUR)
FROM (SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15) n
JOIN (
SELECT 1 n,'FR-2026-0001' fr,'PM-2026-HVAC-MONTHLY' pm,'AST-2026-0001' asset,'SP-ADM-CONF-MAIN' space,'CORRECTIVE' type,'HIGH' priority,'IN_PROGRESS' status,'Restore conference room cooling.' problem,-3,-1,-2,NULL,-24,-1 UNION ALL
SELECT 2,'FR-2026-0002',NULL,'AST-2026-0003','SP-UTL-ELEC','EMERGENCY','CRITICAL','ASSIGNED','Investigate breaker trip.',-2,0,NULL,NULL,-10,-1 UNION ALL
SELECT 3,NULL,'PM-2026-GEN-QUARTERLY','AST-2026-0013','SP-UTL-GEN','PREVENTIVE','HIGH','SCHEDULED','Quarterly generator load test.',24,28,NULL,NULL,-2,-2 UNION ALL
SELECT 4,'FR-2026-0004',NULL,NULL,'SP-TRN-A','CORRECTIVE','MEDIUM','COMPLETED','Complete sanitation request.',-40,-36,-39,-35,-42,-30 UNION ALL
SELECT 5,'FR-2026-0005',NULL,'AST-2026-0017','SP-REC-OFFICE','CORRECTIVE','LOW','VERIFIED','Replace cabinet hinges.',-100,-96,-98,-92,-110,-20 UNION ALL
SELECT 6,'FR-2026-0006',NULL,NULL,'SP-ADM-LOBBY','EMERGENCY','CRITICAL','IN_PROGRESS','Resolve wet floor hazard.',-6,-3,-5,NULL,-8,-1 UNION ALL
SELECT 7,NULL,'PM-2026-FIRE-EXT','AST-2026-0009','SP-ADM-LOBBY','PREVENTIVE','MEDIUM','OPEN','Monthly extinguisher checks.',18,22,NULL,NULL,-1,-1 UNION ALL
SELECT 8,'FR-2026-0008',NULL,'AST-2026-0018','SP-IT-SERVER','CORRECTIVE','HIGH','COMPLETED','Server room alarm response.',-150,-145,-149,-140,-160,-80 UNION ALL
SELECT 9,NULL,'PM-2026-PROJECTOR','AST-2026-0007','SP-TRN-A','PREVENTIVE','LOW','ASSIGNED','Projector cleaning and testing due today.',3,5,NULL,NULL,-4,-1 UNION ALL
SELECT 10,'FR-2026-0013',NULL,NULL,'SP-ADM-MEET-EXEC','CORRECTIVE','MEDIUM','ON_HOLD','Door closer adjustment awaiting part.',-12,-8,-10,NULL,-20,-1 UNION ALL
SELECT 11,'FR-2026-0014',NULL,'AST-2026-0007','SP-TRN-B','CORRECTIVE','LOW','COMPLETED','Whiteboard and AV readiness check.',-120,-118,-119,-116,-220,-30 UNION ALL
SELECT 12,'FR-2026-0016',NULL,'AST-2026-0008','SP-ADM-MEET-EXEC','CORRECTIVE','MEDIUM','ASSIGNED','Calibrate thermostat.',4,6,NULL,NULL,-13,-1 UNION ALL
SELECT 13,'FR-2026-0017',NULL,NULL,'SP-TRN-A','CORRECTIVE','MEDIUM','VERIFIED','Replace flickering light fixtures.',-350,-348,-349,-346,-380,-90 UNION ALL
SELECT 14,NULL,'PM-2026-ELEC-PANEL','AST-2026-0003','SP-UTL-ELEC','PREVENTIVE','MEDIUM','CANCELLED','Panel inspection moved to next window.',72,76,NULL,NULL,-5,-3 UNION ALL
SELECT 15,'FR-2026-0020',NULL,'AST-2026-0014','SP-UTL-GEN','CORRECTIVE','HIGH','ASSIGNED','Inspect utility drain and pump.',-1,3,NULL,NULL,-28,-1
) v ON v.n=n.n
LEFT JOIN facility_request fr ON fr.request_number=v.fr
LEFT JOIN preventive_maintenance_plan pm ON pm.plan_code=v.pm
LEFT JOIN asset a ON a.asset_code=v.asset
JOIN facility_space s ON s.space_code=v.space
ON DUPLICATE KEY UPDATE status=VALUES(status), updated_at=VALUES(updated_at), actual_cost=VALUES(actual_cost);

INSERT INTO maintenance_history (maintenance_work_order_id, old_status, new_status, changed_by_user_id, change_reason, changed_at)
SELECT maintenance_work_order_id, NULL, status, @fam_user, 'Demo: current work order status seeded.', created_at FROM maintenance_work_order WHERE work_order_number LIKE 'WO-2026-%';

INSERT INTO maintenance_material (maintenance_work_order_id, inventory_item_reference_id, item_description, quantity, unit_of_measure, unit_cost, total_cost, source_type, remarks)
SELECT wo.maintenance_work_order_id, item.inventory_item_reference_id, item.item_name, 1 + MOD(wo.maintenance_work_order_id,3), item.unit_of_measure, 350.00, 350.00*(1 + MOD(wo.maintenance_work_order_id,3)), 'STOCK', 'Demo material usage.'
FROM maintenance_work_order wo JOIN inventory_item_reference item ON item.item_code=ELT(1 + MOD(wo.maintenance_work_order_id,5),'INV-FILTER-24X24','INV-BREAKER-30A','INV-LED-TUBE','INV-CLEAN-KIT','INV-PROJ-LAMP')
WHERE wo.work_order_number LIKE 'WO-2026-%';

-- 6. Reservations: 15 reservations, including one deliberate conflict for UI demonstration.
INSERT INTO facility_reservation (reservation_number, facility_space_id, requested_by_employee_reference_id, department_reference_id, reservation_type, purpose, expected_attendees, setup_requirements, start_datetime, end_datetime, setup_buffer_minutes, cleanup_buffer_minutes, status, approval_status, approved_by_employee_reference_id, approved_at, checked_in_at, checked_out_at, cancellation_reason, remarks, created_by_user_id, updated_by_user_id, created_at, updated_at)
SELECT CONCAT('RR-2026-',LPAD(n.n,4,'0')), s.facility_space_id, @requestor_emp, d.department_reference_id, v.type, v.purpose, v.attendees, v.setup, v.start_dt, v.end_dt, 15, 15, v.status, v.approval_status,
CASE WHEN v.approval_status='APPROVED' THEN @manager_emp END, CASE WHEN v.approval_status='APPROVED' THEN DATE_ADD(v.start_dt, INTERVAL -24 HOUR) END,
v.checkin, v.checkout, CASE WHEN v.status='CANCELLED' THEN 'Demo cancellation by organizer.' END, v.remarks, @requestor_user, @fam_user, DATE_ADD(v.start_dt, INTERVAL -72 HOUR), DATE_ADD(v.start_dt, INTERVAL -2 HOUR)
FROM (SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15) n
JOIN (
SELECT 1 n,'SP-ADM-CONF-MAIN' space,'MEETING','Division coordination meeting' purpose,24 attendees,'Projector, video conferencing, Wi-Fi','2026-07-26 09:00:00' start_dt,'2026-07-26 11:00:00' end_dt,'CHECKED_IN' status,'APPROVED' approval_status,'2026-07-26 08:55:00' checkin,NULL checkout,'Today schedule item' remarks UNION ALL
SELECT 2,'SP-ADM-CONF-MAIN','MEETING','Deliberate mock conflict for calendar testing',18,'Projector','2026-07-26 10:00:00','2026-07-26 12:00:00','APPROVED','APPROVED',NULL,NULL,'Demo conflict; application validation should prevent this in real use.' UNION ALL
SELECT 3,'SP-TRN-A','TRAINING','Safety briefing','30','Sound system, whiteboard','2026-07-27 09:00:00','2026-07-27 12:00:00','APPROVED','APPROVED',NULL,NULL,'Tomorrow training' UNION ALL
SELECT 4,'SP-TRN-B','WORKSHOP','Procurement orientation','28','Projector, Wi-Fi','2026-07-28 13:00:00','2026-07-28 16:00:00','PENDING','PENDING',NULL,NULL,'Pending approval' UNION ALL
SELECT 5,'SP-ADM-MEET-EXEC','MEETING','Budget review','12','Video conferencing','2026-07-29 10:00:00','2026-07-29 11:30:00','APPROVED','APPROVED',NULL,NULL,'Next seven days' UNION ALL
SELECT 6,'SP-TRN-A','TRAINING','Records workshop','32','Projector','2026-07-30 09:00:00','2026-07-30 16:00:00','APPROVED','APPROVED',NULL,NULL,'Full day session' UNION ALL
SELECT 7,'SP-TRN-B','TRAINING','Asset tagging orientation','26','Projector','2026-08-01 09:00:00','2026-08-01 11:00:00','APPROVED','APPROVED',NULL,NULL,'Next seven days' UNION ALL
SELECT 8,'SP-ADM-CONF-MAIN','MEETING','Weekly operations sync','20','Video conferencing','2026-07-25 09:00:00','2026-07-25 10:30:00','COMPLETED','APPROVED','2026-07-25 08:55:00','2026-07-25 10:35:00','Historical completed' UNION ALL
SELECT 9,'SP-TRN-A','TRAINING','Fire drill briefing','35','Sound system','2026-07-24 14:00:00','2026-07-24 16:00:00','COMPLETED','APPROVED','2026-07-24 13:50:00','2026-07-24 16:05:00','Historical completed' UNION ALL
SELECT 10,'SP-ADM-MEET-EXEC','MEETING','Supplier interview','8','Video conferencing','2026-07-23 10:00:00','2026-07-23 11:00:00','NO_SHOW','APPROVED',NULL,NULL,'No show demo status' UNION ALL
SELECT 11,'SP-TRN-B','WORKSHOP','Cancelled planning workshop','20','Whiteboard','2026-07-31 13:00:00','2026-07-31 15:00:00','CANCELLED','PENDING',NULL,NULL,'Cancelled reservation' UNION ALL
SELECT 12,'SP-ADM-CONF-MAIN','MEETING','Executive review','34','Projector','2026-08-02 10:00:00','2026-08-02 12:00:00','APPROVED','APPROVED',NULL,NULL,'Upcoming' UNION ALL
SELECT 13,'SP-TRN-A','TRAINING','Maintenance onboarding','30','Projector','2026-08-03 09:00:00','2026-08-03 12:00:00','APPROVED','APPROVED',NULL,NULL,'Upcoming' UNION ALL
SELECT 14,'SP-ADM-MEET-EXEC','MEETING','Audit entrance meeting','10','Video conferencing','2026-07-26 14:00:00','2026-07-26 15:00:00','APPROVED','APPROVED',NULL,NULL,'Today schedule item' UNION ALL
SELECT 15,'SP-TRN-B','TRAINING','Records retention clinic','22','Projector, whiteboard','2026-07-22 09:00:00','2026-07-22 11:30:00','COMPLETED','APPROVED','2026-07-22 08:45:00','2026-07-22 11:40:00','Historical completed'
) v ON v.n=n.n
JOIN facility_space s ON s.space_code=v.space JOIN department_reference d ON d.department_code='DEP-ADM'
ON DUPLICATE KEY UPDATE status=VALUES(status), approval_status=VALUES(approval_status), remarks=VALUES(remarks);

INSERT INTO reservation_history (facility_reservation_id, old_status, new_status, changed_by_user_id, change_reason, changed_at)
SELECT facility_reservation_id, NULL, status, @fam_user, 'Demo: reservation status seeded.', created_at FROM facility_reservation WHERE reservation_number LIKE 'RR-2026-%';

INSERT IGNORE INTO reservation_participant (facility_reservation_id, employee_reference_id, participant_role, attendance_status)
SELECT r.facility_reservation_id, e.employee_reference_id, 'PARTICIPANT', CASE WHEN r.status='COMPLETED' THEN 'PRESENT' END
FROM facility_reservation r JOIN employee_reference e ON e.employee_number IN ('EMP-2026-0003','EMP-2026-0004','EMP-2026-0012')
WHERE r.reservation_number LIKE 'RR-2026-%';

-- 7. Procurement Requests: 12 requests with items and selected PO references.
INSERT INTO procurement_request (request_number, requested_by_employee_reference_id, department_reference_id, facility_request_id, maintenance_work_order_id, budget_reference_id, justification, priority, estimated_total, currency_code, status, approval_status, external_procurement_id, external_status, integration_status, submitted_to_external_at, last_synced_at, integration_error_code, integration_error_message, expected_delivery_date, completed_at, created_by_user_id, updated_by_user_id, created_at, updated_at)
SELECT CONCAT('PR-2026-',LPAD(n.n,4,'0')), @requestor_emp, d.department_reference_id, fr.facility_request_id, wo.maintenance_work_order_id, b.budget_reference_id,
v.justification, v.priority, v.total, 'PHP', v.status, v.approval, CASE WHEN v.integration_status<>'NOT_SUBMITTED' THEN CONCAT('EXT-PR-2026-',LPAD(n.n,4,'0')) END,
v.external_status, v.integration_status, CASE WHEN v.integration_status IN ('SUBMITTED','SYNCED','FAILED') THEN DATE_ADD(@demo_now, INTERVAL -4 HOUR) END,
CASE WHEN v.integration_status IN ('SYNCED','FAILED') THEN DATE_ADD(@demo_now, INTERVAL -1 HOUR) END,
CASE WHEN v.integration_status='FAILED' THEN 'SCM_VALIDATION_ERROR' END, CASE WHEN v.integration_status='FAILED' THEN 'Demo: missing item mapping in external SCM.' END,
DATE_ADD('2026-07-26', INTERVAL 7+n.n DAY), CASE WHEN v.status IN ('COMPLETED','FULFILLED') THEN DATE_ADD(@demo_now, INTERVAL -24 HOUR) END,
@requestor_user, @fam_user, DATE_ADD(@demo_now, INTERVAL -n.n*10 HOUR), DATE_ADD(@demo_now, INTERVAL -n.n HOUR)
FROM (SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12) n
JOIN (
SELECT 1 n,'FR-2026-0001' fr,'WO-2026-0001' wo,'BUD-MNT-PM' bud,'Replacement filters for HVAC corrective work.' justification,'HIGH' priority,9200 total,'PENDING_APPROVAL' status,'PENDING' approval,'NOT_SUBMITTED' integration_status,NULL external_status UNION ALL
SELECT 2,'FR-2026-0002','WO-2026-0002','BUD-MNT-PM','Electrical breaker replacement for safety issue.','CRITICAL',18500,'APPROVED','APPROVED','SUBMITTED','Queued in SCM' UNION ALL
SELECT 3,NULL,'WO-2026-0003','BUD-MNT-PM','Generator test consumables and service supplies.','HIGH',12600,'FORWARDED_TO_SUPPLY_CHAIN','APPROVED','SYNCED','Received by SCM' UNION ALL
SELECT 4,'FR-2026-0007',NULL,'BUD-IT-EQP','Projector lamp and HDMI kit replacement.','MEDIUM',21000,'PROCESSING','APPROVED','SYNCED','Processing' UNION ALL
SELECT 5,NULL,NULL,'BUD-ADM-SUP','Administrative cleaning consumables.','LOW',7800,'FULFILLED','APPROVED','SYNCED','Fulfilled' UNION ALL
SELECT 6,NULL,NULL,'BUD-FAC-OPS','Lobby safety signage replacement.','MEDIUM',5400,'SUBMITTED','PENDING','NOT_SUBMITTED',NULL UNION ALL
SELECT 7,NULL,'WO-2026-0010','BUD-MNT-PM','Door closer replacement part.','MEDIUM',4300,'PARTIALLY_FULFILLED','APPROVED','SYNCED','Partially fulfilled' UNION ALL
SELECT 8,NULL,NULL,'BUD-IT-EQP','Wireless access point replacement stock.','MEDIUM',36000,'DRAFT','PENDING','NOT_SUBMITTED',NULL UNION ALL
SELECT 9,NULL,NULL,'BUD-FAC-OPS','Asset replacement for retired AC unit.','HIGH',42000,'PENDING_APPROVAL','PENDING','NOT_SUBMITTED',NULL UNION ALL
SELECT 10,NULL,NULL,'BUD-ADM-SUP','Records archival folders and boxes.','LOW',11200,'COMPLETED','APPROVED','SYNCED','Completed' UNION ALL
SELECT 11,'FR-2026-0020','WO-2026-0015','BUD-MNT-PM','Utility pump inspection materials.','HIGH',16500,'INTEGRATION_FAILED','APPROVED','FAILED','Rejected by SCM' UNION ALL
SELECT 12,NULL,NULL,'BUD-FAC-OPS','Preventive maintenance spare parts bundle.','MEDIUM',28500,'CANCELLED','PENDING','NOT_SUBMITTED',NULL
) v ON v.n=n.n
JOIN department_reference d ON d.department_code='DEP-ADM'
LEFT JOIN facility_request fr ON fr.request_number=v.fr
LEFT JOIN maintenance_work_order wo ON wo.work_order_number=v.wo
JOIN budget_reference b ON b.budget_code=v.bud AND b.fiscal_year=2026
ON DUPLICATE KEY UPDATE status=VALUES(status), approval_status=VALUES(approval_status), estimated_total=VALUES(estimated_total), integration_status=VALUES(integration_status);

INSERT INTO procurement_request_item (procurement_request_id, inventory_item_reference_id, item_description, quantity, unit_of_measure, estimated_unit_cost, estimated_total_cost, specifications)
SELECT pr.procurement_request_id, item.inventory_item_reference_id, item.item_name, 2, item.unit_of_measure, pr.estimated_total/2, pr.estimated_total, 'Demo item line matched to request total for simple validation.'
FROM procurement_request pr JOIN inventory_item_reference item ON item.item_code=ELT(1 + MOD(pr.procurement_request_id,5),'INV-FILTER-24X24','INV-BREAKER-30A','INV-LED-TUBE','INV-CLEAN-KIT','INV-PROJ-LAMP')
WHERE pr.request_number LIKE 'PR-2026-%';

INSERT INTO procurement_history (procurement_request_id, old_status, new_status, changed_by_user_id, change_reason, changed_at)
SELECT procurement_request_id, NULL, status, @fam_user, 'Demo: procurement status seeded.', created_at FROM procurement_request WHERE request_number LIKE 'PR-2026-%';

INSERT INTO purchase_order_reference (procurement_request_id, supplier_reference_id, external_purchase_order_id, purchase_order_number, order_date, expected_delivery_date, total_amount, currency_code, purchase_order_status, source_system, sync_status, last_synced_at)
SELECT pr.procurement_request_id, sup.supplier_reference_id, CONCAT('EXT-PO-2026-',SUBSTRING(pr.request_number,9)), CONCAT('PO-2026-',SUBSTRING(pr.request_number,9)), '2026-07-24', pr.expected_delivery_date, pr.estimated_total, 'PHP', 'OPEN', 'SCM', 'SYNCED', @demo_now
FROM procurement_request pr JOIN supplier_reference sup ON sup.supplier_code='SUP-ALPHA'
WHERE pr.request_number IN ('PR-2026-0003','PR-2026-0004','PR-2026-0005','PR-2026-0010')
ON DUPLICATE KEY UPDATE purchase_order_status=VALUES(purchase_order_status), total_amount=VALUES(total_amount), sync_status=VALUES(sync_status);

-- 8. Documents and Administrative Records
INSERT INTO document (document_number, document_category_id, document_title, document_description, document_status, confidentiality_level, current_version_number, document_date, effective_date, expiration_date, uploaded_by_user_id, owner_employee_reference_id, created_at, updated_at)
SELECT CONCAT('DOC-2026-',LPAD(n.n,4,'0')), dc.document_category_id, v.title, v.description, v.status, v.confidentiality, v.version_no, DATE_ADD('2026-01-01', INTERVAL n.n*7 DAY), DATE_ADD('2026-01-01', INTERVAL n.n*7 DAY), v.expiration_date, @fam_user, @records_emp, DATE_ADD(@demo_now, INTERVAL -n.n DAY), DATE_ADD(@demo_now, INTERVAL -MOD(n.n,5) DAY)
FROM (SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15 UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19 UNION ALL SELECT 20) n
JOIN (
SELECT 1 n,'DOC-ADM' cat,'Facility Access Policy' title,'Policy document for facility access.' description,'ACTIVE' status,'INTERNAL' confidentiality,2 version_no,'2027-01-31' expiration_date UNION ALL
SELECT 2,'DOC-MNT','HVAC Maintenance SOP','Maintenance procedure.' ,'ACTIVE','INTERNAL',1,'2026-08-15' UNION ALL
SELECT 3,'DOC-ADM','Service Request Form','Administrative form.','ACTIVE','PUBLIC',1,NULL UNION ALL
SELECT 4,'DOC-PERMIT','Administration Building Permit','Building permit record.','ACTIVE','PUBLIC',1,'2026-09-30' UNION ALL
SELECT 5,'DOC-PERMIT','Fire Safety Certificate','Fire safety certificate.','ACTIVE','PUBLIC',1,'2026-08-05' UNION ALL
SELECT 6,'DOC-MNT','Generator Inspection Report','Inspection report.','ACTIVE','INTERNAL',1,NULL UNION ALL
SELECT 7,'DOC-AST','Projector Manual','Equipment manual.','ACTIVE','INTERNAL',1,NULL UNION ALL
SELECT 8,'DOC-ADM','Ground Floor Plan','Floor plan.','ACTIVE','INTERNAL',3,NULL UNION ALL
SELECT 9,'DOC-CON','Maintenance Services Contract File','Contract file.','ACTIVE','CONFIDENTIAL',1,'2026-12-31' UNION ALL
SELECT 10,'DOC-CON','Facilities MOA','Memorandum of agreement.','ACTIVE','CONFIDENTIAL',1,'2026-10-30' UNION ALL
SELECT 11,'DOC-MNT','Pump Maintenance Manual','Maintenance manual.','ACTIVE','INTERNAL',1,NULL UNION ALL
SELECT 12,'DOC-PR','Procurement Quote Abstract','Procurement support document.','PENDING_REVIEW','CONFIDENTIAL',1,NULL UNION ALL
SELECT 13,'DOC-FR','Facility Request Photo Set','Request attachment index.','ACTIVE','INTERNAL',1,NULL UNION ALL
SELECT 14,'DOC-RES','Reservation Layout Sheet','Reservation setup document.','ACTIVE','INTERNAL',1,NULL UNION ALL
SELECT 15,'DOC-AST','Asset Warranty Packet','Warranty documents.','ACTIVE','INTERNAL',1,'2026-11-15' UNION ALL
SELECT 16,'DOC-LEGAL','Lease Review Notes','Legal notes.','ACTIVE','CONFIDENTIAL',1,NULL UNION ALL
SELECT 17,'DOC-ADM','Visitor Management SOP','Visitor procedure.','PENDING_REVIEW','INTERNAL',2,'2026-08-20' UNION ALL
SELECT 18,'DOC-PERMIT','Elevator Safety Certificate','Certificate for monitoring.','ARCHIVED','PUBLIC',1,'2026-07-31' UNION ALL
SELECT 19,'DOC-MNT','Electrical Panel Test Report','Inspection report.','ACTIVE','INTERNAL',1,NULL UNION ALL
SELECT 20,'DOC-ADM','Records Transfer Form','Administrative records form.','ACTIVE','PUBLIC',1,NULL
) v ON v.n=n.n JOIN document_category dc ON dc.category_code=v.cat
ON DUPLICATE KEY UPDATE document_title=VALUES(document_title), document_status=VALUES(document_status), expiration_date=VALUES(expiration_date), current_version_number=VALUES(current_version_number);

INSERT INTO document_version (document_id, version_number, file_name, file_extension, mime_type, file_size, storage_path, file_hash, change_summary, uploaded_by_user_id, uploaded_at, is_current)
SELECT d.document_id, v.version_number, CONCAT(d.document_number,'-v',v.version_number,'.pdf'), 'pdf', 'application/pdf', 102400 + d.document_id*100, CONCAT('/demo-storage/documents/',d.document_number,'/v',v.version_number,'.pdf'), SHA2(CONCAT(d.document_number,'-',v.version_number),256), 'Demo version metadata only; file path is fictional.', @fam_user, DATE_ADD(d.created_at, INTERVAL v.version_number HOUR), v.version_number=d.current_version_number
FROM document d JOIN (SELECT 1 version_number UNION ALL SELECT 2 UNION ALL SELECT 3) v ON v.version_number<=d.current_version_number
WHERE d.document_number LIKE 'DOC-2026-%';

INSERT INTO record (record_number, record_title, record_description, record_type, retention_schedule_id, originating_department_reference_id, record_owner_employee_reference_id, source_module, source_entity_type, source_entity_id, record_date, retention_start_date, retention_trigger_basis, retention_trigger_date, policy_eligibility_date, administrative_review_date_override, scheduled_disposition_date, retention_trigger_state, record_status, confidentiality_level, created_by_user_id, updated_by_user_id, created_at, updated_at)
SELECT CONCAT('REC-2026-',LPAD(n.n,4,'0')), CONCAT(v.title,' ',n.n), 'Demo administrative record for dashboard and records module.', v.record_type, rs.retention_schedule_id, d.department_reference_id, @records_emp, v.module_code, v.entity_type, v.entity_id, DATE_ADD('2026-01-01', INTERVAL n.n*8 DAY), DATE_ADD('2026-01-01', INTERVAL n.n*8 DAY), rs.retention_trigger_basis, NULL, NULL, NULL, NULL, 'WAITING_FOR_TRIGGER', v.status, v.confidentiality, @fam_user, @fam_user, DATE_ADD(@demo_now, INTERVAL -n.n DAY), DATE_ADD(@demo_now, INTERVAL -MOD(n.n,6) DAY)
FROM (SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10) n
JOIN (
SELECT 1 n,'Administrative File' title,'Administrative' record_type,'RET-ADM-005' sched,'administration' module_code,'DEMO_RECORD' entity_type,1 entity_id,'2031-01-01' disposition_date,'ACTIVE' status,'INTERNAL' confidentiality UNION ALL
SELECT 2,'Maintenance File','Maintenance','RET-MNT-007','maintenance','DEMO_RECORD',2,'2033-01-01','ACTIVE','INTERNAL' UNION ALL
SELECT 3,'Asset File','Assets','RET-AST-010','assets','DEMO_RECORD',3,'2036-01-01','ACTIVE','INTERNAL' UNION ALL
SELECT 4,'Procurement File','Procurement','RET-PR-007','procurement','DEMO_RECORD',4,'2033-01-01','PENDING_REVIEW','CONFIDENTIAL' UNION ALL
SELECT 5,'Contract File','Contracts','RET-CON-010','contracts','DEMO_RECORD',5,'2036-01-01','ACTIVE','CONFIDENTIAL' UNION ALL
SELECT 6,'Expiring Certificate','Administrative','RET-ADM-005','records','DEMO_RECORD',6,'2026-08-15','PENDING_REVIEW','PUBLIC' UNION ALL
SELECT 7,'Archived Inspection','Maintenance','RET-MNT-007','maintenance','DEMO_RECORD',7,'2032-01-01','ARCHIVED','INTERNAL' UNION ALL
SELECT 8,'Closed Procurement','Procurement','RET-PR-007','procurement','DEMO_RECORD',8,'2033-01-01','ACTIVE','CONFIDENTIAL' UNION ALL
SELECT 9,'Records Transfer','Administrative','RET-ADM-005','records','DEMO_RECORD',9,'2026-09-01','PENDING_REVIEW','INTERNAL' UNION ALL
SELECT 10,'Contract Closeout','Contracts','RET-CON-010','contracts','DEMO_RECORD',10,'2036-01-01','ACTIVE','CONFIDENTIAL'
) v ON v.n=n.n
JOIN retention_schedule rs ON rs.schedule_code=v.sched JOIN department_reference d ON d.department_code='DEP-REC'
ON DUPLICATE KEY UPDATE record_status=VALUES(record_status), retention_trigger_basis=VALUES(retention_trigger_basis), retention_trigger_date=VALUES(retention_trigger_date), policy_eligibility_date=VALUES(policy_eligibility_date), administrative_review_date_override=VALUES(administrative_review_date_override), scheduled_disposition_date=VALUES(scheduled_disposition_date), retention_trigger_state=VALUES(retention_trigger_state), updated_at=VALUES(updated_at);

INSERT IGNORE INTO record_document (record_id, document_id, is_primary_document, added_by_user_id)
SELECT r.record_id, d.document_id, TRUE, @fam_user
FROM record r JOIN document d ON CAST(SUBSTRING(r.record_number,10) AS UNSIGNED)=CAST(SUBSTRING(d.document_number,10) AS UNSIGNED)
WHERE r.record_number LIKE 'REC-2026-%' AND d.document_number LIKE 'DOC-2026-%';

-- 9. Contracts
INSERT INTO contract (contract_number, contract_type_id, contract_title, contract_description, supplier_reference_id, budget_reference_id, contract_owner_employee_reference_id, start_date, end_date, original_amount, current_amount, currency_code, contract_status, notice_period_days)
SELECT CONCAT('CON-2026-',LPAD(n.n,4,'0')), ct.contract_type_id, v.title, v.description, sup.supplier_reference_id, b.budget_reference_id, @manager_emp, v.start_date, v.end_date, v.amount, v.amount, 'PHP', v.status, 60
FROM (SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5) n
JOIN (
SELECT 1 n,'MAINTENANCE' type_code,'Campus Preventive Maintenance Services' title,'Maintenance services for critical equipment.' description,'SUP-BRIGHT' supplier,'BUD-MNT-PM' budget,'2026-01-01' start_date,'2026-12-31' end_date,850000 amount,'ACTIVE' status UNION ALL
SELECT 2,'SUPPLY','Facility Consumables Supply Agreement','Supply agreement for consumables.','SUP-ALPHA','BUD-FAC-OPS','2026-02-01','2027-01-31',420000,'ACTIVE' UNION ALL
SELECT 3,'LEASE','Temporary Training Equipment Lease','Lease for training equipment.','SUP-CLEAR','BUD-ADM-SUP','2026-06-01','2026-08-15',120000,'ACTIVE' UNION ALL
SELECT 4,'SERVICE','Technical Services Retainer','Technical support services.','SUP-BRIGHT','BUD-IT-EQP','2025-07-01','2026-06-30',300000,'EXPIRED' UNION ALL
SELECT 5,'SERVICE','Archive Digitization Services','Records digitization support.','SUP-CLEAR','BUD-ADM-SUP','2026-03-01','2026-07-15',180000,'EXPIRED'
) v ON v.n=n.n JOIN contract_type ct ON ct.type_code=v.type_code JOIN supplier_reference sup ON sup.supplier_code=v.supplier JOIN budget_reference b ON b.budget_code=v.budget AND b.fiscal_year=2026
ON DUPLICATE KEY UPDATE contract_status=VALUES(contract_status), end_date=VALUES(end_date), current_amount=VALUES(current_amount);

-- 10. Visitors and Visits
INSERT INTO visitor (first_name, last_name, organization_name, visitor_type, email_address, contact_number, id_type, id_number_encrypted, status)
SELECT CONCAT('Visitor',n.n), CONCAT('Demo',n.n), 'Fictional Partner Office', 'GUEST', CONCAT('visitor',n.n,'@visitor.example-agency.test'), CONCAT('+63-2-555-02',LPAD(n.n,2,'0')), 'Demo ID', NULL, 'ACTIVE'
FROM (SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8) n;

INSERT INTO visit (visit_number, visitor_id, host_employee_reference_id, facility_reservation_id, destination_space_id, purpose, scheduled_arrival, scheduled_departure, actual_time_in, actual_time_out, visit_status, remarks)
SELECT CONCAT('VIS-2026-',LPAD(n.n,4,'0')), v.visitor_id, @manager_emp, r.facility_reservation_id, s.facility_space_id, 'Demo visitor appointment.', DATE_ADD(@demo_now, INTERVAL n.n-4 HOUR), DATE_ADD(@demo_now, INTERVAL n.n-2 HOUR),
CASE WHEN n.n IN (2,3,4) THEN DATE_ADD(@demo_now, INTERVAL n.n-4 HOUR) END,
CASE WHEN n.n IN (2,3) THEN DATE_ADD(@demo_now, INTERVAL n.n-2 HOUR) END,
ELT(n.n,'SCHEDULED','COMPLETED','COMPLETED','CHECKED_IN','SCHEDULED','CANCELLED','SCHEDULED','SCHEDULED'), 'Demo visit status.'
FROM (SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8) n
JOIN visitor v ON v.email_address=CONCAT('visitor',n.n,'@visitor.example-agency.test')
LEFT JOIN facility_reservation r ON r.reservation_number=CONCAT('RR-2026-',LPAD(LEAST(n.n,5),4,'0'))
JOIN facility_space s ON s.space_code=ELT(1 + MOD(n.n,4),'SP-ADM-CONF-MAIN','SP-TRN-A','SP-ADM-MEET-EXEC','SP-ADM-LOBBY')
ON DUPLICATE KEY UPDATE visit_status=VALUES(visit_status), actual_time_in=VALUES(actual_time_in), actual_time_out=VALUES(actual_time_out);

INSERT INTO visitor_pass (visit_id, pass_number, pass_type, issued_at, returned_at, pass_status, issued_by_user_id)
SELECT visit_id, CONCAT('VP-2026-',SUBSTRING(visit_number,10)), 'VISITOR', COALESCE(actual_time_in, scheduled_arrival), actual_time_out, CASE WHEN actual_time_out IS NULL THEN 'ISSUED' ELSE 'RETURNED' END, @fam_user FROM visit WHERE visit_number LIKE 'VIS-2026-%'
ON DUPLICATE KEY UPDATE pass_status=VALUES(pass_status), returned_at=VALUES(returned_at);

-- 11-13. Notifications, Workflow Tasks, Activity Events
INSERT INTO workflow_task (task_reference, module_code, entity_type, entity_id, task_type, title, description, assigned_to_employee_reference_id, assigned_role_id, priority, status, due_at, created_by_user_id, created_at)
SELECT CONCAT('TASK-2026-',LPAD(n.n,4,'0')), v.module_code, v.entity_type, v.entity_id, v.task_type, v.title, v.description, v.emp_id, r.role_id, v.priority, v.status, v.due_at, @fam_user, DATE_ADD(@demo_now, INTERVAL -n.n HOUR)
FROM (
SELECT 1 n,'facility_requests' module_code,'facility_request' entity_type,(SELECT facility_request_id FROM facility_request WHERE request_number='FR-2026-0003') entity_id,'ASSIGN_REQUEST' task_type,'Assign facility request' title,'Demo task for unassigned request.' description,@manager_emp emp_id,'FACILITY_MANAGER' role_code,'HIGH' priority,'PENDING' status,'2026-07-26 13:00:00' due_at UNION ALL
SELECT 2,'reservations','facility_reservation',(SELECT facility_reservation_id FROM facility_reservation WHERE reservation_number='RR-2026-0004'),'APPROVE_RESERVATION','Approve reservation','Demo reservation approval task.',@manager_emp,'FACILITY_MANAGER','MEDIUM','PENDING','2026-07-26 16:00:00' UNION ALL
SELECT 3,'maintenance','maintenance_work_order',(SELECT maintenance_work_order_id FROM maintenance_work_order WHERE work_order_number='WO-2026-0005'),'VERIFY_WORK_ORDER','Verify completed work order','Demo verification task.',@supervisor_emp,'MAINTENANCE_SUPERVISOR','MEDIUM','PENDING','2026-07-27 10:00:00' UNION ALL
SELECT 4,'procurement','procurement_request',(SELECT procurement_request_id FROM procurement_request WHERE request_number='PR-2026-0001'),'APPROVE_PROCUREMENT','Approve procurement request','Demo procurement approval task.',NULL,'APPROVER','HIGH','PENDING','2026-07-26 15:00:00' UNION ALL
SELECT 5,'records','record',(SELECT record_id FROM record WHERE record_number='REC-2026-0006'),'REVIEW_RECORD','Review expiring record','Demo record review task.',@records_emp,'RECORDS_OFFICER','MEDIUM','PENDING','2026-07-28 09:00:00' UNION ALL
SELECT 6,'maintenance','maintenance_work_order',(SELECT maintenance_work_order_id FROM maintenance_work_order WHERE work_order_number='WO-2026-0008'),'CLOSE_WORK_ORDER','Close completed work order','Demo completed task.',@supervisor_emp,'MAINTENANCE_SUPERVISOR','LOW','COMPLETED','2026-07-25 09:00:00'
) v JOIN (SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) n ON n.n=v.n LEFT JOIN role r ON r.role_code=v.role_code
ON DUPLICATE KEY UPDATE status=VALUES(status), due_at=VALUES(due_at), assigned_to_employee_reference_id=VALUES(assigned_to_employee_reference_id);

INSERT INTO notification (recipient_user_id, notification_type, title, message, module_code, entity_type, entity_id, is_read, read_at, created_at)
SELECT u.user_account_id, v.ntype, CONCAT('Demo: ',v.title), v.message, v.module_code, v.entity_type, v.entity_id, v.is_read, CASE WHEN v.is_read THEN DATE_ADD(@demo_now, INTERVAL -1 HOUR) END, DATE_ADD(@demo_now, INTERVAL -v.n HOUR)
FROM (
SELECT 1 n,'gsms-super-admin' username,'FACILITY_REQUEST_ASSIGNED' ntype,'Facility Request Assigned' title,'FR-2026-0001 assigned to technician.' message,'facility_requests' module_code,'facility_request' entity_type,(SELECT facility_request_id FROM facility_request WHERE request_number='FR-2026-0001') entity_id,FALSE is_read UNION ALL
SELECT 2,'gsms-super-admin','SLA_NEAR_DUE','SLA Near Due','FR-2026-0020 is near SLA due time.','facility_requests','facility_request',(SELECT facility_request_id FROM facility_request WHERE request_number='FR-2026-0020'),FALSE UNION ALL
SELECT 3,'gsms-super-admin','SLA_BREACHED','SLA Breached','FR-2026-0006 breached resolution target.','facility_requests','facility_request',(SELECT facility_request_id FROM facility_request WHERE request_number='FR-2026-0006'),FALSE UNION ALL
SELECT 4,'maintenance.supervisor','WORK_ORDER_ASSIGNED','Work Order Assigned','WO-2026-0002 assigned today.','maintenance','maintenance_work_order',(SELECT maintenance_work_order_id FROM maintenance_work_order WHERE work_order_number='WO-2026-0002'),FALSE UNION ALL
SELECT 5,'maintenance.supervisor','WORK_ORDER_OVERDUE','Work Order Overdue','WO-2026-0015 is overdue.','maintenance','maintenance_work_order',(SELECT maintenance_work_order_id FROM maintenance_work_order WHERE work_order_number='WO-2026-0015'),FALSE UNION ALL
SELECT 6,'reservation.officer','RESERVATION_APPROVED','Reservation Approved','RR-2026-0001 approved.','reservations','facility_reservation',(SELECT facility_reservation_id FROM facility_reservation WHERE reservation_number='RR-2026-0001'),TRUE UNION ALL
SELECT 7,'requestor.user','RESERVATION_REMINDER','Reservation Reminder','Reservation starts today.','reservations','facility_reservation',(SELECT facility_reservation_id FROM facility_reservation WHERE reservation_number='RR-2026-0014'),FALSE UNION ALL
SELECT 8,'gsms-super-admin','PROCUREMENT_APPROVAL_REQUIRED','Procurement Approval Required','PR-2026-0001 needs approval.','procurement','procurement_request',(SELECT procurement_request_id FROM procurement_request WHERE request_number='PR-2026-0001'),FALSE UNION ALL
SELECT 9,'gsms-scm-head','SUPPLY_CHAIN_UPDATE','Supply Chain Update','PO synced from SCM.','procurement','procurement_request',(SELECT procurement_request_id FROM procurement_request WHERE request_number='PR-2026-0003'),TRUE UNION ALL
SELECT 10,'records.officer','DOCUMENT_REVIEW_DUE','Document Review Due','Fire safety certificate expires soon.','records','document',(SELECT document_id FROM document WHERE document_number='DOC-2026-0005'),FALSE UNION ALL
SELECT 11,'gsms-super-admin','REPORT_READY','Report Ready','Monthly operations report is ready.','reports','report',1,TRUE UNION ALL
SELECT 12,'facility.manager','AI_RECOMMENDATION_READY','AI Recommendation Ready','AI triage recommendation is pending.','facility_requests','facility_request',(SELECT facility_request_id FROM facility_request WHERE request_number='FR-2026-0002'),FALSE UNION ALL
SELECT 13,'gsms-super-admin','INTEGRATION_SYNC_COMPLETED','Integration Sync Completed','HRIS sync completed.','administration','integration_log',1,TRUE UNION ALL
SELECT 14,'gsms-super-admin','INTEGRATION_FAILED','Integration Failed','SCM validation failed for PR-2026-0011.','procurement','procurement_request',(SELECT procurement_request_id FROM procurement_request WHERE request_number='PR-2026-0011'),FALSE UNION ALL
SELECT 15,'asset.custodian','ASSET_DUE_SOON','Asset Due Soon','Fire extinguisher inspection due soon.','assets','asset',(SELECT asset_id FROM asset WHERE asset_code='AST-2026-0009'),FALSE UNION ALL
SELECT 16,'records.officer','RECORD_REVIEW_DUE','Record Review Due','REC-2026-0006 needs review.','records','record',(SELECT record_id FROM record WHERE record_number='REC-2026-0006'),FALSE UNION ALL
SELECT 17,'gsms-super-admin','VISITOR_CHECKED_IN','Visitor Checked In','Visitor checked in for meeting.','visitors','visit',(SELECT visit_id FROM visit WHERE visit_number='VIS-2026-0004'),TRUE UNION ALL
SELECT 18,'facility.manager','REQUEST_SUBMITTED','Request Submitted','New facility request submitted.','facility_requests','facility_request',(SELECT facility_request_id FROM facility_request WHERE request_number='FR-2026-0015'),FALSE UNION ALL
SELECT 19,'gsms-super-admin','CONTRACT_EXPIRING','Contract Expiring','Lease agreement expires soon.','contracts','contract',(SELECT contract_id FROM contract WHERE contract_number='CON-2026-0003'),FALSE UNION ALL
SELECT 20,'gsms-super-admin','SYSTEM_NOTICE','Integration Sync Completed','BI dashboard extract completed.','administration','integration_log',5,TRUE
) v JOIN user_account u ON u.username=v.username;

INSERT INTO activity_event (module_code, entity_type, entity_id, event_type, event_description, actor_user_id, event_status, visibility_level, occurred_at)
SELECT v.module_code, v.entity_type, v.entity_id, v.event_type, CONCAT('Demo: ',v.event_description), @fam_user, v.event_status, 'INTERNAL', DATE_ADD(@demo_now, INTERVAL -v.n HOUR)
FROM (
SELECT 1 n,'facility_requests' module_code,'facility_request' entity_type,(SELECT facility_request_id FROM facility_request WHERE request_number='FR-2026-0001') entity_id,'REQUEST_ASSIGNED' event_type,'request assigned to technician' event_description,'SUCCESS' event_status UNION ALL
SELECT 2,'facility_requests','facility_request',(SELECT facility_request_id FROM facility_request WHERE request_number='FR-2026-0003'),'REQUEST_SUBMITTED','request submitted','SUCCESS' UNION ALL
SELECT 3,'maintenance','maintenance_work_order',(SELECT maintenance_work_order_id FROM maintenance_work_order WHERE work_order_number='WO-2026-0008'),'WORK_ORDER_COMPLETED','work order completed','SUCCESS' UNION ALL
SELECT 4,'assets','asset',(SELECT asset_id FROM asset WHERE asset_code='AST-2026-0022'),'ASSET_TRANSFERRED','asset flagged for disposal','SUCCESS' UNION ALL
SELECT 5,'reservations','facility_reservation',(SELECT facility_reservation_id FROM facility_reservation WHERE reservation_number='RR-2026-0001'),'RESERVATION_APPROVED','reservation approved','SUCCESS' UNION ALL
SELECT 6,'procurement','procurement_request',(SELECT procurement_request_id FROM procurement_request WHERE request_number='PR-2026-0003'),'PROCUREMENT_FORWARDED','procurement forwarded to supply chain','SUCCESS' UNION ALL
SELECT 7,'records','document',(SELECT document_id FROM document WHERE document_number='DOC-2026-0001'),'DOCUMENT_REVISED','document revised to version 2','SUCCESS' UNION ALL
SELECT 8,'records','record',(SELECT record_id FROM record WHERE record_number='REC-2026-0007'),'RECORD_ARCHIVED','record archived','SUCCESS' UNION ALL
SELECT 9,'facility_requests','facility_request',(SELECT facility_request_id FROM facility_request WHERE request_number='FR-2026-0007'),'AI_RECOMMENDATION_REVIEWED','AI recommendation reviewed','SUCCESS' UNION ALL
SELECT 10,'maintenance','maintenance_work_order',(SELECT maintenance_work_order_id FROM maintenance_work_order WHERE work_order_number='WO-2026-0005'),'WORK_ORDER_VERIFIED','work order verified','SUCCESS'
) v;

-- Add more dashboard activity coverage across the week.
INSERT INTO activity_event (module_code, entity_type, entity_id, event_type, event_description, actor_user_id, event_status, visibility_level, occurred_at)
SELECT 'dashboard','demo_activity',n.n,'DEMO_EVENT',CONCAT('Demo: dashboard activity event ',n.n),@admin_user,'SUCCESS','INTERNAL',DATE_ADD(@demo_now, INTERVAL -(10+n.n) HOUR)
FROM (SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15 UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19 UNION ALL SELECT 20) n;

-- 14. Audit Logs
INSERT INTO audit_log (actor_user_id, action, module_code, entity_type, entity_id, old_values_json, new_values_json, ip_address, user_agent, created_at)
SELECT @admin_user, v.action, CONCAT('demo_',v.module_code), v.entity_type, v.entity_id, v.old_json, v.new_json, '192.0.2.10', 'DemoSeeder/1.0', DATE_ADD(@demo_now, INTERVAL -v.n HOUR)
FROM (
SELECT 1 n,'LOGIN_SUCCESS' action,'security' module_code,'user_account' entity_type,@admin_user entity_id,NULL old_json,JSON_OBJECT('status','success') new_json UNION ALL
SELECT 2,'LOGIN_FAILED','security','user_account',@admin_user,NULL,JSON_OBJECT('status','failed','reason','demo invalid password') UNION ALL
SELECT 3,'ACCESS_DENIED','security','permission',NULL,NULL,JSON_OBJECT('permission','administration.delete','result','denied') UNION ALL
SELECT 4,'PROFILE_UPDATED','profile','employee_reference',@requestor_emp,JSON_OBJECT('contact','old demo'),JSON_OBJECT('contact','new demo') UNION ALL
SELECT 5,'ROLE_PERMISSION_REVIEW','administration','role',NULL,NULL,JSON_OBJECT('role','AUDITOR','result','reviewed') UNION ALL
SELECT 6,'AI_CONNECTION_TEST','administration','external_system',NULL,NULL,JSON_OBJECT('result','success') UNION ALL
SELECT 7,'INTEGRATION_SYNC','integration','external_system',NULL,NULL,JSON_OBJECT('system','HRIS','result','success') UNION ALL
SELECT 8,'APPROVAL_ACTION','workflow','approval_request',NULL,JSON_OBJECT('status','PENDING'),JSON_OBJECT('status','APPROVED') UNION ALL
SELECT 9,'REQUEST_UPDATED','facility_requests','facility_request',(SELECT facility_request_id FROM facility_request WHERE request_number='FR-2026-0001'),JSON_OBJECT('status','ASSIGNED'),JSON_OBJECT('status','IN_PROGRESS') UNION ALL
SELECT 10,'WORK_ORDER_COMPLETED','maintenance','maintenance_work_order',(SELECT maintenance_work_order_id FROM maintenance_work_order WHERE work_order_number='WO-2026-0008'),JSON_OBJECT('status','IN_PROGRESS'),JSON_OBJECT('status','COMPLETED')
) v;

INSERT INTO audit_log (actor_user_id, action, module_code, entity_type, entity_id, old_values_json, new_values_json, ip_address, user_agent, created_at)
SELECT @fam_user, 'DEMO_AUDIT_EVENT', 'demo_operations', 'demo_entity', n.n, NULL, JSON_OBJECT('demoSequence',n.n,'secret','not stored'), '192.0.2.11', 'DemoSeeder/1.0', DATE_ADD(@demo_now, INTERVAL -(10+n.n) HOUR)
FROM (SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10) n;

-- 15. Integration Logs / Outbox
-- Schema limitation: there is no integration_log table; integration history is represented through integration_outbox, activity_event, notifications, and audit_log.
INSERT INTO integration_outbox (event_uuid, destination_system_code, source_module, event_type, entity_type, entity_id, payload_json, delivery_status, retry_count, next_retry_at, published_at, last_error_message, created_at)
VALUES
('00000000-2026-0000-0000-000000000001','HRIS','administration','SYNC_COMPLETED','external_system',1,JSON_OBJECT('processed',14,'success',14,'failed',0,'summary','Demo HRIS sync completed.'),'PUBLISHED',0,NULL,'2026-07-26 08:00:00',NULL,'2026-07-26 08:00:00'),
('00000000-2026-0000-0000-000000000002','FMS','procurement','BUDGET_SYNC_DEGRADED','budget_reference',1,JSON_OBJECT('processed',4,'success',3,'failed',1,'summary','Demo Finance sync degraded; one budget pending review.'),'PUBLISHED',1,NULL,'2026-07-26 08:15:00','One budget row returned a warning.','2026-07-26 08:15:00'),
('00000000-2026-0000-0000-000000000003','SCM','procurement','PROCUREMENT_FAILED','procurement_request',(SELECT procurement_request_id FROM procurement_request WHERE request_number='PR-2026-0011'),JSON_OBJECT('processed',1,'success',0,'failed',1,'summary','Demo SCM validation failed for item mapping.'),'FAILED',2,'2026-07-26 11:00:00',NULL,'Missing external item mapping.','2026-07-26 09:00:00'),
('00000000-2026-0000-0000-000000000004','FLEET','facility_requests','TRANSPORT_SYNC_RUNNING','facility_request',(SELECT facility_request_id FROM facility_request WHERE request_number='FR-2026-0019'),JSON_OBJECT('processed',1,'success',0,'failed',0,'summary','Demo Fleet sync is running.'),'PENDING',0,'2026-07-26 10:30:00',NULL,NULL,'2026-07-26 10:00:00'),
('00000000-2026-0000-0000-000000000005','BI','reports','DASHBOARD_EXPORT_COMPLETED','dashboard',1,JSON_OBJECT('processed',120,'success',120,'failed',0,'summary','Demo BI dashboard extract completed.'),'PUBLISHED',0,NULL,'2026-07-26 09:45:00',NULL,'2026-07-26 09:45:00')
ON DUPLICATE KEY UPDATE delivery_status=VALUES(delivery_status), retry_count=VALUES(retry_count), payload_json=VALUES(payload_json), last_error_message=VALUES(last_error_message);

-- Approval requests and steps for selected demo records.
INSERT INTO approval_request (module_code, entity_type, entity_id, requested_by_user_id, approval_status, current_step_number, requested_at, completed_at, remarks)
SELECT module_code, entity_type, entity_id, @requestor_user, approval_status, 1, DATE_ADD(@demo_now, INTERVAL -6 HOUR), CASE WHEN approval_status='APPROVED' THEN DATE_ADD(@demo_now, INTERVAL -4 HOUR) END, 'Demo approval route.'
FROM (
SELECT 'facility_requests' module_code,'DEMO_facility_request' entity_type,(SELECT facility_request_id FROM facility_request WHERE request_number='FR-2026-0007') entity_id,'PENDING' approval_status UNION ALL
SELECT 'reservations','DEMO_facility_reservation',(SELECT facility_reservation_id FROM facility_reservation WHERE reservation_number='RR-2026-0004'),'PENDING' UNION ALL
SELECT 'procurement','DEMO_procurement_request',(SELECT procurement_request_id FROM procurement_request WHERE request_number='PR-2026-0002'),'APPROVED' UNION ALL
SELECT 'procurement','DEMO_procurement_request',(SELECT procurement_request_id FROM procurement_request WHERE request_number='PR-2026-0001'),'PENDING'
) v;

INSERT IGNORE INTO approval_step (approval_request_id, step_number, approver_employee_reference_id, approver_role_id, decision, decision_at, comments)
SELECT ar.approval_request_id, 1, @manager_emp, r.role_id, CASE WHEN ar.approval_status='APPROVED' THEN 'APPROVED' ELSE 'PENDING' END, CASE WHEN ar.approval_status='APPROVED' THEN DATE_ADD(@demo_now, INTERVAL -4 HOUR) END, 'Demo approval step.'
FROM approval_request ar LEFT JOIN role r ON r.role_code='APPROVER'
WHERE ar.entity_type LIKE 'DEMO_%' AND ar.requested_at BETWEEN '2026-01-01' AND '2026-12-31';

COMMIT;
