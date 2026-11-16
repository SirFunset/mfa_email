<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'E-Mail MFA Provider',
    'description' => 'Provides a multi-factor authentication via E-Mail for TYPO3',
    'category' => 'be',
    'author' => 'Ralf Freit, Benno Nijholt',
    'author_email' => 'ralf@freit.de, ik@bennonijholt.nl',
    'state' => 'stable',
    'clearCacheOnLoad' => true,
    'version' => '12.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'php' => '8.1.0-8.5.99',
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
