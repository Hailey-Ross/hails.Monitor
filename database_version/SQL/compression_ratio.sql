SELECT
    COUNT(*) AS sessions,
    SUM(heartbeat_count) AS raw_rows_represented,
    ROUND(SUM(heartbeat_count) / COUNT(*), 2) AS compression_ratio
FROM avatar_sessions;
