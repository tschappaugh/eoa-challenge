-- Runs once, when the MySQL data volume is first created.
CREATE TABLE users (
  id CHAR(36) NOT NULL PRIMARY KEY,
  timezone VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- api_id is NULL while a title is reserved and the upstream add is in flight.
-- title_normalized is trimmed, whitespace-collapsed, and lowercased in PHP;
-- the binary collation makes the unique index an exact match on that value.
CREATE TABLE games (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  api_id INT UNSIGNED NULL UNIQUE,
  title VARCHAR(100) NOT NULL,
  title_normalized VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL UNIQUE,
  created_by CHAR(36) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_games_user FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE votes (
  user_id CHAR(36) NOT NULL,
  game_id INT UNSIGNED NOT NULL,
  cast_local_date DATE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, game_id),
  KEY idx_votes_game (game_id),
  CONSTRAINT fk_votes_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_votes_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per user per local day enforces "one vote OR one add per day".
CREATE TABLE daily_actions (
  user_id CHAR(36) NOT NULL,
  local_date DATE NOT NULL,
  type ENUM('vote', 'add') NOT NULL,
  ref_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, local_date),
  CONSTRAINT fk_actions_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
