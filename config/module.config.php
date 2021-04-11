<?php
/**
 * module.config.php - RPS Server Config
 *
 * Main Config File for Faucet RPS Server Module
 *
 * @category Config
 * @package Faucet\RPSServer
 * @author Verein onePlace
 * @copyright (C) 2021  Verein onePlace <admin@1plc.ch>
 * @license https://opensource.org/licenses/BSD-3-Clause
 * @version 1.0.0
 * @since 1.0.0
 */

namespace OnePlace\Faucet\RPSServer;

use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;
use Laminas\ServiceManager\Factory\InvokableFactory;

return [
    # Livechat Module - Routes
    'router' => [
        'routes' => [
        ],
    ],

    # View Settings
    'view_manager' => [
        'template_path_stack' => [
            'rpsserver' => __DIR__ . '/../view',
        ],
    ],

    # Translator
    'translator' => [
        'locale' => 'de_DE',
        'translation_file_patterns' => [
            [
                'type'     => 'gettext',
                'base_dir' => __DIR__ . '/../language',
                'pattern'  => '%s.mo',
            ],
        ],
    ],
];
