SELECT 
    avatar_name AS Name,
    region_name AS Region,
    last_seen AS Time,
    avatar_key AS UUID
FROM avatar_visits
ORDER BY last_seen DESC
LIMIT 44;
