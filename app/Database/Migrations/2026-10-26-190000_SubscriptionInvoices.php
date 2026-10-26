<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class SubscriptionInvoices extends Migration
{
    public function up()
    {
        $this->db->query("CREATE TABLE cs_subscription_invoices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,ref CHAR(32) NOT NULL UNIQUE,
            version INT UNSIGNED NOT NULL DEFAULT 1,created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            agency_id BIGINT UNSIGNED NOT NULL,created_by INT UNSIGNED NOT NULL,
            invoice_no VARCHAR(40) NOT NULL UNIQUE,request_key CHAR(32) NOT NULL,request_hash CHAR(64) NOT NULL,
            state VARCHAR(24) NOT NULL DEFAULT 'issued',document_type VARCHAR(24) NOT NULL DEFAULT 'pro_forma',
            currency CHAR(3) NOT NULL,total_cents INT UNSIGNED NOT NULL,snapshot LONGTEXT NOT NULL,
            UNIQUE(agency_id,request_key),UNIQUE(agency_id,id),KEY ix_subscription_invoice_history(agency_id,created_at),
            FOREIGN KEY(agency_id) REFERENCES cs_agencies(id),FOREIGN KEY(created_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    public function down(){throw new \RuntimeException('Issued billing records require reviewed recovery.');}
}
