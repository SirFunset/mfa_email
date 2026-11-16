<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'E-Mail MFA Provider',
    'description' => 'Provides a multi-factor authentication via E-Mail for TYPO3',
    'category' => 'be',
    'author' => 'Ralf Freit, Benno Nijholt',
    'author_email' => 'ralf@freit.de, ik@bennonijholt.nl',
    'state' => 'stable',
    'clearCacheOnLoad' => true,
    'version' => '13.0.1',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
            'php' => '8.2.0-8.5.99',
        ],
        'conflicts' => [
        ],
        'suggests' => [
        ],
    ],
    'autoload' => [
        'psr-4' => [
            'Ralffreit\\MfaEmail\\' => 'Classes/',
        ],
    ],
];
