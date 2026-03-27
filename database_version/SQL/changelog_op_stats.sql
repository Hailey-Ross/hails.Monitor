SELECT table_name, operation, COUNT(*) AS rows_left
FROM change_log
GROUP BY table_name, operation
ORDER BY rows_left DESC;
