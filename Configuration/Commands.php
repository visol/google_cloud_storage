<?php
return [
    'gcs:copy' => [
        'class' => \Visol\GoogleCloudStorage\Command\GcsCopyCommand::class,
    ],
    'gcs:move' => [
        'class' => \Visol\GoogleCloudStorage\Command\GcsMoveCommand::class,
    ],
];
