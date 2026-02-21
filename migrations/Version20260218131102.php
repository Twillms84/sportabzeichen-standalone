<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260218131102 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sportabzeichen_exams ADD examiner_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE sportabzeichen_exams ADD CONSTRAINT FK_FC8CC66C8A588563 FOREIGN KEY (examiner_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_FC8CC66C8A588563 ON sportabzeichen_exams (examiner_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sportabzeichen_exams DROP CONSTRAINT FK_FC8CC66C8A588563');
        $this->addSql('DROP INDEX IDX_FC8CC66C8A588563');
        $this->addSql('ALTER TABLE sportabzeichen_exams DROP examiner_id');
    }
}
