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
    }
];
