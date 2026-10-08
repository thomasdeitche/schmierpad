-- SchmierPAD: persönliche Aufgabenliste pro Arbeitsplatz (Schlüssel = MAC-Adresse des Clients)
-- Zeitstempel als Unix-Epoche (UTC-unabhängig, keine Zeitzonen-Umrechnung in der DB).

CREATE TABLE IF NOT EXISTS tasks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_mac CHAR(17) NOT NULL,
  title VARCHAR(500) NOT NULL,
  priority TINYINT UNSIGNED NOT NULL DEFAULT 6,
  done TINYINT(1) NOT NULL DEFAULT 0,
  created_ts INT UNSIGNED NOT NULL,
  done_ts INT UNSIGNED NULL DEFAULT NULL,
  INDEX idx_tasks_owner (owner_mac)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
