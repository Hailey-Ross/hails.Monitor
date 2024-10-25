SELECT 
  date_value,
  SUM(avatar_visits) AS visitors,
  SUM(change_log) AS "change log"
FROM (
    -- Count records in avatar_visits grouped by date
    SELECT 
      DATE(v.last_seen) AS date_value,          
      COUNT(*) AS avatar_visits,               
      0 AS change_log
    FROM 
      avatar_visits v
    GROUP BY 
      date_value

    UNION ALL

    -- Count records in change_log grouped by date
    SELECT 
      DATE(STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(old_data, '$.last_seen')), '%Y-%m-%d %H:%i:%s')) AS date_value,  
      0 AS avatar_visits,                    
      COUNT(*) AS change_log                 
    FROM 
      change_log c
    WHERE 
      c.table_name = 'avatar_visits'
    GROUP BY 
      date_value
) combined
GROUP BY 
  date_value
ORDER BY 
  date_value;
