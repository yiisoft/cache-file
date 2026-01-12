<?php

declare(strict_types=1);

return [
    'yiisoft/cache-file' => [
        'fileCache' => [
            'path' => '@runtime/cache',
            'directoryMode' => 0775,
            'fileSuffix' => '.bin',
            'fileMode' => null,
            'directoryLevel' => 1,
            'gcProbability' => 10,
        ],
    ],
];
