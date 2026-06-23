<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260623211541 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE flight (id VARCHAR(36) NOT NULL, flight_number VARCHAR(20) NOT NULL, origin VARCHAR(3) NOT NULL, destination VARCHAR(3) NOT NULL, departure_time DATETIME NOT NULL, arrival_time DATETIME NOT NULL, price NUMERIC(10, 2) NOT NULL, currency VARCHAR(3) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE seat (id VARCHAR(36) NOT NULL, seat_number VARCHAR(4) NOT NULL, class VARCHAR(10) NOT NULL, available TINYINT NOT NULL, flight_id VARCHAR(36) NOT NULL, INDEX IDX_3D5C366691F478C5 (flight_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE seat ADD CONSTRAINT FK_3D5C366691F478C5 FOREIGN KEY (flight_id) REFERENCES flight (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE seat DROP FOREIGN KEY FK_3D5C366691F478C5');
        $this->addSql('DROP TABLE flight');
        $this->addSql('DROP TABLE seat');
    }
}
