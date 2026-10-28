-- Loop 3 tables (MySQL 5.7+ / MariaDB 10.3+). setup.php imports this for you.
-- To do it by hand: phpMyAdmin > your database > Import.
-- Every table starts with loop_, so Loop can share a database with other apps.
-- On the FHD Portal, Loop also READS sch_resources / sch_teams / sch_sbus / res_staff / res_departments.
-- All times are UTC epoch milliseconds (BIGINT).

-- One row per person. id is 'm<n>' for people added in Loop, 'g<n>' for guests, 'x...' for connected apps,
-- and on the FHD Portal 's<sch_resources.id>' / 'r<res_staff.id>', kept in step with Resources automatically.
CREATE TABLE IF NOT EXISTS loop_people (
  id            VARCHAR(20)  NOT NULL PRIMARY KEY,
  source        CHAR(1)      NOT NULL,              -- m = member, g = guest, x = app; s / r = FHD Resources
  source_id     INT          NOT NULL,
  email         VARCHAR(190) NULL,
  name          VARCHAR(120) NOT NULL,
  title         VARCHAR(120) NOT NULL DEFAULT '',   -- role / designation
  grp           VARCHAR(120) NOT NULL DEFAULT '',   -- SBU or department
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  last_seen     BIGINT       NULL,
  now_week      VARCHAR(10)  NOT NULL DEFAULT '',
  now_used      INT          NOT NULL DEFAULT 0,
  notify_email  TINYINT(1)   NOT NULL DEFAULT 1,
  last_notified BIGINT       NOT NULL DEFAULT 0,
  role          VARCHAR(10)  NOT NULL DEFAULT '',   -- member | director | admin
  UNIQUE KEY (source, source_id),
  INDEX (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loop_meta (
  k VARCHAR(40)  NOT NULL PRIMARY KEY,
  v VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO loop_meta (k, v) VALUES ('schema', '4');

CREATE TABLE IF NOT EXISTS loop_otp (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(190) NOT NULL,
  code_hash  CHAR(64)     NOT NULL,
  created_at BIGINT       NOT NULL,
  expires_at BIGINT       NOT NULL,
  attempts   INT          NOT NULL DEFAULT 0,
  INDEX (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loop_sessions (
  token_hash CHAR(64)    NOT NULL PRIMARY KEY,
  person_id  VARCHAR(20) NOT NULL,
  created_at BIGINT      NOT NULL,
  expires_at BIGINT      NOT NULL,
  INDEX (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loop_spaces (
  id         VARCHAR(20) NOT NULL PRIMARY KEY,
  name       VARCHAR(80) NOT NULL,
  kind       VARCHAR(20) NOT NULL DEFAULT 'project',
  created_by VARCHAR(20) NOT NULL,
  created_at BIGINT      NOT NULL,
  archived   TINYINT(1)  NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loop_items (
  id             VARCHAR(20)  NOT NULL PRIMARY KEY,
  type           VARCHAR(10)  NOT NULL,              -- tell | ask | decide | do | share
  title          VARCHAR(200) NOT NULL,
  body           TEXT         NULL,
  author_id      VARCHAR(20)  NOT NULL,
  space_id       VARCHAR(20)  NULL,
  due            DATE         NULL,
  urgent         TINYINT(1)   NOT NULL DEFAULT 0,
  deliver_at     BIGINT       NOT NULL,
  created_at     BIGINT       NOT NULL,
  status         VARCHAR(10)  NOT NULL DEFAULT 'open', -- open | closed | withdrawn
  outcome_text   TEXT         NULL,
  outcome_note   TEXT         NULL,
  outcome_option VARCHAR(20)  NULL,
  outcome_by     VARCHAR(20)  NULL,
  outcome_at     BIGINT       NULL,
  closed_at      BIGINT       NULL,
  nudged_at      BIGINT       NULL,
  edited_at      BIGINT       NULL,
  INDEX (author_id), INDEX (space_id), INDEX (status), INDEX (deliver_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loop_parts (
  loop_id   VARCHAR(20) NOT NULL,
  person_id VARCHAR(20) NOT NULL,
  role      VARCHAR(12) NOT NULL,                    -- recipient | responder | decider | owner
  seen_at   BIGINT      NULL,
  PRIMARY KEY (loop_id, person_id),
  INDEX (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loop_options (
  id      VARCHAR(20)  NOT NULL PRIMARY KEY,
  loop_id VARCHAR(20)  NOT NULL,
  label   VARCHAR(140) NOT NULL,
  pos     INT          NOT NULL,
  INDEX (loop_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loop_notes (
  id         VARCHAR(20) NOT NULL PRIMARY KEY,
  loop_id    VARCHAR(20) NOT NULL,
  person_id  VARCHAR(20) NOT NULL,
  text       TEXT        NOT NULL,
  kind       VARCHAR(10) NOT NULL DEFAULT 'note',   -- note | answer | edit
  created_at BIGINT      NOT NULL,
  edited_at  BIGINT      NULL,
  INDEX (loop_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loop_ai_calls (
  id        BIGINT AUTO_INCREMENT PRIMARY KEY,
  person_id VARCHAR(20) NOT NULL,
  at        BIGINT      NOT NULL,
  INDEX (person_id, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Talk, membership, AI suggestions, guests and push (v2.0)
CREATE TABLE IF NOT EXISTS loop_space_members (space_id VARCHAR(20) NOT NULL, person_id VARCHAR(20) NOT NULL, joined_at BIGINT NOT NULL, PRIMARY KEY (space_id, person_id), INDEX (person_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS loop_talk (id VARCHAR(20) NOT NULL PRIMARY KEY, thread VARCHAR(60) NOT NULL, person_id VARCHAR(20) NOT NULL, text TEXT NOT NULL, kind VARCHAR(10) NOT NULL DEFAULT 'msg', mentions VARCHAR(500) NULL, loop_id VARCHAR(20) NULL, created_at BIGINT NOT NULL, edited_at BIGINT NULL, INDEX (thread, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS loop_talk_reads (person_id VARCHAR(20) NOT NULL, thread VARCHAR(60) NOT NULL, read_at BIGINT NOT NULL, PRIMARY KEY (person_id, thread)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS loop_suggestions (id VARCHAR(20) NOT NULL PRIMARY KEY, thread VARCHAR(60) NOT NULL, msg_id VARCHAR(20) NULL, data TEXT NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'open', created_at BIGINT NOT NULL, acted_by VARCHAR(20) NULL, INDEX (thread)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS loop_guest_links (token_hash CHAR(64) NOT NULL PRIMARY KEY, loop_id VARCHAR(20) NOT NULL, person_id VARCHAR(20) NOT NULL, created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL, INDEX (loop_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS loop_push_subs (id BIGINT AUTO_INCREMENT PRIMARY KEY, person_id VARCHAR(20) NOT NULL, endpoint_hash CHAR(64) NOT NULL UNIQUE, endpoint TEXT NOT NULL, p256dh VARCHAR(200) NOT NULL, auth VARCHAR(60) NOT NULL, created_at BIGINT NOT NULL, INDEX (person_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attachments (v2.2). Files live in S3-compatible storage; this table records who sent what, where.
CREATE TABLE IF NOT EXISTS loop_files (id VARCHAR(20) NOT NULL PRIMARY KEY, owner_id VARCHAR(20) NOT NULL, name VARCHAR(200) NOT NULL,
    mime VARCHAR(120) NOT NULL, size BIGINT NOT NULL, obj_key VARCHAR(200) NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'pending',
    ref_kind VARCHAR(10) NULL, ref_id VARCHAR(20) NULL, loop_id VARCHAR(20) NULL, thread VARCHAR(60) NULL, width INT NULL, height INT NULL,
    created_at BIGINT NOT NULL, expires_at BIGINT NULL, kept_by VARCHAR(20) NULL,
    INDEX (ref_kind, ref_id), INDEX (loop_id), INDEX (thread), INDEX (expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO loop_meta (k, v) VALUES ('files_v', '2');
