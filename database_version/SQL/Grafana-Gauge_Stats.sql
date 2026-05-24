SELECT
    COUNT(*) AS "Sessions",
    SUM(heartbeat_count) AS "Raw Rows",
    ROUND(SUM(heartbeat_count) / COUNT(*), 2) AS "Compression Ratio"
FROM avatar_sessions;
