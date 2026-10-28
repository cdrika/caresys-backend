<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class InAppNotifications extends Migration
{
    public function up(){ $this->db->query("CREATE TABLE cs_notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,ref CHAR(32) NOT NULL UNIQUE,version INT UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        recipient_id INT UNSIGNED NOT NULL,agency_id BIGINT UNSIGNED NOT NULL,event VARCHAR(60) NOT NULL,event_key CHAR(64) NOT NULL,
        title VARCHAR(180) NOT NULL,body TEXT NOT NULL,link VARCHAR(180) NOT NULL,read_at DATETIME NULL,
        UNIQUE(recipient_id,event_key),INDEX(recipient_id,read_at,id),FOREIGN KEY(recipient_id) REFERENCES users(id),FOREIGN KEY(agency_id) REFERENCES cs_agencies(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); }
    public function down(){throw new \RuntimeException('Notification history requires reviewed recovery.');}
}
