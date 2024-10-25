SELECT 
    avatar_name AS Name,
    region_name AS Region,
    last_seen AS Time,
    avatar_key AS UUID
FROM avatar_visits
WHERE LOWER(avatar_name) LIKE '%bonniebelle%'
ORDER BY last_seen DESC;
