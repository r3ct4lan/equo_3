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

        foreach ([
            ['connect', 'first_user_id', 'fk_connect_first_user', 'app_user'],
            ['connect', 'second_user_id', 'fk_connect_second_user', 'app_user'],
            ['connect_invitation', 'created_by_id', 'fk_connect_invitation_author', 'app_user'],
            ['connect_invitation', 'accepted_by_id', 'fk_connect_invitation_acceptor', 'app_user'],
            ['debt', 'payer_id', 'fk_debt_payer', 'app_user'],
            ['debt', 'created_by_id', 'fk_debt_author', 'app_user'],
            ['debt_participant', 'debt_id', 'fk_debt_participant_debt', 'debt'],
            ['debt_participant', 'user_id', 'fk_debt_participant_user', 'app_user'],
            ['transfer', 'connect_id', 'fk_transfer_connect', 'connect'],
            ['transfer', 'sender_id', 'fk_transfer_sender', 'app_user'],
            ['transfer', 'receiver_id', 'fk_transfer_receiver', 'app_user'],
            ['transfer', 'created_by_id', 'fk_transfer_author', 'app_user'],
            ['transfer', 'debt_id', 'fk_transfer_debt', 'debt'],
            ['transfer', 'debt_participant_id', 'fk_transfer_participant', 'debt_participant'],
            ['transfer', 'related_transfer_id', 'fk_transfer_related', 'transfer'],
        ] as [$tableName, $column, $name, $target]) {
            if ($schema->hasTable($tableName) && $schema->hasTable($target)) {
                $table = $schema->getTable($tableName);
                if (!$table->hasForeignKey($name)) {
                    $table->addForeignKeyConstraint($target, [$column], ['id'], ['onUpdate' => 'NO ACTION', 'onDelete' => 'RESTRICT'], $name);
                }
            }
        }

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
