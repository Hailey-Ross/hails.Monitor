SELECT
    v.avatar_name AS Name,
    v.region_name AS Region,
    v.last_seen   AS time,
    v.avatar_key  AS UUID
FROM avatar_visits v
WHERE LOWER(v.avatar_name) LIKE '%bonniebelle%'
ORDER BY v.last_seen DESC;
