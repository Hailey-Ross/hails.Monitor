SELECT
    x.avatar_key_gc,
    v.avatar_name,
    x.region_name_gc,
    x.stranded_rows,
    x.first_change_time,
    x.last_change_time
FROM (
    SELECT
        avatar_key_gc,
        region_name_gc,
        COUNT(*) AS stranded_rows,
        MIN(change_time) AS first_change_time,
        MAX(change_time) AS last_change_time
    FROM change_log
    WHERE table_name = 'avatar_visits'
      AND operation IN ('INSERT', 'UPDATE', 'Insert')
      AND id <= (
          SELECT last_processed_id
          FROM compression_state
          WHERE job_name = 'change_log_session_compress'
      )
      AND change_time < (UTC_TIMESTAMP() - INTERVAL 600 SECOND)
      AND avatar_key_gc IS NOT NULL
      AND avatar_key_gc <> ''
      AND region_name_gc IS NOT NULL
      AND region_name_gc <> ''
    GROUP BY avatar_key_gc, region_name_gc
) x
LEFT JOIN avatar_visits v
    ON v.avatar_key = x.avatar_key_gc
ORDER BY x.stranded_rows DESC;
