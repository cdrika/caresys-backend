<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class ShiftChanges extends Migration
{
    public function up()
    {
        $this->db->query("CREATE TABLE cs_shift_changes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,ref CHAR(32) NOT NULL UNIQUE,version INT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            agency_id BIGINT UNSIGNED NOT NULL,person_id BIGINT UNSIGNED NOT NULL,created_by INT UNSIGNED NOT NULL,
            payload JSON NOT NULL,conflicts JSON NOT NULL,state VARCHAR(24) NOT NULL DEFAULT 'pending',
            expires_at DATETIME NOT NULL,visit_ref CHAR(32) NULL,
            FOREIGN KEY(agency_id) REFERENCES cs_agencies(id),FOREIGN KEY(person_id) REFERENCES cs_people(id),
            FOREIGN KEY(created_by) REFERENCES users(id),INDEX(person_id,state)
        ) ENGINE=InnoDB");
    }
    public function down(){throw new \RuntimeException('Shift change decisions are retained for audit.');}
}
