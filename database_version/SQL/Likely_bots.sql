SELECT
  COALESCE(v.avatar_name, s.avatar_key) AS avatar,
  s.avatar_key,
  COUNT(*) AS total_visits,
  ROUND(AVG(s.duration_seconds), 2) AS avg_visit_seconds,
  ROUND(AVG(s.duration_seconds) / 60, 2) AS avg_visit_minutes
FROM avatar_sessions s
LEFT JOIN avatar_visits v
  ON v.avatar_key = s.avatar_key
WHERE
  $__timeFilter(s.visit_start)
GROUP BY
  s.avatar_key,
  v.avatar_name
HAVING
  COUNT(*) >= 0
  AND AVG(s.duration_seconds) <= 15
  AND total_visits >= 4
LIMIT 44;
