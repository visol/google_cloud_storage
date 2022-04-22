<?php

use Visol\GoogleCloudStorage\Command\GcsCopyCommand;
use Visol\GoogleCloudStorage\Command\GcsMoveCommand;
return [
    'gcs:copy' => [
        'class' => GcsCopyCommand::class,
    ],
    'gcs:move' => [
        'class' => GcsMoveCommand::class,
    ],
];
