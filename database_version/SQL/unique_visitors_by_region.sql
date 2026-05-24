SELECT
  region_name AS Region,
  COUNT(*) AS "Total Visitors",
  COUNT(DISTINCT avatar_key) AS "Unique Visitors"
FROM avatar_sessions
GROUP BY region_name
ORDER BY COUNT(DISTINCT avatar_key) DESC;
