<?php

declare(strict_types=1);

namespace App\Enums;

enum NewDatabaseTypes: string
{
    case POSTGRESQL = 'postgresql';
    case MYSQL = 'mysql';
    case MONGODB = 'mongodb';
    case REDIS = 'redis';
    case MARIADB = 'mariadb';
    case KEYDB = 'keydb';
    case DRAGONFLY = 'dragonfly';
    case CLICKHOUSE = 'clickhouse';
}
