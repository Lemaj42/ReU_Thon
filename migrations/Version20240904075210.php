<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240904075210 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE booking ADD date DATETIME NOT NULL, DROP answer, DROP chosen_date, DROP is_confirmed');
        $this->addSql('ALTER TABLE meeting DROP date, DROP alternative_date');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE booking ADD answer VARCHAR(255) NOT NULL, ADD chosen_date VARCHAR(255) DEFAULT NULL, ADD is_confirmed TINYINT(1) NOT NULL, DROP date');
        $this->addSql('ALTER TABLE meeting ADD date DATETIME NOT NULL, ADD alternative_date DATETIME DEFAULT NULL');
    }
}
