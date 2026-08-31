-- =====================================================================
--  オーナー管理画面 追加スキーマ（カゴヤDB）
--  既存の users / kiten_girl は、インデックス追加を除いて変更しません
-- =====================================================================

-- ---------------------------------------------------------------------
-- 0. 既存テーブルへのインデックス追加
--    カラムは増やさないので、既存プログラムの SELECT / INSERT に影響しません
--    kiten_girl は現在 PRIMARY KEY のみで、店舗別の集計が毎回フルスキャンになります
-- ---------------------------------------------------------------------
ALTER TABLE `kiten_girl` ADD KEY `idx_shopr` (`shopr_id`);
ALTER TABLE `kiten_girl` ADD KEY `idx_shopr_status` (`shopr_id`, `kitengirl_status`);


-- ---------------------------------------------------------------------
-- 1. 運営側のログインアカウント（契約店舗の users とは必ず分ける）
-- ---------------------------------------------------------------------
CREATE TABLE `admin_users` (
  `admin_id`   int(11)      NOT NULL AUTO_INCREMENT,
  `login_id`   varchar(60)  NOT NULL,
  `name`       varchar(100) NOT NULL,
  `password`   varchar(255) NOT NULL COMMENT 'password_hash()',
  `role`       tinyint(1)   NOT NULL DEFAULT 2 COMMENT '1=オーナー 2=スタッフ 3=閲覧のみ',
  `is_active`  tinyint(1)   NOT NULL DEFAULT 1,
  `last_login` datetime     DEFAULT NULL,
  `created_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`admin_id`),
  UNIQUE KEY `uq_login_id` (`login_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------
-- 2. 実行ログ（VPSのプログラムから1処理ごとに INSERT）
--    これが無いと稼働監視は表示するものがありません
-- ---------------------------------------------------------------------
CREATE TABLE `exec_log` (
  `id`           bigint(20)  NOT NULL AUTO_INCREMENT,
  `user_id`      int(11)     NOT NULL COMMENT 'users.user_id',
  `kitengirl_id` int(11)     DEFAULT NULL,
  `job_type`     varchar(40) NOT NULL COMMENT '処理種別',
  `result`       tinyint(1)  NOT NULL COMMENT '0=失敗 1=成功 2=スキップ',
  `message`      text,
  `exeserver`    int(11)     DEFAULT NULL,
  `duration_ms`  int(11)     DEFAULT NULL,
  `started_at`   datetime    NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_time`   (`user_id`, `started_at`),
  KEY `idx_time_result` (`started_at`, `result`),
  KEY `idx_girl_time`   (`kitengirl_id`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------
-- 3. 店舗別の最新稼働サマリー（一覧表示はこの1行だけを読む）
-- ---------------------------------------------------------------------
CREATE TABLE `exec_status` (
  `user_id`         int(11)    NOT NULL,
  `last_exec_at`    datetime   DEFAULT NULL,
  `last_success_at` datetime   DEFAULT NULL,
  `last_result`     tinyint(1) DEFAULT NULL,
  `last_message`    text,
  `fail_streak`     int(11)    NOT NULL DEFAULT 0,
  `updated_at`      datetime   NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  KEY `idx_last_success` (`last_success_at`),
  KEY `idx_fail_streak`  (`fail_streak`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------
-- 4. 運営側の操作履歴
-- ---------------------------------------------------------------------
CREATE TABLE `admin_audit_log` (
  `id`          bigint(20)  NOT NULL AUTO_INCREMENT,
  `admin_id`    int(11)     NOT NULL,
  `action`      varchar(60) NOT NULL,
  `target_type` varchar(30) NOT NULL DEFAULT '',
  `target_id`   int(11)     DEFAULT NULL,
  `detail`      text,
  `ip`          varchar(45) NOT NULL DEFAULT '',
  `created_at`  datetime    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_time` (`admin_id`, `created_at`),
  KEY `idx_target`     (`target_type`, `target_id`),
  KEY `idx_created`    (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
