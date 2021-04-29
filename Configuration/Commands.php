<?php
return [
    'gcs:copy' => [
        'class' => \Visol\GoogleCloudStorage\Command\GcsCopyCommand::class,
    ],
    'gcs:move' => [
        'class' => \Visol\GoogleCloudStorage\Command\GcsMoveCommand::class,
    ],
    'gcs:tests' => [
        'class' => \Visol\GoogleCloudStorage\Command\GcsAcceptanceTestCommand::class,
    ],
    'gcs:scan' => [
        'class' => \Visol\GoogleCloudStorage\Command\GcsScanCommand::class,
    ],
    'gcs:query' => [
        'class' => \Visol\GoogleCloudStorage\Command\GcsQueryCommand::class,
    ],
];
