<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class InitialSchemaForeignKeyListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();

        if ($schema->hasTable('idempotency_record') && $schema->hasTable('app_user')) {
            $table = $schema->getTable('idempotency_record');

            if (!$table->hasForeignKey('fk_idempotency_record_user')) {
                $table->addForeignKeyConstraint(
                    'app_user',
                    ['user_id'],
                    ['id'],
                    ['onUpdate' => 'NO ACTION', 'onDelete' => 'RESTRICT'],
                    'fk_idempotency_record_user',
                );
            }
        }

        if ($schema->hasTable('email_delivery_outbox') && $schema->hasTable('user_action_token')) {
            $table = $schema->getTable('email_delivery_outbox');

            if (!$table->hasForeignKey('fk_email_delivery_outbox_token')) {
                $table->addForeignKeyConstraint(
                    'user_action_token',
                    ['user_action_token_id'],
                    ['id'],
                    ['onUpdate' => 'NO ACTION', 'onDelete' => 'RESTRICT'],
                    'fk_email_delivery_outbox_token',
                );
            }
        }
    }
}
