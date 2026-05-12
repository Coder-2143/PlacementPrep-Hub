-- =========================================================
--  PlacementPrep Hub — MySQL Schema
--  Run: mysql -u root -p placementprep < schema.sql
-- =========================================================

CREATE DATABASE IF NOT EXISTS placementprep
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE placementprep;

-- ─── users ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  email       VARCHAR(254)  NOT NULL,
  fullName    VARCHAR(200)  NOT NULL,
  password    VARCHAR(255)  NOT NULL,
  bio         TEXT,
  expertise   VARCHAR(100),
  role        ENUM('user','admin') NOT NULL DEFAULT 'user',
  createdAt   BIGINT        NOT NULL DEFAULT 0,
  PRIMARY KEY (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── posts ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS posts (
  id          VARCHAR(64)   NOT NULL,
  title       VARCHAR(500)  NOT NULL,
  category    VARCHAR(100)  NOT NULL,
  content     LONGTEXT      NOT NULL,
  excerpt     TEXT,
  coverImage  TEXT,
  videoUrl    TEXT,
  youtubeUrl  TEXT,
  author      VARCHAR(200)  NOT NULL,
  authorEmail VARCHAR(254)  NOT NULL,
  authorBio   TEXT,
  expertise   VARCHAR(100),
  date        VARCHAR(50),
  status      ENUM('published','draft') NOT NULL DEFAULT 'published',
  createdAt   BIGINT        NOT NULL DEFAULT 0,
  likes       INT           NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_posts_authorEmail (authorEmail),
  KEY idx_posts_status (status),
  KEY idx_posts_createdAt (createdAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── comments ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS comments (
  id      VARCHAR(64)   NOT NULL,
  postId  VARCHAR(64)   NOT NULL,
  name    VARCHAR(200)  NOT NULL,
  email   VARCHAR(254),
  comment TEXT          NOT NULL,
  date    VARCHAR(50),
  PRIMARY KEY (id),
  KEY idx_comments_postId (postId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── replies ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS replies (
  id        VARCHAR(64)   NOT NULL,
  commentId VARCHAR(64)   NOT NULL,
  name      VARCHAR(200)  NOT NULL,
  text      TEXT          NOT NULL,
  date      VARCHAR(50),
  PRIMARY KEY (id),
  KEY idx_replies_commentId (commentId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── likes ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS likes (
  postId  VARCHAR(64)   NOT NULL,
  actorId VARCHAR(300)  NOT NULL,
  PRIMARY KEY (postId, actorId),
  KEY idx_likes_postId (postId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── saved_posts ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS saved_posts (
  userKey VARCHAR(300)  NOT NULL,
  postId  VARCHAR(64)   NOT NULL,
  PRIMARY KEY (userKey, postId),
  KEY idx_saved_userKey (userKey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── reports ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reports (
  id      VARCHAR(64)   NOT NULL,
  postId  VARCHAR(64)   NOT NULL,
  reason  VARCHAR(200)  NOT NULL,
  note    TEXT,
  date    VARCHAR(50),
  PRIMARY KEY (id),
  KEY idx_reports_postId (postId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── contact_messages ────────────────────────────────────
CREATE TABLE IF NOT EXISTS contact_messages (
  id      VARCHAR(64)   NOT NULL,
  name    VARCHAR(200)  NOT NULL,
  email   VARCHAR(254)  NOT NULL,
  message TEXT          NOT NULL,
  date    VARCHAR(50),
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── analytics ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS analytics (
  postId  VARCHAR(64)  NOT NULL,
  views   INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (postId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── drafts ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS drafts (
  userEmail   VARCHAR(254)  NOT NULL,
  title       VARCHAR(500),
  category    VARCHAR(100),
  content     LONGTEXT,
  coverImage  TEXT,
  videoUrl    TEXT,
  youtubeUrl  TEXT,
  status      VARCHAR(20)   DEFAULT 'draft',
  lastUpdated VARCHAR(50),
  PRIMARY KEY (userEmail)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── followers ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS followers (
  authorEmail   VARCHAR(254)  NOT NULL,
  followerEmail VARCHAR(254)  NOT NULL,
  PRIMARY KEY (authorEmail, followerEmail),
  KEY idx_followers_authorEmail (authorEmail)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── user_kv (generic key-value per user) ────────────────
CREATE TABLE IF NOT EXISTS user_kv (
  user_email VARCHAR(254) NOT NULL,
  kv_key     VARCHAR(120) NOT NULL,
  kv_val     LONGTEXT     NOT NULL,
  PRIMARY KEY (user_email, kv_key),
  KEY idx_user_kv_email (user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Seed default admin account ──────────────────────────
INSERT IGNORE INTO users (email, fullName, password, bio, expertise, role, createdAt)
VALUES (
  'admin@placementprep.local',
  'Admin',
  '$2y$10$BCH5QZfVDCNdFJ3ZsbKIS.GDX7bQG3tjlySee92WuNk922HBg6MEq',
  'Platform administrator',
  'Operations',
  'admin',
  UNIX_TIMESTAMP() * 1000
);
