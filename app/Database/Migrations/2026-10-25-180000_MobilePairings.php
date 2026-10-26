<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class MobilePairings extends Migration
{
    public function up()
    {
        $this->db->query("CREATE TABLE cs_mobile_pairings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_hash CHAR(64) NOT NULL UNIQUE,
            code_hash CHAR(64) NOT NULL UNIQUE,
            device_name VARCHAR(60) NOT NULL,
            user_id INT UNSIGNED NULL,
            state VARCHAR(16) NOT NULL DEFAULT 'pending',
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            KEY ix_pairing_expiry(expires_at),
            FOREIGN KEY(user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down() { $this->forge->dropTable('cs_mobile_pairings'); }
}
