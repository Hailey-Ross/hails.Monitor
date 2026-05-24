SELECT
    VARIABLE_NAME AS metric,
    VARIABLE_VALUE AS value
FROM performance_schema.global_status
WHERE VARIABLE_NAME IN (

    'Threads_connected',
    'Max_used_connections',
    'Threads_created',
    'Innodb_buffer_pool_reads',
    'Innodb_buffer_pool_read_requests',
    'Innodb_buffer_pool_pages_free',
    'Innodb_buffer_pool_pages_total',
    'Innodb_row_lock_waits',
    'Innodb_row_lock_time',
    'Innodb_rows_inserted',
    'Innodb_rows_updated',
    'Innodb_rows_deleted'
)
ORDER BY VARIABLE_NAME;
