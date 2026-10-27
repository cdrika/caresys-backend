<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class AgencyTrials extends Migration
{
    public function up()
    {
        $this->db->query("CREATE TABLE cs_billing_policy (id INT PRIMARY KEY,trial_days INT UNSIGNED NOT NULL DEFAULT 30,version INT UNSIGNED NOT NULL DEFAULT 1,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
        $this->db->table('cs_billing_policy')->insert(['id'=>1,'trial_days'=>30]);
        $this->db->query("CREATE TABLE cs_agency_subscriptions (
            agency_id BIGINT UNSIGNED PRIMARY KEY,state VARCHAR(24) NOT NULL DEFAULT 'awaiting_card',
            trial_days INT UNSIGNED NULL,trial_started_at DATETIME NULL,trial_ends_at DATETIME NULL,paid_until DATETIME NULL,
            stripe_customer VARCHAR(100) NULL,card_verified_at DATETIME NULL,card_brand VARCHAR(30) NULL,card_last4 CHAR(4) NULL,
            current_invoice_ref CHAR(32) NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(agency_id) REFERENCES cs_agencies(id)
        ) ENGINE=InnoDB");
        $this->db->query("CREATE TABLE cs_billing_checkouts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,ref CHAR(32) NOT NULL UNIQUE,version INT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            agency_id BIGINT UNSIGNED NOT NULL,created_by INT UNSIGNED NOT NULL,request_key CHAR(32) NOT NULL,
            purpose VARCHAR(16) NOT NULL,invoice_ref CHAR(32) NULL,stripe_session VARCHAR(150) NULL UNIQUE,
            state VARCHAR(16) NOT NULL DEFAULT 'pending',checkout_url TEXT NULL,paid_total_cents INT UNSIGNED NULL,
            UNIQUE(agency_id,request_key),FOREIGN KEY(agency_id) REFERENCES cs_agencies(id),FOREIGN KEY(created_by) REFERENCES users(id)
        ) ENGINE=InnoDB");
        // Preserve existing commercially active agreements; enroll every other agency.
        $this->db->query("INSERT INTO cs_agency_subscriptions (agency_id) SELECT id FROM cs_agencies WHERE subscription <> 'active'");
    }
    public function down(){throw new \RuntimeException('Billing records require reviewed recovery.');}
}
