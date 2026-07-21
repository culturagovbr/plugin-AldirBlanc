<?php

use function MapasCulturais\__exec;
use function MapasCulturais\__table_exists;
use function MapasCulturais\__try;

return [
    'create federative_entity table' => function () {
        if (!__table_exists('federative_entity')) {
            __try("CREATE SEQUENCE federative_entity_id_seq INCREMENT BY 1 MINVALUE 1 START 1");

            __try("CREATE TABLE federative_entity (
                id INT NOT NULL DEFAULT nextval('federative_entity_id_seq'),
                name VARCHAR(255) NOT NULL,
                document VARCHAR(255) NOT NULL,
                create_timestamp timestamp NOT NULL,
                update_timestamp timestamp(0) NULL,
                subsite_id int4 NULL,
                PRIMARY KEY(id)
            )");
            __try("CREATE INDEX IDX_federative_entity_subsite_id ON federative_entity (subsite_id)");
            __try("ALTER TABLE federative_entity ADD CONSTRAINT FK_federative_entity_subsite FOREIGN KEY (subsite_id) REFERENCES subsite(id) ON DELETE CASCADE");
        }
    },

    'add FederativeEntity to object_type enum' => function () {
        __try("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_enum 
                    WHERE enumlabel = 'AldirBlanc\Entities\FederativeEntity' 
                    AND enumtypid = (SELECT oid FROM pg_type WHERE typname = 'object_type')
                ) THEN
                    ALTER TYPE object_type ADD VALUE 'AldirBlanc\Entities\FederativeEntity';
                END IF;
            END $$;
        ");
    },

    'add exercices column to federative_entity' => function () {
        if (__table_exists('federative_entity')) {
            __try("ALTER TABLE federative_entity ADD COLUMN exercices JSONB DEFAULT '{}'::jsonb NOT NULL");
        }
    },

    'Removendo metadado isNotGestorCultBr' => function () {
        __try("DELETE FROM agent_meta WHERE key = 'isNotGestorCultBr'");
    },

    'create cultbr_request_log table' => function () {
        if (!__table_exists('cultbr_request_log')) {
            __try("CREATE SEQUENCE cultbr_request_log_id_seq INCREMENT BY 1 MINVALUE 1 START 1");

            __try("CREATE TABLE cultbr_request_log (
                id INT NOT NULL DEFAULT nextval('cultbr_request_log_id_seq'),
                request_uuid VARCHAR(36) NOT NULL,
                opportunity_id INT NOT NULL,
                action VARCHAR(32) NOT NULL,
                status VARCHAR(16) NOT NULL,
                create_timestamp timestamp NOT NULL,
                update_timestamp timestamp(0) NULL,
                PRIMARY KEY(id)
            )");
            __try("CREATE UNIQUE INDEX UNIQ_cultbr_request_log_request_uuid ON cultbr_request_log (request_uuid)");
            __try("CREATE INDEX IDX_cultbr_request_log_opportunity_id ON cultbr_request_log (opportunity_id)");
            __try("CREATE INDEX IDX_cultbr_request_log_create_timestamp ON cultbr_request_log (create_timestamp)");
        }
    },

    'create cultbr_request_log_attempt table' => function () {
        if (!__table_exists('cultbr_request_log_attempt')) {
            __try("CREATE SEQUENCE cultbr_request_log_attempt_id_seq INCREMENT BY 1 MINVALUE 1 START 1");

            __try("CREATE TABLE cultbr_request_log_attempt (
                id INT NOT NULL DEFAULT nextval('cultbr_request_log_attempt_id_seq'),
                log_id INT NOT NULL,
                attempt INT NOT NULL,
                max_attempts INT NOT NULL,
                endpoint TEXT NOT NULL,
                http_method VARCHAR(8) NOT NULL,
                http_status INT NULL,
                payload JSONB NULL,
                response JSONB NULL,
                error_message TEXT NULL,
                status VARCHAR(16) NOT NULL,
                sent_at timestamp NOT NULL,
                duration_ms INT NULL,
                PRIMARY KEY(id)
            )");
            __try("CREATE INDEX IDX_cultbr_request_log_attempt_log_id ON cultbr_request_log_attempt (log_id)");
            __try("ALTER TABLE cultbr_request_log_attempt ADD CONSTRAINT FK_cultbr_request_log_attempt_log FOREIGN KEY (log_id) REFERENCES cultbr_request_log(id) ON DELETE CASCADE");
        }
    },

    'add response_headers column to cultbr_request_log_attempt' => function () {
        if (__table_exists('cultbr_request_log_attempt')) {
            __try("ALTER TABLE cultbr_request_log_attempt ADD COLUMN response_headers JSONB NULL");
        }
    },

    'add user_id column to cultbr_request_log' => function () {
        if (__table_exists('cultbr_request_log')) {
            // Nulo para envios sem autor (sync em lote, execuções por CLI).
            __try("ALTER TABLE cultbr_request_log ADD COLUMN user_id INT NULL");
            __try("CREATE INDEX IDX_cultbr_request_log_user_id ON cultbr_request_log (user_id)");
            __try("ALTER TABLE cultbr_request_log ADD CONSTRAINT FK_cultbr_request_log_user FOREIGN KEY (user_id) REFERENCES usr(id) ON DELETE SET NULL");
        }
    }
];
