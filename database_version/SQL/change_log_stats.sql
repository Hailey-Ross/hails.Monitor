SELECT
    COUNT(*) AS total_rows,
    SUM(table_name = 'avatar_visits') AS avatar_visits_rows,
    SUM(table_name = 'avatar_visits' AND operation IN ('INSERT','UPDATE')) AS avatar_visit_insert_update_rows,
    SUM(table_name = 'avatar_visits' AND operation = 'DELETE') AS avatar_visit_delete_rows
FROM change_log;
