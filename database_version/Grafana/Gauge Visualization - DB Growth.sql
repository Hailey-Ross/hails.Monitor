SELECT 
  (SELECT COUNT(*) FROM avatar_visits) AS "visitors",
  (SELECT COUNT(*) FROM change_log WHERE table_name = 'avatar_visits') AS "change logs";
