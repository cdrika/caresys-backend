<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class AgencyOptionalAddress extends Migration
{
    public function up(){ $this->db->query("ALTER TABLE cs_agencies ADD address_enabled TINYINT NOT NULL DEFAULT 0, ADD address_line1 VARCHAR(255) NOT NULL DEFAULT '', ADD address_line2 VARCHAR(255) NOT NULL DEFAULT '', ADD address_city VARCHAR(120) NOT NULL DEFAULT '', ADD address_state VARCHAR(120) NOT NULL DEFAULT '', ADD address_postal_code VARCHAR(32) NOT NULL DEFAULT ''"); }
    public function down(){throw new \RuntimeException('Address records require reviewed recovery.');}
}
