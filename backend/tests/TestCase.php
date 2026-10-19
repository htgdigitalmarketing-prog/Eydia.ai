<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase runs migrate:fresh. A cached config ignores phpunit.xml's
     * sqlite settings and would point that at the real dev database — refuse.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        if ($app->configurationIsCached() || $app['config']->get('database.default') !== 'sqlite') {
            throw new \RuntimeException(
                'Refusing to run tests against a non-sqlite database. Run "php artisan config:clear" first.'
            );
        }

        return $app;
    }
}
