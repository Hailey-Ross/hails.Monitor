SELECT 
    a.date_value,
    (SELECT SUM(b.row_count) 
     FROM (
         SELECT DATE(last_seen) AS date_value, COUNT(*) AS row_count
         FROM avatar_visits
         GROUP BY date_value
     ) b
     WHERE b.date_value <= a.date_value) AS visitors
FROM (
    SELECT 
        DATE(last_seen) AS date_value,  
        COUNT(*) AS row_count          
    FROM 
        avatar_visits
    GROUP BY 
        date_value
) a
ORDER BY 
    a.date_value;
