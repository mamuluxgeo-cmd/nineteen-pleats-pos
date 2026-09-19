-- Reconcile older installations with indexes already present in schema.sql.
-- Take a backup first and verify existing index definitions before applying.
-- Additive and idempotent; never executed by web requests or FTP deployment.
-- Run with the intended POS database selected for the entire script.
SET SESSION lock_wait_timeout=3;

SET @pos_has_index=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='orders' AND index_name='idx_orders_day_table_status');
SET @pos_index_sql=IF(@pos_has_index=0,'ALTER TABLE orders ADD INDEX idx_orders_day_table_status (business_day_id,table_id,status,id), ALGORITHM=INPLACE, LOCK=NONE','SELECT 1');
PREPARE pos_index_stmt FROM @pos_index_sql;
EXECUTE pos_index_stmt;
DEALLOCATE PREPARE pos_index_stmt;

SET @pos_has_index=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='orders' AND index_name='idx_orders_status_closed');
SET @pos_index_sql=IF(@pos_has_index=0,'ALTER TABLE orders ADD INDEX idx_orders_status_closed (status,closed_at), ALGORITHM=INPLACE, LOCK=NONE','SELECT 1');
PREPARE pos_index_stmt FROM @pos_index_sql;
EXECUTE pos_index_stmt;
DEALLOCATE PREPARE pos_index_stmt;

SET @pos_has_index=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='order_items' AND index_name='idx_items_order_active_sent');
SET @pos_index_sql=IF(@pos_has_index=0,'ALTER TABLE order_items ADD INDEX idx_items_order_active_sent (order_id,is_cancelled,sent_at), ALGORITHM=INPLACE, LOCK=NONE','SELECT 1');
PREPARE pos_index_stmt FROM @pos_index_sql;
EXECUTE pos_index_stmt;
DEALLOCATE PREPARE pos_index_stmt;
