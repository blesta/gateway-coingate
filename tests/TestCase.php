<?php

namespace CoinGate;

class TestCase extends \PHPUnit_Framework_TestCase
{
    const AUTH_TOKEN  = '';
    const ENVIRONMENT = 'sandbox';

    public static function getGoodAuthentication()
    {
        return [
            'auth_token'  => self::AUTH_TOKEN,
            'environment' => self::ENVIRONMENT,
        ];
    }
}
