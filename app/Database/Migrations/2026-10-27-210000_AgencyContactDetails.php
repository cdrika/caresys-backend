<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class AgencyContactDetails extends Migration
{
    public function up()
    {
        $this->db->query("ALTER TABLE cs_agencies ADD official_email VARCHAR(254) NOT NULL DEFAULT '', ADD business_phone VARCHAR(24) NOT NULL DEFAULT '', ADD contact_number VARCHAR(24) NOT NULL DEFAULT '', ADD mobile_number VARCHAR(24) NOT NULL DEFAULT '', ADD business_registration_number VARCHAR(100) NOT NULL DEFAULT ''");
        $this->db->query('ALTER TABLE cs_contracts ADD agency_contact JSON NULL');
        $this->db->query('ALTER TABLE cs_invoices ADD agency_contact JSON NULL');
    }
    public function down(){throw new \RuntimeException('Business correspondence snapshots require reviewed recovery.');}
}
