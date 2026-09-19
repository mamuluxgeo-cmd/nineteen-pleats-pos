-- Additive, idempotent indexes. Apply once off-peak after a backup.
-- Never executed by web requests or by the FTP deployment.
SET SESSION lock_wait_timeout=3;

SET @pos_has_index=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='orders' AND index_name='idx_orders_day_status_table');
SET @pos_index_sql=IF(@pos_has_index=0,'ALTER TABLE orders ADD INDEX idx_orders_day_status_table (business_day_id,status,table_id,id), ALGORITHM=INPLACE, LOCK=NONE','SELECT 1');
PREPARE pos_index_stmt FROM @pos_index_sql;
EXECUTE pos_index_stmt;
DEALLOCATE PREPARE pos_index_stmt;

SET @pos_has_index=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='cash_movements' AND index_name='idx_cash_type_created');
SET @pos_index_sql=IF(@pos_has_index=0,'ALTER TABLE cash_movements ADD INDEX idx_cash_type_created (type,created_at), ALGORITHM=INPLACE, LOCK=NONE','SELECT 1');
PREPARE pos_index_stmt FROM @pos_index_sql;
EXECUTE pos_index_stmt;
DEALLOCATE PREPARE pos_index_stmt;
